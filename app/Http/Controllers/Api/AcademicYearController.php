<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Institution;
use Illuminate\Http\Request;

class AcademicYearController extends Controller
{
    public function index(Request $request)
    {
        $institution = $this->resolveInstitution($request);

        return response()->json(['data' => $institution->academicYears()->orderByDesc('year')->get()]);
    }

    public function store(Request $request)
    {
        $institution = $this->resolveInstitution($request);
        $this->authorize('manageAcademics', $institution);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('is_active')) {
            $institution->academicYears()->update(['is_active' => false]);
        }

        $academicYear = $institution->academicYears()->create($validated);

        return response()->json(['data' => $academicYear], 201);
    }

    private function resolveInstitution(Request $request): Institution
    {
        $institutionId = $request->query('institutionId') ?? $request->input('institution_id');

        if ($institutionId) {
            return Institution::findOrFail($institutionId);
        }

        $membership = $request->user()->institutionTeachers()->where('status', 'active')->firstOrFail();

        return $membership->institution;
    }
}
