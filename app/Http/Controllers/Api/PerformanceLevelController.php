<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Services\PerformanceScale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Escala de valoración de la institución (la del SIEE). Se guarda completa de una
 * vez: los rangos deben cubrir toda la escala sin huecos ni cruces, y deben estar
 * los cuatro niveles de la escala nacional en orden. La nota mínima para aprobar
 * de la institución se deriva de aquí (inicio del primer nivel que no es Bajo).
 */
class PerformanceLevelController extends Controller
{
    public function index(Institution $institution)
    {
        $this->authorize('view', $institution);

        return response()->json(['data' => $institution->performanceLevels]);
    }

    public function update(Request $request, Institution $institution)
    {
        $this->authorize('update', $institution);

        $validated = $request->validate([
            'grading_scale' => ['required', Rule::in(['1_to_10', '1_to_5'])],
            'levels' => ['required', 'array', 'min:4', 'max:8'],
            'levels.*.name' => ['required', 'string', 'max:50'],
            'levels.*.national_level' => ['required', Rule::in(PerformanceScale::NATIONAL_LEVELS)],
            'levels.*.min_score' => ['required', 'numeric', 'min:1', 'max:10'],
            'levels.*.max_score' => ['required', 'numeric', 'min:1', 'max:10'],
            'levels.*.color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $max = PerformanceScale::maxFor($validated['grading_scale']);
        $levels = collect($validated['levels'])
            ->map(fn ($l) => [...$l, 'min_score' => round((float) $l['min_score'], 1), 'max_score' => round((float) $l['max_score'], 1)])
            ->sortBy('min_score')
            ->values();

        $this->assertValidScale($levels->all(), $max);

        $minPassing = $levels->first(fn ($l) => $l['national_level'] !== 'bajo')['min_score'];

        DB::transaction(function () use ($institution, $levels, $validated, $minPassing) {
            $institution->performanceLevels()->delete();
            foreach ($levels as $index => $level) {
                $institution->performanceLevels()->create([...$level, 'color' => strtoupper($level['color']), 'sort_order' => $index]);
            }
            $institution->update(['grading_scale' => $validated['grading_scale'], 'min_passing_grade' => $minPassing]);
        });

        return response()->json([
            'data' => $institution->performanceLevels()->get(),
            'min_passing_grade' => $minPassing,
        ]);
    }

    /** Escala sugerida, para el botón "Usar la escala sugerida". */
    public function suggested(Request $request, Institution $institution)
    {
        $this->authorize('view', $institution);

        $validated = $request->validate([
            'grading_scale' => ['required', Rule::in(['1_to_10', '1_to_5'])],
            'min_passing_grade' => ['nullable', 'numeric', 'min:1.1', 'max:10'],
        ]);
        $minPassing = (float) ($validated['min_passing_grade'] ?? ($validated['grading_scale'] === '1_to_5' ? 3.0 : 6.0));

        return response()->json(['data' => PerformanceScale::defaultsFor($validated['grading_scale'], $minPassing)]);
    }

    /** @param array<int, array{name: string, national_level: string, min_score: float, max_score: float}> $levels */
    private function assertValidScale(array $levels, float $max): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['levels' => [$message]]);

        if ($levels[0]['min_score'] !== 1.0) {
            $fail('La escala debe empezar en 1.0.');
        }
        if (end($levels)['max_score'] !== $max) {
            $fail('La escala debe terminar en '.number_format($max, 1).'.');
        }

        $rank = array_flip(PerformanceScale::NATIONAL_LEVELS);
        foreach ($levels as $index => $level) {
            if ($level['max_score'] < $level['min_score']) {
                $fail("El nivel \"{$level['name']}\" termina antes de empezar.");
            }
            $previous = $levels[$index - 1] ?? null;
            if ($previous) {
                $expected = round($previous['max_score'] + 0.1, 1);
                if ($level['min_score'] !== $expected) {
                    $fail("Entre \"{$previous['name']}\" y \"{$level['name']}\" hay un hueco o un cruce: \"{$level['name']}\" debe empezar en ".number_format($expected, 1).'.');
                }
                if ($rank[$level['national_level']] < $rank[$previous['national_level']]) {
                    $fail("\"{$level['name']}\" tiene notas más altas que \"{$previous['name']}\" pero una equivalencia nacional menor.");
                }
            }
        }

        $present = array_unique(array_column($levels, 'national_level'));
        $missing = array_diff(PerformanceScale::NATIONAL_LEVELS, $present);
        if ($missing) {
            $names = implode(', ', array_map(fn ($l) => PerformanceScale::NATIONAL_LABELS[$l], $missing));
            $fail("La escala debe tener equivalencia con los cuatro niveles nacionales (Decreto 1290 de 2009, art. 5). Falta: {$names}.");
        }
    }
}
