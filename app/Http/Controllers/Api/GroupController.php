<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $institution = $this->resolveInstitution($request);

        $groups = $institution->groups()
            ->when($request->query('academicYearId'), fn ($q, $id) => $q->where('academic_year_id', $id))
            ->when($request->query('shiftId'), fn ($q, $id) => $q->where('shift_id', $id))
            ->with('shift:id,name')
            ->orderBy('grade_level')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $groups]);
    }

    public function store(Request $request)
    {
        $institution = $this->resolveInstitution($request);
        $this->authorize('manageAcademics', $institution);

        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'name' => ['required', 'string', 'max:255'],
            'grade_level_id' => ['required_without:grade_level', 'nullable', 'integer', Rule::exists('grade_levels', 'id')->where('institution_id', $institution->id)],
            'grade_level' => ['required_without:grade_level_id', 'nullable', 'string', 'max:50'],
            'shift_id' => ['nullable', 'integer', Rule::exists('shifts', 'id')->where('institution_id', $institution->id)],
            'section' => ['nullable', 'string', 'max:50'],
        ]);

        $validated['shift_id'] ??= $institution->shifts()->value('id');
        $group = $institution->groups()->create($this->withLegacyGradeLevel($validated));

        return response()->json(['data' => $group->load('shift:id,name')], 201);
    }

    public function update(Request $request, Group $group)
    {
        $this->authorize('manageAcademics', $group->institution);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'grade_level_id' => ['sometimes', 'nullable', 'integer', Rule::exists('grade_levels', 'id')->where('institution_id', $group->institution_id)],
            'grade_level' => ['sometimes', 'string', 'max:50'],
            'shift_id' => ['sometimes', 'integer', Rule::exists('shifts', 'id')->where('institution_id', $group->institution_id)],
            'section' => ['nullable', 'string', 'max:50'],
        ]);

        $group->update($this->withLegacyGradeLevel($validated));

        return response()->json(['data' => $group->load('shift:id,name')]);
    }

    /**
     * `groups.grade_level` (texto) se conserva por compatibilidad con reportes y
     * exportes: al elegir un grado, se llena con su número (o su nombre).
     */
    private function withLegacyGradeLevel(array $validated): array
    {
        if (! empty($validated['grade_level_id'])) {
            $gradeLevel = GradeLevel::find($validated['grade_level_id']);
            $validated['grade_level'] = $gradeLevel->level !== null ? (string) $gradeLevel->level : $gradeLevel->name;
        }

        return $validated;
    }

    public function destroy(Group $group)
    {
        $this->authorize('manageAcademics', $group->institution);

        $group->delete();

        return response()->json(['message' => 'Grupo eliminado.']);
    }

    public function students(Group $group)
    {
        $this->authorize('view', $group->institution);

        $students = $group->studentGroups()
            ->where('status', 'activo')
            ->with('student')
            ->get()
            ->pluck('student');

        return response()->json(['data' => $students]);
    }

    private function resolveInstitution(Request $request): Institution
    {
        $institutionId = $request->query('institutionId') ?? $request->input('institution_id');

        if ($institutionId) {
            return Institution::findOrFail($institutionId);
        }

        return $request->user()->institutionTeachers()->where('status', 'active')->firstOrFail()->institution;
    }
}
