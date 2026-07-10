<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\Subject;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    public function index(Request $request)
    {
        $institution = $this->resolveInstitution($request);

        return response()->json(['data' => $institution->subjects()->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $institution = $this->resolveInstitution($request);
        $this->authorize('manageAcademics', $institution);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'color' => ['sometimes', 'string', 'max:20'],
        ]);

        $subject = $institution->subjects()->create($validated);

        return response()->json(['data' => $subject], 201);
    }

    public function update(Request $request, Subject $subject)
    {
        $this->authorize('manageAcademics', $subject->institution);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'color' => ['sometimes', 'string', 'max:20'],
        ]);

        $subject->update($validated);

        return response()->json(['data' => $subject]);
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
