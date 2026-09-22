<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use App\Models\Institution;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Jornadas de la institución y sus bloques de horario (clases y descansos). */
class ShiftController extends Controller
{
    public function index(Institution $institution)
    {
        $this->authorize('view', $institution);

        $shifts = $institution->shifts()->withCount('groups')->with('classBlocks')->get();

        return response()->json(['data' => $shifts]);
    }

    public function store(Request $request, Institution $institution)
    {
        $this->authorize('manageAcademics', $institution);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('shifts')->where('institution_id', $institution->id)],
        ]);

        $shift = $institution->shifts()->create($validated + ['sort_order' => $institution->shifts()->count()]);

        return response()->json(['data' => $shift->load('classBlocks')], 201);
    }

    public function update(Request $request, Shift $shift)
    {
        $this->authorize('manageAcademics', $shift->institution);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('shifts')->where('institution_id', $shift->institution_id)->ignore($shift->id)],
        ]);

        $shift->update($validated);

        return response()->json(['data' => $shift]);
    }

    public function destroy(Shift $shift)
    {
        $this->authorize('manageAcademics', $shift->institution);
        abort_if($shift->groups()->exists(), 422, 'Esta jornada tiene grupos. Muévelos a otra jornada antes de eliminarla.');
        abort_if($shift->institution->shifts()->count() <= 1, 422, 'La institución debe tener al menos una jornada.');

        $shift->delete();

        return response()->json(['message' => 'Jornada eliminada.']);
    }

    /**
     * Reemplaza todos los bloques de la jornada (mismo patrón "guardar todo" que la
     * configuración de la planilla). Deben ir en orden y sin solaparse.
     */
    public function saveBlocks(Request $request, Shift $shift)
    {
        $this->authorize('manageAcademics', $shift->institution);

        $validated = $request->validate([
            'blocks' => ['present', 'array', 'max:30'],
            'blocks.*.type' => ['required', Rule::in(['clase', 'descanso'])],
            'blocks.*.label' => ['required', 'string', 'max:50'],
            'blocks.*.start_time' => ['required', 'date_format:H:i'],
            'blocks.*.end_time' => ['required', 'date_format:H:i'],
        ]);

        $blocks = collect($validated['blocks'])->sortBy('start_time')->values();
        foreach ($blocks as $index => $block) {
            if ($block['end_time'] <= $block['start_time']) {
                throw ValidationException::withMessages(['blocks' => ["El bloque \"{$block['label']}\" termina antes de empezar."]]);
            }
            $previous = $blocks[$index - 1] ?? null;
            if ($previous && $block['start_time'] < $previous['end_time']) {
                throw ValidationException::withMessages(['blocks' => ["Los bloques \"{$previous['label']}\" y \"{$block['label']}\" se cruzan."]]);
            }
        }

        DB::transaction(function () use ($shift, $blocks) {
            $shift->classBlocks()->delete();
            foreach ($blocks as $index => $block) {
                $shift->classBlocks()->create($block + ['sort_order' => $index]);
            }
        });

        return response()->json(['data' => $shift->fresh()->classBlocks]);
    }

    /**
     * Propone bloques a partir de los horarios de clase ya cargados en los grupos
     * de esta jornada: cada hora de inicio/fin es un límite, cada tramo cubierto por
     * alguna clase es un bloque y cada hueco entre clases es un descanso. No guarda
     * nada — el admin revisa la propuesta y la guarda con saveBlocks.
     */
    public function inferBlocks(Shift $shift)
    {
        $this->authorize('manageAcademics', $shift->institution);

        $entries = ClassSchedule::whereHas('groupSubject.group', fn ($q) => $q->where('shift_id', $shift->id))
            ->where('is_active', true)
            ->get(['start_time', 'end_time']);

        $toMinutes = fn (string $time) => ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
        $toTime = fn (int $minutes) => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);

        $ranges = $entries->map(fn ($e) => [$toMinutes($e->start_time), $toMinutes($e->end_time)]);
        $points = $ranges->flatten()->unique()->sort()->values();

        $blocks = [];
        $classNumber = 1;
        for ($i = 0; $i < $points->count() - 1; $i++) {
            [$start, $end] = [$points[$i], $points[$i + 1]];
            $covered = $ranges->contains(fn ($r) => $r[0] <= $start && $r[1] >= $end);
            $blocks[] = [
                'type' => $covered ? 'clase' : 'descanso',
                'label' => $covered ? (string) $classNumber++ : 'Descanso',
                'start_time' => $toTime($start),
                'end_time' => $toTime($end),
            ];
        }

        return response()->json(['data' => $blocks]);
    }
}
