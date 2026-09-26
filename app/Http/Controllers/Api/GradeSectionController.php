<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Grades\BulkSaveGradeSheetRequest;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeSection;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\StudentGroup;
use App\Services\GradeCalculatorService;
use App\Services\PerformanceScale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GradeSectionController extends Controller
{
    /** Valores por defecto de los campos opcionales al crear una sección. */
    private const SECTION_DEFAULTS = [
        'short_name' => null,
        'color' => '#1565C0',
        'has_section_final' => true,
        'section_final_label' => 'Def',
        'final_calculation' => 'weighted_avg',
    ];

    /** Valores por defecto de los campos opcionales al crear una columna. */
    private const COLUMN_DEFAULTS = [
        'short_name' => null,
        'description' => null,
        'max_score' => 10.0,
        'date' => null,
        'attendance_base_score' => 10.0,
        'absence_penalty' => 0.5,
        'justified_absence_penalty' => 0.1,
        'formula' => null,
    ];

    public function index(Request $request)
    {
        $request->validate([
            'groupSubjectId' => ['required', 'integer', 'exists:group_subjects,id'],
            'periodId' => ['required', 'integer', 'exists:periods,id'],
        ]);

        $groupSubject = GroupSubject::findOrFail($request->groupSubjectId);
        $this->authorize('view', $groupSubject);

        $sections = GradeSection::where('group_subject_id', $request->groupSubjectId)
            ->where('period_id', $request->periodId)
            ->where('is_active', true)
            ->with('columns')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $sections]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'group_subject_id' => ['required', 'integer', 'exists:group_subjects,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:12'],
            'weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'color' => ['sometimes', 'string', 'max:20'],
            'has_section_final' => ['sometimes', 'boolean'],
            'final_calculation' => ['sometimes', 'in:weighted_avg,simple_avg,manual'],
        ]);

        $groupSubject = GroupSubject::findOrFail($validated['group_subject_id']);
        $this->authorize('update', $groupSubject);
        $this->assertPeriodOpen($validated['period_id']);

        $validated['sort_order'] = GradeSection::where('group_subject_id', $validated['group_subject_id'])
            ->where('period_id', $validated['period_id'])
            ->count();

        $section = GradeSection::create($validated);

        return response()->json(['data' => $section->load('columns')], 201);
    }

    public function update(Request $request, GradeSection $gradeSection)
    {
        $this->authorize('update', $gradeSection->groupSubject);
        $this->assertPeriodOpen($gradeSection->period_id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:12'],
            'weight' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'color' => ['sometimes', 'string', 'max:20'],
            'has_section_final' => ['sometimes', 'boolean'],
            'section_final_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'final_calculation' => ['sometimes', 'in:weighted_avg,simple_avg,manual'],
        ]);

        $gradeSection->update($validated);

        return response()->json(['data' => $gradeSection->load('columns')]);
    }

    public function destroy(Request $request, GradeSection $gradeSection)
    {
        $this->authorize('update', $gradeSection->groupSubject);
        $this->assertPeriodOpen($gradeSection->period_id);

        $hasGrades = Grade::whereIn('grade_column_id', $gradeSection->columns()->pluck('id'))->exists();
        if ($hasGrades && ! $request->boolean('confirm')) {
            return response()->json([
                'message' => 'Esta sección tiene notas registradas. Confirma la eliminación.',
                'requires_confirmation' => true,
            ], 409);
        }

        $gradeSection->delete();

        return response()->json(['message' => 'Sección eliminada.']);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'sections' => ['required', 'array'],
            'sections.*.id' => ['required', 'integer', 'exists:grade_sections,id'],
            'sections.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($validated['sections'] as $item) {
            GradeSection::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['message' => 'Orden actualizado.']);
    }

    /**
     * Replaces the entire sections+columns configuration for a group_subject+period
     * in one call, enforcing the weight-sum rules from "VALIDACIONES IMPORTANTES".
     */
    public function bulkSave(BulkSaveGradeSheetRequest $request, GradeCalculatorService $calculator)
    {
        $groupSubject = GroupSubject::findOrFail($request->group_subject_id);
        $this->authorize('update', $groupSubject);
        $this->assertPeriodOpen($request->period_id);

        $existingSections = GradeSection::where('group_subject_id', $request->group_subject_id)
            ->where('period_id', $request->period_id)
            ->with('columns')
            ->get();

        $incomingSectionIds = collect($request->sections)->pluck('id')->filter()->all();
        $sectionsToDelete = $existingSections->whereNotIn('id', $incomingSectionIds);

        $incomingColumnIds = collect($request->sections)
            ->flatMap(fn ($s) => collect($s['columns'])->pluck('id'))
            ->filter()
            ->all();
        $columnsToDelete = $existingSections->flatMap->columns->whereNotIn('id', $incomingColumnIds);

        $affectedColumnIds = $sectionsToDelete->flatMap->columns->pluck('id')
            ->merge($columnsToDelete->pluck('id'));

        if ($affectedColumnIds->isNotEmpty() && Grade::whereIn('grade_column_id', $affectedColumnIds)->exists()
            && ! $request->boolean('confirm_delete')) {
            return response()->json([
                'message' => 'Algunas secciones o columnas que estás eliminando tienen notas registradas. Confirma la eliminación.',
                'requires_confirmation' => true,
            ], 409);
        }

        DB::transaction(function () use ($request, $groupSubject, $sectionsToDelete, $columnsToDelete) {
            $columnsToDelete->each->delete();
            $sectionsToDelete->each->delete();

            // Las columnas nuevas toman la nota máxima de la escala de la institución (5.0 o 10.0).
            $scaleMax = PerformanceScale::maxForInstitution($groupSubject->institution);
            $columnDefaults = [...self::COLUMN_DEFAULTS, 'max_score' => $scaleMax, 'attendance_base_score' => $scaleMax];

            foreach ($request->sections as $sectionIndex => $sectionData) {
                $sectionAttributes = [
                    'group_subject_id' => $request->group_subject_id,
                    'period_id' => $request->period_id,
                    'name' => $sectionData['name'],
                    'weight' => $sectionData['weight'],
                    'sort_order' => $sectionIndex,
                    ...$this->optionalAttributes($sectionData, isset($sectionData['id']), self::SECTION_DEFAULTS),
                ];

                $section = isset($sectionData['id'])
                    ? tap(GradeSection::findOrFail($sectionData['id']))->update($sectionAttributes)
                    : GradeSection::create($sectionAttributes);

                foreach ($sectionData['columns'] as $columnIndex => $columnData) {
                    $columnAttributes = [
                        'grade_section_id' => $section->id,
                        'group_subject_id' => $request->group_subject_id,
                        'period_id' => $request->period_id,
                        'column_type' => $columnData['column_type'],
                        'name' => $columnData['name'],
                        'weight' => $columnData['weight'],
                        'sort_order' => $columnIndex,
                        ...$this->optionalAttributes($columnData, isset($columnData['id']), $columnDefaults),
                    ];

                    if (isset($columnData['id'])) {
                        GradeColumn::findOrFail($columnData['id'])->update($columnAttributes);
                    } else {
                        GradeColumn::create($columnAttributes);
                    }
                }
            }
        });

        $this->recalculateAllStudents($groupSubject, $request->period_id, $calculator);

        $sections = GradeSection::where('group_subject_id', $request->group_subject_id)
            ->where('period_id', $request->period_id)
            ->with('columns')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $sections]);
    }

    /**
     * Copies the sections+columns structure of one period into another (no grades),
     * by round-tripping through the same sections_config shape used by templates.
     */
    public function copyFromPeriod(Request $request, GradeCalculatorService $calculator)
    {
        $validated = $request->validate([
            'group_subject_id' => ['required', 'integer', 'exists:group_subjects,id'],
            'from_period_id' => ['required', 'integer', 'exists:periods,id'],
            'to_period_id' => ['required', 'integer', 'different:from_period_id', 'exists:periods,id'],
        ]);

        $groupSubject = GroupSubject::findOrFail($validated['group_subject_id']);
        $this->authorize('update', $groupSubject);

        $toPeriod = Period::findOrFail($validated['to_period_id']);
        $this->assertPeriodOpen($toPeriod->id);

        $alreadyConfigured = GradeSection::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $toPeriod->id)
            ->exists();
        abort_if($alreadyConfigured, 422, 'El período de destino ya tiene secciones configuradas.');

        $fromPeriod = Period::findOrFail($validated['from_period_id']);
        $sectionsConfig = $calculator->buildSectionsConfig($groupSubject, $fromPeriod);

        if (empty($sectionsConfig['sections'])) {
            return response()->json(['message' => 'El período de origen no tiene secciones configuradas.'], 422);
        }

        $sections = $calculator->applySectionsConfig($sectionsConfig, $groupSubject, $toPeriod);

        return response()->json(['data' => $sections]);
    }

    private function assertPeriodOpen(int $periodId): void
    {
        $period = Period::findOrFail($periodId);
        abort_if($period->is_closed, 422, 'El período está cerrado y no admite cambios en la planilla.');
    }

    private function recalculateAllStudents(GroupSubject $groupSubject, int $periodId, GradeCalculatorService $calculator): void
    {
        $studentIds = StudentGroup::where('group_id', $groupSubject->group_id)
            ->where('status', 'activo')
            ->pluck('student_id');

        foreach ($studentIds as $studentId) {
            $calculator->recalculateForStudent($studentId, $groupSubject->id, $periodId);
        }
    }

    /**
     * Campos opcionales de una sección o columna: al crear, lo que falte toma el
     * valor por defecto; al actualizar, lo que no venga en la petición se conserva.
     * Antes se aplicaban los valores por defecto también al actualizar, y cada
     * guardado de la configuración devolvía todas las columnas a nota máxima 10 y
     * borraba su fecha y descripción.
     */
    private function optionalAttributes(array $data, bool $exists, array $defaults): array
    {
        $attributes = [];
        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key] ?? $default;
            } elseif (! $exists) {
                $attributes[$key] = $default;
            }
        }

        return $attributes;
    }

}
