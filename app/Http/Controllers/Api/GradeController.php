<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Grades\BulkGradesRequest;
use App\Http\Requests\Grades\StoreGradeRequest;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeConvention;
use App\Models\GradeSection;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\PeriodFinal;
use App\Models\SectionFinal;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Services\GradeCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GradeController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'groupSubjectId' => ['required', 'integer', 'exists:group_subjects,id'],
            'periodId' => ['required', 'integer', 'exists:periods,id'],
        ]);

        $groupSubject = GroupSubject::findOrFail($request->groupSubjectId);
        $this->authorize('view', $groupSubject);

        $students = Student::whereHas(
            'studentGroups',
            fn ($q) => $q->where('group_id', $groupSubject->group_id)->where('status', 'activo')
        )->orderBy('last_name')->orderBy('first_name')->get();

        $sections = GradeSection::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $request->periodId)
            ->where('is_active', true)
            ->with(['columns' => fn ($q) => $q->where('is_visible', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $columnIds = $sections->flatMap->columns->pluck('id');

        $grades = Grade::whereIn('grade_column_id', $columnIds)
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->groupBy('student_id')
            ->map(fn ($studentGrades) => $studentGrades->keyBy('grade_column_id'));

        $sectionFinals = SectionFinal::whereIn('grade_section_id', $sections->pluck('id'))
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->keyBy('grade_section_id'));

        $periodFinals = PeriodFinal::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $request->periodId)
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy('student_id');

        return response()->json([
            'students' => $students,
            'sections' => $sections,
            'grades' => $grades,
            'section_finals' => $sectionFinals,
            'period_finals' => $periodFinals,
            'min_passing_grade' => (float) $groupSubject->institution->min_passing_grade,
            // Convenciones del docente del curso (no de quien abre la planilla).
            'conventions' => GradeConvention::where('user_id', $groupSubject->user_id)
                ->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(StoreGradeRequest $request)
    {
        $column = GradeColumn::findOrFail($request->grade_column_id);
        $this->authorizeAndAssertEditable($column);
        $convention = $this->resolveConvention($request->convention_id, $column);
        if (! $convention) {
            $this->assertScoreWithinRange($request->score, $column);
        }
        $this->assertStudentEnrolled($request->student_id, $column->groupSubject->group_id);

        $grade = Grade::updateOrCreate(
            ['student_id' => $request->student_id, 'grade_column_id' => $column->id],
            [
                'group_subject_id' => $column->group_subject_id,
                'period_id' => $column->period_id,
                'score' => $convention ? $convention->scoreFor($column) : $request->score,
                'convention_id' => $convention?->id,
                'is_excused' => $request->boolean('is_excused'),
                'excused_reason' => $request->excused_reason,
                'notes' => $request->notes,
                'registered_by' => $request->user()->id,
            ]
        );

        return response()->json(['data' => $grade->fresh()], 201);
    }

    public function bulk(BulkGradesRequest $request, GradeCalculatorService $calculator)
    {
        $columnIds = collect($request->grades)->pluck('grade_column_id')->unique();
        $columns = GradeColumn::with('groupSubject')->whereIn('id', $columnIds)->get()->keyBy('id');

        foreach ($columns as $column) {
            $this->authorizeAndAssertEditable($column);
        }

        // Pre-carga de matrícula por grupo en una sola consulta — evita 1 query por
        // fila para validar que cada estudiante realmente pertenezca al grupo del
        // group_subject de su columna.
        $groupIds = $columns->pluck('groupSubject.group_id')->filter()->unique();
        $enrolledByGroup = StudentGroup::whereIn('group_id', $groupIds)
            ->where('status', 'activo')
            ->get(['student_id', 'group_id'])
            ->groupBy('group_id')
            ->map(fn ($rows) => $rows->pluck('student_id')->all());

        $conventions = GradeConvention::whereIn('id', collect($request->grades)->pluck('convention_id')->filter()->unique())
            ->get()->keyBy('id');

        $saved = [];
        $affected = collect();

        // withoutEvents: escribimos todo primero sin disparar GradeObserver, y
        // recalculamos una sola vez por (estudiante, group_subject, período) único
        // al final — evita cascadas de recálculo repetidas si el mismo estudiante
        // aparece en más de una fila (varias columnas guardadas de una vez).
        Grade::withoutEvents(function () use ($request, $columns, $conventions, $enrolledByGroup, &$saved, &$affected) {
            foreach ($request->grades as $item) {
                $column = $columns->get($item['grade_column_id']);
                $convention = $this->resolveConvention($item['convention_id'] ?? null, $column, $conventions);
                if (! $convention) {
                    $this->assertScoreWithinRange($item['score'] ?? null, $column);
                }

                $groupId = $column->groupSubject->group_id;
                abort_if(
                    ! in_array($item['student_id'], $enrolledByGroup->get($groupId, []), true),
                    422,
                    'Uno de los estudiantes no está matriculado en este grupo.'
                );

                $saved[] = Grade::updateOrCreate(
                    ['student_id' => $item['student_id'], 'grade_column_id' => $column->id],
                    [
                        'group_subject_id' => $column->group_subject_id,
                        'period_id' => $column->period_id,
                        'score' => $convention ? $convention->scoreFor($column) : ($item['score'] ?? null),
                        'convention_id' => $convention?->id,
                        'is_excused' => $item['is_excused'] ?? false,
                        'notes' => $item['notes'] ?? null,
                        'registered_by' => $request->user()->id,
                    ]
                );

                $affected->put(
                    "{$item['student_id']}:{$column->group_subject_id}:{$column->period_id}",
                    [$item['student_id'], $column->group_subject_id, $column->period_id]
                );
            }
        });

        foreach ($affected as [$studentId, $groupSubjectId, $periodId]) {
            $calculator->recalculateForStudent($studentId, $groupSubjectId, $periodId);
        }

        return response()->json(['data' => $saved, 'count' => count($saved)], 201);
    }

    public function update(StoreGradeRequest $request, Grade $grade)
    {
        $column = $grade->gradeColumn;
        $this->authorizeAndAssertEditable($column);
        $convention = $this->resolveConvention($request->convention_id, $column);
        if (! $convention) {
            $this->assertScoreWithinRange($request->score, $column);
        }

        $grade->update([
            'score' => $convention ? $convention->scoreFor($column) : $request->score,
            'convention_id' => $convention?->id,
            'is_excused' => $request->boolean('is_excused'),
            'excused_reason' => $request->excused_reason,
            'notes' => $request->notes,
            'registered_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $grade->fresh()]);
    }

    public function studentGrades(Request $request, int $studentId)
    {
        $request->validate([
            'groupSubjectId' => ['required', 'integer', 'exists:group_subjects,id'],
            'periodId' => ['required', 'integer', 'exists:periods,id'],
        ]);

        $groupSubject = GroupSubject::findOrFail($request->groupSubjectId);
        $this->authorize('view', $groupSubject);

        $grades = Grade::where('student_id', $studentId)
            ->where('group_subject_id', $groupSubject->id)
            ->where('period_id', $request->periodId)
            ->with('gradeColumn')
            ->get();

        return response()->json(['data' => $grades]);
    }

    public function sectionFinals(Request $request)
    {
        [$groupSubject] = $this->resolveGroupSubjectAndPeriod($request);

        $sectionIds = GradeSection::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $request->periodId)
            ->pluck('id');

        return response()->json(['data' => SectionFinal::whereIn('grade_section_id', $sectionIds)->get()]);
    }

    public function calculateSectionFinals(Request $request, GradeCalculatorService $calculator)
    {
        [$groupSubject, $period] = $this->resolveGroupSubjectAndPeriod($request);
        $this->authorize('update', $groupSubject);

        $this->recalculateAllStudents($groupSubject, $period, $calculator);

        return response()->json(['message' => 'Definitivas de sección recalculadas.']);
    }

    public function adjustSectionFinal(Request $request, SectionFinal $sectionFinal, GradeCalculatorService $calculator)
    {
        $this->authorize('update', $sectionFinal->groupSubject);
        $this->assertPeriodIsOpen($sectionFinal->period_id);

        $validated = $request->validate([
            'section_final' => ['required', 'numeric', 'min:1'],
            'adjustment_reason' => ['required', 'string'],
        ]);

        $sectionFinal->update([
            'section_final' => $validated['section_final'],
            'manually_adjusted' => true,
            'adjustment_reason' => $validated['adjustment_reason'],
            'calculated_at' => now(),
        ]);

        // La Def Total se recalcula con el valor ajustado (salvo que también esté ajustada a mano).
        $calculator->recalculatePeriodFinal($sectionFinal->student_id, $sectionFinal->group_subject_id, $sectionFinal->period_id);

        return response()->json(['data' => $sectionFinal]);
    }

    public function periodFinals(Request $request)
    {
        [$groupSubject] = $this->resolveGroupSubjectAndPeriod($request);

        return response()->json(['data' => PeriodFinal::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $request->periodId)
            ->get()]);
    }

    public function calculatePeriodFinals(Request $request, GradeCalculatorService $calculator)
    {
        [$groupSubject, $period] = $this->resolveGroupSubjectAndPeriod($request);
        $this->authorize('update', $groupSubject);

        $this->recalculateAllStudents($groupSubject, $period, $calculator);

        return response()->json(['message' => 'Def Total recalculado.']);
    }

    public function adjustPeriodFinal(Request $request, PeriodFinal $periodFinal)
    {
        $this->authorize('update', $periodFinal->groupSubject);
        $this->assertPeriodIsOpen($periodFinal->period_id);

        $validated = $request->validate([
            'period_final' => ['required', 'numeric', 'min:1'],
            'adjustment_reason' => ['required', 'string'],
        ]);

        $periodFinal->update([
            'period_final' => $validated['period_final'],
            'manually_adjusted' => true,
            'adjustment_reason' => $validated['adjustment_reason'],
            'calculated_at' => now(),
        ]);

        return response()->json(['data' => $periodFinal]);
    }

    /** Quita el ajuste manual: la definitiva vuelve al valor calculado (y la Def Total se recalcula). */
    public function clearSectionAdjustment(SectionFinal $sectionFinal, GradeCalculatorService $calculator)
    {
        $this->authorize('update', $sectionFinal->groupSubject);
        $this->assertPeriodIsOpen($sectionFinal->period_id);

        $sectionFinal->update(['manually_adjusted' => false, 'adjustment_reason' => null]);
        $fresh = $calculator->recalculateSectionFinal($sectionFinal->student_id, $sectionFinal->grade_section_id);
        $calculator->recalculatePeriodFinal($sectionFinal->student_id, $sectionFinal->group_subject_id, $sectionFinal->period_id);

        return response()->json(['data' => $fresh]);
    }

    public function clearPeriodAdjustment(PeriodFinal $periodFinal, GradeCalculatorService $calculator)
    {
        $this->authorize('update', $periodFinal->groupSubject);
        $this->assertPeriodIsOpen($periodFinal->period_id);

        $periodFinal->update(['manually_adjusted' => false, 'adjustment_reason' => null]);
        $fresh = $calculator->recalculatePeriodFinal($periodFinal->student_id, $periodFinal->group_subject_id, $periodFinal->period_id);

        return response()->json(['data' => $fresh]);
    }

    private function assertPeriodIsOpen(int $periodId): void
    {
        abort_if(Period::whereKey($periodId)->value('is_closed'), 422, 'El período está cerrado. Las notas no se pueden editar.');
    }

    private function authorizeAndAssertEditable(GradeColumn $column): void
    {
        $this->authorize('update', $column->groupSubject);
        abort_if($column->period->is_closed, 422, 'El período está cerrado. Las notas no se pueden editar.');
    }

    /**
     * La convención debe ser del docente del curso (la planilla solo le muestra
     * esas). Solo aplica a columnas manuales: las calculadas no se digitan.
     */
    private function resolveConvention(?int $conventionId, GradeColumn $column, $preloaded = null): ?GradeConvention
    {
        if (! $conventionId) {
            return null;
        }

        $convention = $preloaded?->get($conventionId) ?? GradeConvention::find($conventionId);
        abort_if(
            ! $convention || $convention->user_id !== $column->groupSubject->user_id,
            422,
            'Esa convención no pertenece al docente de este curso.'
        );

        return $convention;
    }

    private function assertStudentEnrolled(int $studentId, int $groupId): void
    {
        $student = Student::find($studentId);
        abort_if(
            ! $student || ! $student->isEnrolledInGroup($groupId),
            422,
            'El estudiante no está matriculado en este grupo.'
        );
    }

    private function assertScoreWithinRange(?float $score, GradeColumn $column): void
    {
        if ($score === null) {
            return;
        }

        if ($score < 1.0 || $score > (float) $column->max_score) {
            throw ValidationException::withMessages([
                'score' => ["La nota debe estar entre 1.0 y {$column->max_score}."],
            ]);
        }
    }

    /**
     * @return array{0: GroupSubject, 1: Period}
     */
    private function resolveGroupSubjectAndPeriod(Request $request): array
    {
        $request->validate([
            'groupSubjectId' => ['required', 'integer', 'exists:group_subjects,id'],
            'periodId' => ['required', 'integer', 'exists:periods,id'],
        ]);

        $groupSubject = GroupSubject::findOrFail($request->groupSubjectId);
        $this->authorize('view', $groupSubject);
        $period = Period::findOrFail($request->periodId);

        return [$groupSubject, $period];
    }

    private function recalculateAllStudents(GroupSubject $groupSubject, Period $period, GradeCalculatorService $calculator): void
    {
        $studentIds = Student::whereHas(
            'studentGroups',
            fn ($q) => $q->where('group_id', $groupSubject->group_id)->where('status', 'activo')
        )->pluck('id');

        foreach ($studentIds as $studentId) {
            $calculator->recalculateForStudent($studentId, $groupSubject->id, $period->id);
        }
    }
}
