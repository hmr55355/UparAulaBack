<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Period;
use Illuminate\Http\Request;

class PeriodController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['yearId' => ['required', 'integer', 'exists:academic_years,id']]);

        $periods = Period::where('academic_year_id', $request->yearId)->orderBy('number')->get();

        return response()->json(['data' => $periods]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'number' => ['required', 'integer', 'min:1', 'max:6'],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        $academicYear = AcademicYear::findOrFail($validated['academic_year_id']);
        $this->authorize('manageAcademics', $academicYear->institution);

        $period = Period::create($validated);

        return response()->json(['data' => $period], 201);
    }

    public function update(Request $request, Period $period)
    {
        $this->authorize('manageAcademics', $period->academicYear->institution);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after:start_date'],
            'is_closed' => ['sometimes', 'boolean'],
        ]);

        $period->update($validated);

        return response()->json(['data' => $period]);
    }

    public function setActive(Period $period)
    {
        $this->authorize('manageAcademics', $period->academicYear->institution);

        Period::where('academic_year_id', $period->academic_year_id)->update(['is_active' => false]);
        $period->update(['is_active' => true]);

        return response()->json(['data' => $period]);
    }
}
