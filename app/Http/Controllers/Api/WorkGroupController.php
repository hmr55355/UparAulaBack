<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeConvention;
use App\Models\StudentGroup;
use App\Models\WorkGroup;
use App\Services\GradeCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grupos de trabajo de una actividad. Se guardan todos juntos (como "Configurar
 * planilla"): lo que no viene en la lista se elimina.
 *
 * La nota del grupo se copia a cada integrante en `grades`. Solo se reescribe a
 * todos cuando cambia la nota del grupo; si no cambió, solo la reciben los
 * integrantes nuevos, así no se pierde la nota que el docente le cambió a uno.
 */
class WorkGroupController extends Controller
{
    public function index(GradeColumn $gradeColumn)
    {
        $this->authorize('view', $gradeColumn->groupSubject);

        return response()->json(['data' => $this->groupsOf($gradeColumn)]);
    }

    public function sync(Request $request, GradeColumn $gradeColumn, GradeCalculatorService $calculator)
    {
        $groupSubject = $gradeColumn->groupSubject;
        $this->authorize('update', $groupSubject);
        abort_if($gradeColumn->period->is_closed, 422, 'El período está cerrado. Las notas no se pueden editar.');
        abort_if($gradeColumn->column_type !== 'manual', 422, 'Solo las actividades que se califican a mano admiten grupos.');

        $validated = $request->validate([
            'groups' => ['present', 'array', 'max:60'],
            'groups.*.id' => ['nullable', 'integer'],
            'groups.*.name' => ['required', 'string', 'max:80'],
            'groups.*.student_ids' => ['present', 'array'],
            'groups.*.student_ids.*' => ['integer'],
            'groups.*.score' => ['nullable', 'numeric'],
            'groups.*.convention_id' => ['nullable', 'integer'],
        ]);

        $existing = WorkGroup::with('members:students.id')->where('grade_column_id', $gradeColumn->id)->get()->keyBy('id');
        $enrolled = StudentGroup::where('group_id', $groupSubject->group_id)->where('status', 'activo')
            ->pluck('student_id')->all();
        $conventions = GradeConvention::where('user_id', $groupSubject->user_id)->get()->keyBy('id');

        $seen = [];
        foreach ($validated['groups'] as $i => $group) {
            abort_if(isset($group['id']) && ! $existing->has($group['id']), 422, 'Uno de los grupos no es de esta actividad.');
            foreach ($group['student_ids'] as $studentId) {
                abort_if(! in_array($studentId, $enrolled, true), 422, 'Uno de los estudiantes no está matriculado en este grupo.');
                abort_if(isset($seen[$studentId]), 422, 'Un estudiante no puede estar en dos grupos de la misma actividad.');
                $seen[$studentId] = true;
            }
            if (! empty($group['convention_id'])) {
                abort_if(! $conventions->has($group['convention_id']), 422, 'Esa convención no pertenece al docente de este curso.');
            } elseif (isset($group['score'])) {
                $this->assertScoreWithinRange((float) $group['score'], $gradeColumn, "groups.{$i}.score");
            }
        }

        $gradeWrites = [];

        DB::transaction(function () use ($validated, $existing, $gradeColumn, $conventions, &$gradeWrites) {
            $keptIds = collect($validated['groups'])->pluck('id')->filter();
            WorkGroup::whereIn('id', $existing->keys()->diff($keptIds))->delete();
            // Se reescriben todos los integrantes: así un estudiante puede pasar de un grupo a otro
            // sin chocar con la llave única (actividad, estudiante).
            DB::table('work_group_members')->where('grade_column_id', $gradeColumn->id)->delete();

            foreach ($validated['groups'] as $index => $item) {
                $convention = ! empty($item['convention_id']) ? $conventions->get($item['convention_id']) : null;
                $attributes = [
                    'name' => trim($item['name']),
                    'convention_id' => $convention?->id,
                    'score' => $convention ? $convention->scoreFor($gradeColumn) : ($item['score'] ?? null),
                    'sort_order' => $index,
                ];

                $previous = isset($item['id']) ? $existing->get($item['id']) : null;
                $gradeChanged = ! $previous
                    ? ($attributes['score'] !== null || $attributes['convention_id'] !== null)
                    : ((int) $previous->convention_id !== (int) $attributes['convention_id']
                        || $this->differentScore($previous->score, $attributes['score']));
                $previousMembers = $previous ? $previous->members->pluck('id')->all() : [];

                if ($previous) {
                    $previous->update($attributes);
                    $group = $previous;
                } else {
                    $group = WorkGroup::create($attributes + ['grade_column_id' => $gradeColumn->id]);
                }

                DB::table('work_group_members')->insert(array_map(fn ($studentId) => [
                    'work_group_id' => $group->id,
                    'grade_column_id' => $gradeColumn->id,
                    'student_id' => $studentId,
                ], $item['student_ids']));

                $targets = $gradeChanged ? $item['student_ids'] : array_diff($item['student_ids'], $previousMembers);

                // Un integrante nuevo de un grupo sin nota no recibe nada (no se le borra la suya).
                if (! $gradeChanged && $attributes['score'] === null && $attributes['convention_id'] === null) {
                    $targets = [];
                }

                foreach ($targets as $studentId) {
                    $gradeWrites[$studentId] = [$attributes['score'], $attributes['convention_id']];
                }
            }
        });

        // Igual que /grades/bulk: se escribe todo sin observers y se recalcula una vez por estudiante.
        Grade::withoutEvents(function () use ($gradeWrites, $gradeColumn, $request) {
            foreach ($gradeWrites as $studentId => [$score, $conventionId]) {
                Grade::updateOrCreate(
                    ['student_id' => $studentId, 'grade_column_id' => $gradeColumn->id],
                    [
                        'group_subject_id' => $gradeColumn->group_subject_id,
                        'period_id' => $gradeColumn->period_id,
                        'score' => $score,
                        'convention_id' => $conventionId,
                        'registered_by' => $request->user()->id,
                    ]
                );
            }
        });
        foreach (array_keys($gradeWrites) as $studentId) {
            $calculator->recalculateForStudent($studentId, $gradeColumn->group_subject_id, $gradeColumn->period_id);
        }

        return response()->json([
            'data' => $this->groupsOf($gradeColumn),
            'grades_updated' => count($gradeWrites),
        ]);
    }

    private function groupsOf(GradeColumn $column): array
    {
        return WorkGroup::with('members:students.id')->where('grade_column_id', $column->id)
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map->toSheetArray()->all();
    }

    private function differentScore($old, $new): bool
    {
        if ($old === null || $new === null) {
            return $old !== $new;
        }

        return abs((float) $old - (float) $new) > 0.001;
    }

    private function assertScoreWithinRange(float $score, GradeColumn $column, string $field): void
    {
        if ($score < 1.0 || $score > (float) $column->max_score) {
            throw ValidationException::withMessages([
                $field => ["La nota debe estar entre 1.0 y {$column->max_score}."],
            ]);
        }
    }
}
