<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GradeLevel;
use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Grados de la institución (Sexto…Once). Las materias se vinculan desde SubjectController. */
class GradeLevelController extends Controller
{
    public function index(Institution $institution)
    {
        $this->authorize('view', $institution);

        $gradeLevels = $institution->gradeLevels()->withCount('groups')->with('subjects:id')->get()
            ->map(fn (GradeLevel $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'level' => $g->level,
                'sort_order' => $g->sort_order,
                'groups_count' => $g->groups_count,
                'subject_ids' => $g->subjects->pluck('id'),
            ]);

        return response()->json(['data' => $gradeLevels]);
    }

    public function store(Request $request, Institution $institution)
    {
        $this->authorize('manageAcademics', $institution);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('grade_levels')->where('institution_id', $institution->id)],
            'level' => ['nullable', 'integer', 'min:0', 'max:13'],
        ]);

        $gradeLevel = $institution->gradeLevels()->create($validated + ['sort_order' => $validated['level'] ?? 99]);

        return response()->json(['data' => $gradeLevel], 201);
    }

    public function update(Request $request, GradeLevel $gradeLevel)
    {
        $this->authorize('manageAcademics', $gradeLevel->institution);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('grade_levels')->where('institution_id', $gradeLevel->institution_id)->ignore($gradeLevel->id)],
            'level' => ['nullable', 'integer', 'min:0', 'max:13'],
        ]);

        $gradeLevel->update($validated);

        return response()->json(['data' => $gradeLevel]);
    }

    public function destroy(GradeLevel $gradeLevel)
    {
        $this->authorize('manageAcademics', $gradeLevel->institution);
        abort_if($gradeLevel->groups()->exists(), 422, 'Este grado tiene grupos. Muévelos a otro grado antes de eliminarlo.');

        $gradeLevel->delete();

        return response()->json(['message' => 'Grado eliminado.']);
    }
}
