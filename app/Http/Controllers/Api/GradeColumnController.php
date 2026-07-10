<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeSection;
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

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $section = GradeSection::findOrFail($validated['grade_section_id']);
        $this->authorize('update', $section->groupSubject);
        $this->assertPeriodOpen($section);

        $validated['group_subject_id'] = $section->group_subject_id;
        $validated['period_id'] = $section->period_id;
        $validated['sort_order'] = $section->columns()->count();

        $column = GradeColumn::create($validated);

        return response()->json(['data' => $column], 201);
    }

    public function update(Request $request, GradeColumn $gradeColumn)
    {
        $section = $gradeColumn->gradeSection;
        $this->authorize('update', $section->groupSubject);
        $this->assertPeriodOpen($section);

        $validated = $this->validatePayload($request, partial: true);
        $gradeColumn->update($validated);

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

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'grade_section_id' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:grade_sections,id'],
            'column_type' => [$required, 'in:manual,from_attendance,section_average,custom_formula'],
            'name' => [$required, 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:8'],
            'description' => ['nullable', 'string'],
            'weight' => [$required, 'numeric', 'min:0', 'max:100'],
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
