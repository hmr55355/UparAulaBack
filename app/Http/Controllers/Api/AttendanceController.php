<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

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

    public function bulk(Request $request, AttendanceService $attendance)
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

        $saved = $attendance->saveDay($groupSubject, $validated['date'], $validated['records'], $request->user()->id);

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

    /**
     * Planilla de asistencia de un período: todos los estudiantes del curso y una
     * columna por cada fecha con algún registro, como la planilla de notas.
     * `records` va indexado [student_id][fecha] para pintar la tabla directo.
     */
    public function sheet(Request $request)
    {
        $request->validate([
            'groupSubjectId' => ['required', 'integer', 'exists:group_subjects,id'],
            'periodId' => ['required', 'integer', 'exists:periods,id'],
        ]);

        $groupSubject = GroupSubject::findOrFail($request->groupSubjectId);
        $this->authorize('view', $groupSubject);
        $period = Period::findOrFail($request->periodId);

        $students = Student::whereHas(
            'studentGroups',
            fn ($q) => $q->where('group_id', $groupSubject->group_id)->where('status', 'activo')
        )->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        $records = AttendanceRecord::where('group_subject_id', $groupSubject->id)
            ->whereDate('date', '>=', $period->start_date)
            ->whereDate('date', '<=', $period->end_date)
            ->orderBy('date')
            ->get(['id', 'student_id', 'date', 'status', 'justification']);

        $byStudent = [];
        foreach ($records as $record) {
            $byStudent[$record->student_id][$record->date->toDateString()] = [
                'id' => $record->id,
                'status' => $record->status,
                'justification' => $record->justification,
            ];
        }

        return response()->json([
            'students' => $students,
            'dates' => $records->map(fn ($r) => $r->date->toDateString())->unique()->values(),
            'records' => (object) $byStudent,
        ]);
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
