<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeSection;
use App\Models\GradeTemplate;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\PeriodFinal;
use App\Models\SectionFinal;
use Illuminate\Support\Facades\DB;

/**
 * Centralizes every calculation described in "LÓGICA DE CÁLCULO DE DEFINITIVAS":
 * from_attendance / custom_formula columns -> section finals -> period final (Def Total).
 */
class GradeCalculatorService
{
    /**
     * Recalculates everything for one student within a group-subject + period:
     * computed columns, all section finals, and the period final (Def Total).
     */
    public function recalculateForStudent(int $studentId, int $groupSubjectId, int $periodId): void
    {
        DB::transaction(function () use ($studentId, $groupSubjectId, $periodId) {
            // Cargado una sola vez y reutilizado: evita que upsertGrade() (por columna) y
            // recalculatePeriodFinal() re-consulten el mismo GroupSubject/institution por
            // separado — antes eran N+2 queries redundantes por cada llamada a este método.
            $groupSubject = GroupSubject::with('institution')->find($groupSubjectId);

            $sections = GradeSection::query()
                ->where('group_subject_id', $groupSubjectId)
                ->where('period_id', $periodId)
                ->where('is_active', true)
                ->with(['columns' => fn ($q) => $q->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->get();

            foreach ($sections as $section) {
                foreach ($section->columns as $column) {
                    if ($groupSubject) {
                        $column->setRelation('groupSubject', $groupSubject);
                    }
                    if ($column->column_type === 'from_attendance') {
                        $this->calculateAttendanceColumn($studentId, $column);
                    }
                }
            }

            // custom_formula columns are calculated after from_attendance/manual so that
            // any short_name they reference is already resolved.
            foreach ($sections as $section) {
                foreach ($section->columns as $column) {
                    if ($column->column_type === 'custom_formula') {
                        $this->calculateFormulaColumn($studentId, $column, $section);
                    }
                }
            }

            foreach ($sections as $section) {
                $this->recalculateSectionFinal($studentId, $section->id);
            }

            $this->recalculatePeriodFinal($studentId, $groupSubjectId, $periodId, $groupSubject);
        });
    }

    /**
     * Paso 1 — from_attendance: nota_base - (faltas_injustificadas * penalización)
     * - (faltas_justificadas * penalización_justificada), nunca menor a 1.0.
     */
    public function calculateAttendanceColumn(int $studentId, GradeColumn $column): ?Grade
    {
        $period = Period::find($column->period_id);
        if (! $period) {
            return null;
        }

        $counts = AttendanceRecord::query()
            ->where('student_id', $studentId)
            ->where('group_subject_id', $column->group_subject_id)
            ->whereBetween('date', [$period->start_date, $period->end_date])
            ->selectRaw("
                SUM(CASE WHEN status = 'ausente_injustificado' THEN 1 ELSE 0 END) as injustificadas,
                SUM(CASE WHEN status = 'ausente_justificado' THEN 1 ELSE 0 END) as justificadas
            ")
            ->first();

        $injustificadas = (int) ($counts->injustificadas ?? 0);
        $justificadas = (int) ($counts->justificadas ?? 0);

        $score = (float) $column->attendance_base_score
            - ($injustificadas * (float) $column->absence_penalty)
            - ($justificadas * (float) $column->justified_absence_penalty);

        $score = max(1.0, $score);
        $score = min((float) $column->max_score, $score);

        return $this->upsertGrade($studentId, $column, round($score, 1));
    }

    /**
     * Paso 2 — custom_formula: evalúa la fórmula sustituyendo cada short_name
     * por la nota ya calculada de esa columna. Si alguna referencia es null, el
     * resultado también es null.
     */
    public function calculateFormulaColumn(int $studentId, GradeColumn $column, ?GradeSection $section = null): ?Grade
    {
        if (empty($column->formula)) {
            return $this->upsertGrade($studentId, $column, null);
        }

        $section ??= GradeSection::with('columns')->find($column->grade_section_id);

        $values = [];
        foreach ($section->columns as $sibling) {
            if (! $sibling->short_name) {
                continue;
            }
            $grade = Grade::where('student_id', $studentId)
                ->where('grade_column_id', $sibling->id)
                ->first();
            $values[$sibling->short_name] = $grade?->score !== null ? (float) $grade->score : null;
        }

        $result = FormulaEvaluator::evaluate($column->formula, $values);

        if ($result !== null) {
            $result = round(max(1.0, min((float) $column->max_score, $result)), 1);
        }

        return $this->upsertGrade($studentId, $column, $result);
    }

    /**
     * Paso 3 — section_final: weighted_avg (ponderado, ignorando notas null y
     * ajustando pesos proporcionalmente), simple_avg, o manual (no se toca).
     */
    public function recalculateSectionFinal(int $studentId, int $gradeSectionId): ?SectionFinal
    {
        $section = GradeSection::with('columns')->findOrFail($gradeSectionId);

        if (! $section->has_section_final) {
            return null;
        }

        if ($section->final_calculation === 'manual') {
            return SectionFinal::firstOrCreate(
                ['student_id' => $studentId, 'grade_section_id' => $gradeSectionId],
                ['group_subject_id' => $section->group_subject_id, 'period_id' => $section->period_id]
            );
        }

        $grades = Grade::where('student_id', $studentId)
            ->whereIn('grade_column_id', $section->columns->pluck('id'))
            ->get()
            ->keyBy('grade_column_id');

        $scored = $section->columns->filter(function ($column) use ($grades) {
            $grade = $grades->get($column->id);

            return $grade?->score !== null && ! $grade->is_excused;
        });

        $sectionFinal = null;

        if ($scored->isNotEmpty()) {
            if ($section->final_calculation === 'weighted_avg') {
                $totalWeight = (float) $scored->sum('weight');
                if ($totalWeight > 0) {
                    $weightedSum = $scored->sum(
                        fn ($column) => (float) $grades->get($column->id)->score * (float) $column->weight
                    );
                    $sectionFinal = $weightedSum / $totalWeight;
                }
            } else { // simple_avg
                $sectionFinal = $scored->avg(fn ($column) => (float) $grades->get($column->id)->score);
            }
        }

        if ($sectionFinal !== null) {
            $sectionFinal = max(1.0, min(10.0, $sectionFinal));
        }

        return SectionFinal::updateOrCreate(
            ['student_id' => $studentId, 'grade_section_id' => $gradeSectionId],
            [
                'group_subject_id' => $section->group_subject_id,
                'period_id' => $section->period_id,
                'section_final' => $sectionFinal !== null ? round($sectionFinal, 1) : null,
                'calculated_at' => now(),
            ]
        );
    }

    /**
     * Paso 4 — period_final (Def Total): suma ponderada de las section_finals
     * activas no nulas, ajustando pesos proporcionalmente si falta alguna.
     */
    public function recalculatePeriodFinal(int $studentId, int $groupSubjectId, int $periodId, ?GroupSubject $groupSubject = null): ?PeriodFinal
    {
        $sections = GradeSection::where('group_subject_id', $groupSubjectId)
            ->where('period_id', $periodId)
            ->where('is_active', true)
            ->get();

        $finals = SectionFinal::where('student_id', $studentId)
            ->whereIn('grade_section_id', $sections->pluck('id'))
            ->get()
            ->keyBy('grade_section_id');

        $scored = $sections->filter(fn ($section) => $finals->get($section->id)?->section_final !== null);

        $periodFinal = null;

        if ($scored->isNotEmpty()) {
            $totalWeight = (float) $scored->sum('weight');
            if ($totalWeight > 0) {
                $weightedSum = $scored->sum(
                    fn ($section) => (float) $finals->get($section->id)->section_final * (float) $section->weight
                );
                $periodFinal = $weightedSum / $totalWeight;
                $periodFinal = max(1.0, min(10.0, $periodFinal));
            }
        }

        $groupSubject ??= GroupSubject::with('institution')->find($groupSubjectId);
        $minPassing = optional($groupSubject?->institution)->min_passing_grade ?? 6.0;

        return PeriodFinal::updateOrCreate(
            ['student_id' => $studentId, 'group_subject_id' => $groupSubjectId, 'period_id' => $periodId],
            [
                'period_final' => $periodFinal !== null ? round($periodFinal, 1) : null,
                'is_promoted' => $periodFinal !== null ? $periodFinal >= (float) $minPassing : null,
                'calculated_at' => now(),
            ]
        );
    }

    /**
     * The inverse of applyTemplate(): reads the real grade_sections/grade_columns of a
     * group_subject + period and serializes them into the sections_config array shape,
     * so a live sheet can be saved as a template or copied into another period.
     */
    public function buildSectionsConfig(GroupSubject $groupSubject, Period $period): array
    {
        $sections = GradeSection::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $period->id)
            ->where('is_active', true)
            ->with(['columns' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return [
            'sections' => $sections->map(fn ($section) => [
                'name' => $section->name,
                'short_name' => $section->short_name,
                'weight' => (float) $section->weight,
                'color' => $section->color,
                'has_section_final' => $section->has_section_final,
                'final_calculation' => $section->final_calculation,
                'columns' => $section->columns->map(fn ($column) => [
                    'name' => $column->name,
                    'short_name' => $column->short_name,
                    'column_type' => $column->column_type,
                    'weight' => (float) $column->weight,
                    'attendance_base_score' => (float) $column->attendance_base_score,
                    'absence_penalty' => (float) $column->absence_penalty,
                    'justified_absence_penalty' => (float) $column->justified_absence_penalty,
                    'formula' => $column->formula,
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * Materializes a grade_template's sections_config JSON into real grade_sections
     * and grade_columns rows for a given group_subject + period. Thin wrapper around
     * applySectionsConfig() for callers that already have a persisted GradeTemplate.
     *
     * @return GradeSection[] created sections (each with its columns loaded)
     */
    public function applyTemplate(GradeTemplate $template, GroupSubject $groupSubject, Period $period): array
    {
        return $this->applySectionsConfig($template->sections_config, $groupSubject, $period);
    }

    /**
     * Materializes a sections_config array (same shape as grade_templates.sections_config)
     * into real grade_sections/grade_columns rows. Shared by the demo seeder, "apply
     * template", and "copy from period" (which builds the array via buildSectionsConfig()
     * instead of loading a stored template).
     *
     * @return GradeSection[] created sections (each with its columns loaded)
     */
    public function applySectionsConfig(array $sectionsConfig, GroupSubject $groupSubject, Period $period): array
    {
        return DB::transaction(function () use ($sectionsConfig, $groupSubject, $period) {
            $sections = [];

            foreach ($sectionsConfig['sections'] as $sectionIndex => $sectionData) {
                $section = GradeSection::create([
                    'group_subject_id' => $groupSubject->id,
                    'period_id' => $period->id,
                    'name' => $sectionData['name'],
                    'short_name' => $sectionData['short_name'] ?? null,
                    'weight' => $sectionData['weight'],
                    'color' => $sectionData['color'] ?? '#1565C0',
                    'has_section_final' => $sectionData['has_section_final'] ?? true,
                    'final_calculation' => $sectionData['final_calculation'] ?? 'weighted_avg',
                    'sort_order' => $sectionIndex,
                ]);

                foreach ($sectionData['columns'] as $columnIndex => $columnData) {
                    GradeColumn::create([
                        'grade_section_id' => $section->id,
                        'group_subject_id' => $groupSubject->id,
                        'period_id' => $period->id,
                        'column_type' => $columnData['column_type'],
                        'name' => $columnData['name'],
                        'short_name' => $columnData['short_name'] ?? null,
                        'weight' => $columnData['weight'],
                        'attendance_base_score' => $columnData['attendance_base_score'] ?? 10.0,
                        'absence_penalty' => $columnData['absence_penalty'] ?? 0.5,
                        'justified_absence_penalty' => $columnData['justified_absence_penalty'] ?? 0.1,
                        'formula' => $columnData['formula'] ?? null,
                        'sort_order' => $columnIndex,
                    ]);
                }

                $sections[] = $section->load('columns');
            }

            return $sections;
        });
    }

    private function upsertGrade(int $studentId, GradeColumn $column, ?float $score): Grade
    {
        // Computed columns (from_attendance / custom_formula) are attributed to the
        // course's teacher since no manual entry by a specific user takes place.
        $registeredBy = $column->groupSubject?->user_id ?? auth()->id();

        // withoutEvents: this service already performs the full recalculation cascade
        // (columns -> section finals -> period final) in recalculateForStudent(), so
        // writing a computed grade must not re-trigger GradeObserver and recurse.
        return Grade::withoutEvents(fn () => Grade::updateOrCreate(
            ['student_id' => $studentId, 'grade_column_id' => $column->id],
            [
                'group_subject_id' => $column->group_subject_id,
                'period_id' => $column->period_id,
                'score' => $score,
                'registered_by' => $registeredBy,
            ]
        ));
    }
}
