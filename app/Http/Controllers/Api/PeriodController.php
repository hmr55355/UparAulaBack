<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Period;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        $this->assertNoOverlap($academicYear->id, $validated['start_date'], $validated['end_date']);

        $period = Period::create($validated);

        return response()->json(['data' => $period], 201);
    }

    public function update(Request $request, Period $period)
    {
        $this->authorize('manageAcademics', $period->academicYear->institution);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
            'is_closed' => ['sometimes', 'boolean'],
        ]);

        // Las fechas se validan con las que quedarán (puede venir solo una de las dos).
        $start = $validated['start_date'] ?? $period->start_date->toDateString();
        $end = $validated['end_date'] ?? $period->end_date->toDateString();
        if ($end <= $start) {
            throw ValidationException::withMessages(['end_date' => ['El período debe terminar después de empezar.']]);
        }
        $this->assertNoOverlap($period->academic_year_id, $start, $end, $period->id);

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

    /**
     * Dos períodos del mismo año no pueden cruzarse: la asistencia y las notas de
     * un día se asignan al período cuyas fechas lo contienen.
     */
    private function assertNoOverlap(int $academicYearId, string $start, string $end, ?int $ignoreId = null): void
    {
        $overlap = Period::where('academic_year_id', $academicYearId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->first();

        if ($overlap) {
            throw ValidationException::withMessages([
                'start_date' => ["Las fechas se cruzan con \"{$overlap->name}\" ({$overlap->start_date->toDateString()} a {$overlap->end_date->toDateString()})."],
            ]);
        }
    }

}
