<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    public function index(Request $request)
    {
        $institution = $this->resolveInstitution($request);

        $subjects = $institution->subjects()->with('gradeLevels:id')->orderBy('name')->get()
            ->map(fn (Subject $subject) => [
                ...$subject->only(['id', 'institution_id', 'name', 'code', 'color']),
                'grade_level_ids' => $subject->gradeLevels->pluck('id'),
            ]);

        return response()->json(['data' => $subjects]);
    }

    public function store(Request $request)
    {
        $institution = $this->resolveInstitution($request);
        $this->authorize('manageAcademics', $institution);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'grade_level_ids' => ['sometimes', 'array'],
            'grade_level_ids.*' => ['integer', Rule::exists('grade_levels', 'id')->where('institution_id', $institution->id)],
        ]);

        $subject = $institution->subjects()->create(collect($validated)->except('grade_level_ids')->all());
        $subject->gradeLevels()->sync($validated['grade_level_ids'] ?? []);

        return response()->json(['data' => $subject->load('gradeLevels:id')], 201);
    }

    public function update(Request $request, Subject $subject)
    {
        $this->authorize('manageAcademics', $subject->institution);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'grade_level_ids' => ['sometimes', 'array'],
            'grade_level_ids.*' => ['integer', Rule::exists('grade_levels', 'id')->where('institution_id', $subject->institution_id)],
        ]);

        $subject->update(collect($validated)->except('grade_level_ids')->all());
        if (array_key_exists('grade_level_ids', $validated)) {
            $subject->gradeLevels()->sync($validated['grade_level_ids']);
        }

        return response()->json(['data' => $subject->load('gradeLevels:id')]);
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
