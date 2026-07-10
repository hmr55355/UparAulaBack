<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Grades\BulkGradesRequest;
use App\Http\Requests\Grades\StoreGradeRequest;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeSection;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\PeriodFinal;
use App\Models\SectionFinal;
use App\Models\Student;
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
        ]);
    }

    public function store(StoreGradeRequest $request)
    {
        $column = GradeColumn::findOrFail($request->grade_column_id);
        $this->authorizeAndAssertEditable($column);
        $this->assertScoreWithinRange($request->score, $column);

        $grade = Grade::updateOrCreate(
            ['student_id' => $request->student_id, 'grade_column_id' => $column->id],
            [
                'group_subject_id' => $column->group_subject_id,
                'period_id' => $column->period_id,
                'score' => $request->score,
                'is_excused' => $request->boolean('is_excused'),
                'excused_reason' => $request->excused_reason,
                'notes' => $request->notes,
                'registered_by' => $request->user()->id,
            ]
        );

        return response()->json(['data' => $grade->fresh()], 201);
    }

    public function bulk(BulkGradesRequest $request)
    {
        $columnIds = collect($request->grades)->pluck('grade_column_id')->unique();
        $columns = GradeColumn::whereIn('id', $columnIds)->get()->keyBy('id');

        foreach ($columns as $column) {
            $this->authorizeAndAssertEditable($column);
        }

        $saved = [];
        foreach ($request->grades as $item) {
            $column = $columns->get($item['grade_column_id']);
            $this->assertScoreWithinRange($item['score'] ?? null, $column);

            $saved[] = Grade::updateOrCreate(
                ['student_id' => $item['student_id'], 'grade_column_id' => $column->id],
                [
                    'group_subject_id' => $column->group_subject_id,
                    'period_id' => $column->period_id,
                    'score' => $item['score'] ?? null,
                    'is_excused' => $item['is_excused'] ?? false,
                    'notes' => $item['notes'] ?? null,
                    'registered_by' => $request->user()->id,
                ]
            );
        }

        return response()->json(['data' => $saved, 'count' => count($saved)], 201);
    }

    public function update(StoreGradeRequest $request, Grade $grade)
    {
        $column = $grade->gradeColumn;
        $this->authorizeAndAssertEditable($column);
        $this->assertScoreWithinRange($request->score, $column);

        $grade->update([
            'score' => $request->score,
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

    public function adjustSectionFinal(Request $request, SectionFinal $sectionFinal)
    {
        $this->authorize('update', $sectionFinal->groupSubject);

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

    private function authorizeAndAssertEditable(GradeColumn $column): void
    {
        $this->authorize('update', $column->groupSubject);
        abort_if($column->period->is_closed, 422, 'El período está cerrado. Las notas no se pueden editar.');
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
