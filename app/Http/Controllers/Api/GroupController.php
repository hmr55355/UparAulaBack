<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Institution;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $institution = $this->resolveInstitution($request);

        $groups = $institution->groups()
            ->when($request->query('academicYearId'), fn ($q, $id) => $q->where('academic_year_id', $id))
            ->orderBy('grade_level')
            ->orderBy('section')
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
            'grade_level' => ['required', 'string', 'max:50'],
            'section' => ['nullable', 'string', 'max:50'],
        ]);

        $group = $institution->groups()->create($validated);

        return response()->json(['data' => $group], 201);
    }

    public function update(Request $request, Group $group)
    {
        $this->authorize('manageAcademics', $group->institution);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'grade_level' => ['sometimes', 'string', 'max:50'],
            'section' => ['nullable', 'string', 'max:50'],
        ]);

        $group->update($validated);

        return response()->json(['data' => $group]);
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
