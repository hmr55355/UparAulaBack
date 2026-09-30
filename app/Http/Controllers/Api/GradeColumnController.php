<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeSection;
use App\Models\Homework;
use App\Services\GradeCalculatorService;
use App\Services\PerformanceScale;
use Illuminate\Http\Request;

class GradeColumnController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['sectionId' => ['required', 'integer', 'exists:grade_sections,id']]);

        $section = GradeSection::findOrFail($request->sectionId);
        $this->authorize('view', $section->groupSubject);

        $columns = $section->columns()->orderBy('sort_order')->get();

        return response()->json(['data' => $columns]);
    }

    /**
     * Agrega una columna a una sección (también desde la planilla, sin pasar por
     * Configurar planilla). Con weight_mode=automatic la sección queda repartida
     * por igual; en ambos casos se recalculan las definitivas del curso.
     */
    public function store(Request $request, GradeCalculatorService $calculator)
    {
        $automatic = $request->input('weight_mode') === 'automatic';
        $validated = $this->validatePayload($request, weightRequired: ! $automatic);
        $request->validate(['weight_mode' => ['sometimes', 'in:manual,automatic']]);

        $section = GradeSection::with('groupSubject.institution')->findOrFail($validated['grade_section_id']);
        $this->authorize('update', $section->groupSubject);
        $this->assertPeriodOpen($section);

        $validated['group_subject_id'] = $section->group_subject_id;
        $validated['period_id'] = $section->period_id;
        $validated['sort_order'] = $section->columns()->count();
        $validated['weight'] = $automatic ? 0 : $validated['weight'];
        $validated['max_score'] ??= PerformanceScale::maxForInstitution($section->groupSubject->institution);

        $column = GradeColumn::create($validated);

        if ($automatic) {
            $section->distributeWeightsEqually();
        }
        $calculator->recalculateCourse($section->group_subject_id, $section->period_id);

        return response()->json(['data' => $column->fresh()], 201);
    }

    public function update(Request $request, GradeColumn $gradeColumn)
    {
        $section = $gradeColumn->gradeSection;
        $this->authorize('update', $section->groupSubject);
        $this->assertPeriodOpen($section);

        $validated = $this->validatePayload($request, partial: true);
        $gradeColumn->update($validated);

        // Si la columna la generó una tarea, la tarea toma el nombre nuevo (al revés
        // ya pasaba: editar la tarea renombra su columna).
        if (array_key_exists('name', $validated)) {
            Homework::where('grade_column_id', $gradeColumn->id)->update(['title' => $validated['name']]);
        }

        return response()->json(['data' => $gradeColumn]);
    }

    public function destroy(Request $request, GradeColumn $gradeColumn)
    {
        $section = $gradeColumn->gradeSection;
        $this->authorize('update', $section->groupSubject);
        $this->assertPeriodOpen($section);

        if (Grade::where('grade_column_id', $gradeColumn->id)->exists() && ! $request->boolean('confirm')) {
            return response()->json([
                'message' => 'Esta columna tiene notas registradas. Confirma la eliminación.',
                'requires_confirmation' => true,
            ], 409);
        }

        $gradeColumn->delete();

        return response()->json(['message' => 'Columna eliminada.']);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'columns' => ['required', 'array'],
            'columns.*.id' => ['required', 'integer', 'exists:grade_columns,id'],
            'columns.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($validated['columns'] as $item) {
            GradeColumn::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['message' => 'Orden actualizado.']);
    }

    private function validatePayload(Request $request, bool $partial = false, bool $weightRequired = true): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'grade_section_id' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:grade_sections,id'],
            'column_type' => [$required, 'in:manual,from_attendance,section_average,custom_formula,from_participation'],
            'name' => [$required, 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:8'],
            'description' => ['nullable', 'string'],
            'weight' => [$weightRequired ? $required : 'nullable', 'numeric', 'min:0', 'max:100'],
            'max_score' => ['sometimes', 'numeric', 'min:1'],
            'date' => ['nullable', 'date'],
            'attendance_base_score' => ['sometimes', 'numeric'],
            'absence_penalty' => ['sometimes', 'numeric'],
            'justified_absence_penalty' => ['sometimes', 'numeric'],
            'formula' => ['nullable', 'string'],
        ]);
    }

    private function assertPeriodOpen(GradeSection $section): void
    {
        abort_if($section->period->is_closed, 422, 'El período está cerrado y no admite cambios en la planilla.');
    }
}
