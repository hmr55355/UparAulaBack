<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // La columna is_active vale 1 por defecto: sin esto, crear un año sin marcarlo
        // dejaba dos años activos a la vez.
        $validated['is_active'] = $request->boolean('is_active');
        if ($validated['is_active']) {
            $institution->academicYears()->update(['is_active' => false]);
        }

        $academicYear = $institution->academicYears()->create($validated);

        return response()->json(['data' => $academicYear], 201);
    }

    public function update(Request $request, AcademicYear $academicYear)
    {
        $this->authorize('manageAcademics', $academicYear->institution);

        $validated = $request->validate([
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
        ]);
        $start = $validated['start_date'] ?? $academicYear->start_date->toDateString();
        $end = $validated['end_date'] ?? $academicYear->end_date->toDateString();
        abort_if($end <= $start, 422, 'El año escolar debe terminar después de empezar.');

        $academicYear->update($validated);

        return response()->json(['data' => $academicYear->fresh()]);
    }

    /** Solo un año activo por institución. */
    public function setActive(AcademicYear $academicYear)
    {
        $this->authorize('manageAcademics', $academicYear->institution);

        DB::transaction(function () use ($academicYear) {
            AcademicYear::where('institution_id', $academicYear->institution_id)->update(['is_active' => false]);
            $academicYear->update(['is_active' => true]);
        });

        return response()->json(['data' => $academicYear->fresh()]);
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
