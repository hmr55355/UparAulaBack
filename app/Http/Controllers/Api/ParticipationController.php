<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupSubject;
use App\Models\Participation;
use App\Models\Period;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Services\AttendanceService;
use App\Services\GradeCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Participaciones registradas por el docente (quedan aprobadas de una vez; las
 * del monitor entran por MonitorReviewService al aprobarse). Cualquier cambio
 * recalcula la columna "desde participaciones" de todo el curso.
 */
class ParticipationController extends Controller
{
    public function __construct(
        private GradeCalculatorService $calculator,
        private AttendanceService $attendance,
    ) {}

    public function index(Request $request)
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

        $participations = Participation::where('group_subject_id', $groupSubject->id)
            ->whereDate('date', '>=', $period->start_date)
            ->whereDate('date', '<=', $period->end_date)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get(['id', 'student_id', 'date', 'points', 'notes', 'registered_by', 'monitor_submission_id']);

        return response()->json([
            'students' => $students,
            'totals' => (object) $participations->groupBy('student_id')->map->sum('points'),
            'participations' => $participations,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'group_subject_id' => ['required', 'integer', 'exists:group_subjects,id'],
            'student_id' => ['required', 'integer'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'points' => ['nullable', 'integer', 'min:1', 'max:20'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $groupSubject = GroupSubject::findOrFail($validated['group_subject_id']);
        $this->authorize('update', $groupSubject);
        abort_unless(
            StudentGroup::where('group_id', $groupSubject->group_id)->where('status', 'activo')->where('student_id', $validated['student_id'])->exists(),
            422,
            'El estudiante no pertenece a este curso.'
        );
        $period = $this->attendance->periodForDate($groupSubject, $validated['date']);
        abort_if($period?->is_closed, 422, 'El período está cerrado.');

        $participation = Participation::create([
            ...$validated,
            'points' => $validated['points'] ?? 1,
            'registered_by' => $request->user()->id,
        ]);

        if ($period) {
            $this->calculator->recalculateCourse($groupSubject->id, $period->id);
        }

        return response()->json(['data' => $participation], 201);
    }

    public function destroy(Participation $participation)
    {
        $groupSubject = $participation->groupSubject;
        $this->authorize('update', $groupSubject);
        $period = $this->attendance->periodForDate($groupSubject, $participation->date->toDateString());
        abort_if($period?->is_closed, 422, 'El período está cerrado.');

        $participation->delete();
        if ($period) {
            $this->calculator->recalculateCourse($groupSubject->id, $period->id);
        }

        return response()->json(['message' => 'Participación eliminada.']);
    }
}
