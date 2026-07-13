<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\Student;
use App\Services\GradeCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'groupSubjectId' => ['required', 'integer', 'exists:group_subjects,id'],
            'date' => ['required_without_all:from,to', 'date'],
            'from' => ['required_without:date', 'date'],
            'to' => ['required_with:from', 'date', 'after_or_equal:from'],
        ]);

        $groupSubject = GroupSubject::findOrFail($request->groupSubjectId);
        $this->authorize('view', $groupSubject);

        if ($request->filled('date')) {
            return $this->dayView($groupSubject, $request->date);
        }

        $records = AttendanceRecord::where('group_subject_id', $groupSubject->id)
            ->whereDate('date', '>=', $request->from)
            ->whereDate('date', '<=', $request->to)
            ->with('student:id,first_name,last_name')
            ->orderBy('date')
            ->get();

        return response()->json(['data' => $records]);
    }

    private function dayView(GroupSubject $groupSubject, string $date)
    {
        $students = Student::whereHas(
            'studentGroups',
            fn ($q) => $q->where('group_id', $groupSubject->group_id)->where('status', 'activo')
        )->orderBy('last_name')->orderBy('first_name')->get();

        $records = AttendanceRecord::where('group_subject_id', $groupSubject->id)
            ->whereDate('date', $date)
            ->get()
            ->keyBy('student_id');

        return response()->json([
            'students' => $students,
            'records' => $records,
        ]);
    }

    public function bulk(Request $request, GradeCalculatorService $calculator)
    {
        $validated = $request->validate([
            'group_subject_id' => ['required', 'integer', 'exists:group_subjects,id'],
            'date' => ['required', 'date'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'records.*.status' => ['required', 'in:presente,ausente_injustificado,ausente_justificado,tarde,retirado_temprano'],
            'records.*.justification' => ['nullable', 'string'],
        ]);

        $groupSubject = GroupSubject::findOrFail($validated['group_subject_id']);
        $this->authorize('update', $groupSubject);
        $this->assertPeriodOpenForDate($groupSubject, $validated['date']);

        // withoutEvents: guardamos todo el pase de lista sin disparar AttendanceObserver
        // por cada fila (antes cada create()/update() lanzaba la cascada completa de
        // recálculo de notas, entrelazada con las escrituras) — recalculamos una sola
        // vez por estudiante después, ya con todo guardado.
        $saved = DB::transaction(function () use ($validated, $request) {
            return AttendanceRecord::withoutEvents(function () use ($validated, $request) {
                // Not updateOrCreate(): its match query compares the raw 'date' string
                // against the stored value, which Eloquent's `date` cast persists with a
                // "00:00:00" time suffix — an exact-string match would never find the
                // existing row and would attempt a duplicate insert on every re-save of
                // the same day. whereDate() correctly ignores that suffix.
                return collect($validated['records'])->map(function (array $record) use ($validated, $request) {
                    $existing = AttendanceRecord::where('student_id', $record['student_id'])
                        ->where('group_subject_id', $validated['group_subject_id'])
                        ->whereDate('date', $validated['date'])
                        ->first();

                    $attributes = [
                        'status' => $record['status'],
                        'justification' => $record['justification'] ?? null,
                        'registered_by' => $request->user()->id,
                    ];

                    if ($existing) {
                        $existing->update($attributes);

                        return $existing;
                    }

                    return AttendanceRecord::create([
                        'student_id' => $record['student_id'],
                        'group_subject_id' => $validated['group_subject_id'],
                        'date' => $validated['date'],
                        ...$attributes,
                    ]);
                });
            });
        });

        $period = Period::where('academic_year_id', $groupSubject->academic_year_id)
            ->whereDate('start_date', '<=', $validated['date'])
            ->whereDate('end_date', '>=', $validated['date'])
            ->first();

        if ($period) {
            foreach ($saved->pluck('student_id')->unique() as $studentId) {
                $calculator->recalculateForStudent($studentId, $groupSubject->id, $period->id);
            }
        }

        return response()->json(['data' => $saved, 'count' => $saved->count()], 201);
    }

    public function update(Request $request, AttendanceRecord $attendanceRecord)
    {
        $this->authorize('update', $attendanceRecord->groupSubject);
        $this->assertPeriodOpenForDate($attendanceRecord->groupSubject, (string) $attendanceRecord->date);

        $validated = $request->validate([
            'status' => ['required', 'in:presente,ausente_injustificado,ausente_justificado,tarde,retirado_temprano'],
            'justification' => ['nullable', 'string'],
        ]);

        $attendanceRecord->update([
            ...$validated,
            'registered_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $attendanceRecord->fresh()]);
    }

    public function stats(Request $request)
    {
        $request->validate([
            'groupSubjectId' => ['required', 'integer', 'exists:group_subjects,id'],
            'periodId' => ['required', 'integer', 'exists:periods,id'],
        ]);

        $groupSubject = GroupSubject::findOrFail($request->groupSubjectId);
        $this->authorize('view', $groupSubject);
        $period = Period::findOrFail($request->periodId);

        $rows = AttendanceRecord::where('group_subject_id', $groupSubject->id)
            ->whereBetween('date', [$period->start_date, $period->end_date])
            ->with('student:id,first_name,last_name')
            ->get()
            ->groupBy('student_id')
            ->map(function ($records) {
                $student = $records->first()->student;

                return [
                    'student_id' => $student->id,
                    'student_name' => "{$student->last_name} {$student->first_name}",
                    'ausente_injustificado' => $records->where('status', 'ausente_injustificado')->count(),
                    'ausente_justificado' => $records->where('status', 'ausente_justificado')->count(),
                    'tarde' => $records->where('status', 'tarde')->count(),
                ];
            })
            ->sortByDesc(fn ($row) => $row['ausente_injustificado'] + $row['ausente_justificado'])
            ->values();

        return response()->json(['data' => $rows]);
    }

    private function assertPeriodOpenForDate(GroupSubject $groupSubject, string $date): void
    {
        $period = Period::where('academic_year_id', $groupSubject->academic_year_id)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        abort_if($period?->is_closed, 422, 'El período está cerrado y no admite cambios en la asistencia.');
    }
}
