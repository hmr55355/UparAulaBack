<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeConvention;
use App\Models\StudentGroup;
use App\Models\WorkGroup;
use App\Models\WorkGroupGrade;
use App\Models\WorkGroupSet;
use App\Services\GradeCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grupos de trabajo. Los grupos viven en un conjunto del curso y período que varias
 * actividades comparten (las fases de un proyecto); cada actividad tiene su propia
 * nota por grupo. Se guarda todo junto desde una actividad (como "Configurar planilla"):
 * los grupos que no vienen en la lista se eliminan del conjunto.
 *
 * La nota del grupo se copia a cada integrante en `grades`. Solo se reescribe a todos
 * cuando cambia la nota del grupo; si no cambió, solo la reciben los integrantes
 * nuevos, así no se pierde la nota que el docente le cambió a uno.
 */
class WorkGroupController extends Controller
{
    public function index(GradeColumn $gradeColumn)
    {
        $this->authorize('view', $gradeColumn->groupSubject);

        return response()->json(['data' => self::groupsForColumns(collect([$gradeColumn]))[$gradeColumn->id] ?? []]);
    }

    public function sync(Request $request, GradeColumn $gradeColumn, GradeCalculatorService $calculator)
    {
        $groupSubject = $gradeColumn->groupSubject;
        $this->authorize('update', $groupSubject);
        abort_if($gradeColumn->period->is_closed, 422, 'El período está cerrado. Las notas no se pueden editar.');
        abort_if($gradeColumn->column_type !== 'manual', 422, 'Solo las actividades que se califican a mano admiten grupos.');

        $validated = $request->validate([
            // Ver abajo. Sin grupos = la actividad deja de usar grupos.
            'set_id' => ['nullable', 'integer'],
            'set_name' => ['nullable', 'string', 'max:80'],
            'groups' => ['present', 'array', 'max:60'],
            'groups.*.id' => ['nullable', 'integer'],
            'groups.*.name' => ['required', 'string', 'max:80'],
            'groups.*.student_ids' => ['present', 'array'],
            'groups.*.student_ids.*' => ['integer'],
            'groups.*.score' => ['nullable', 'numeric'],
            'groups.*.convention_id' => ['nullable', 'integer'],
        ]);

        // set_id: un número = ese conjunto; null = conjunto nuevo; sin enviar = el que ya usa la actividad.
        $setId = $request->exists('set_id') ? ($validated['set_id'] ?? null) : $gradeColumn->work_group_set_id;
        $set = null;
        if ($setId) {
            $set = WorkGroupSet::findOrFail($setId);
            abort_if(
                $set->group_subject_id !== $gradeColumn->group_subject_id || $set->period_id !== $gradeColumn->period_id,
                422,
                'Ese conjunto de grupos es de otro curso o período.'
            );
        }

        // Sin grupos: la actividad deja de usarlos. El conjunto sigue si otra actividad lo usa.
        if ($validated['groups'] === []) {
            $previousSetId = $gradeColumn->work_group_set_id;
            $gradeColumn->update(['work_group_set_id' => null]);
            if ($previousSetId && ! GradeColumn::where('work_group_set_id', $previousSetId)->exists()) {
                WorkGroupSet::whereKey($previousSetId)->delete();
            }

            return response()->json(['data' => [], 'grades_updated' => 0]);
        }

        $existing = $set
            ? WorkGroup::with('members:students.id')->where('work_group_set_id', $set->id)->get()->keyBy('id')
            : collect();
        $enrolled = StudentGroup::where('group_id', $groupSubject->group_id)->where('status', 'activo')
            ->pluck('student_id')->all();
        $conventions = GradeConvention::where('user_id', $groupSubject->user_id)->get()->keyBy('id');

        $seen = [];
        foreach ($validated['groups'] as $i => $group) {
            abort_if(isset($group['id']) && ! $existing->has($group['id']), 422, 'Uno de los grupos no es de este conjunto.');
            foreach ($group['student_ids'] as $studentId) {
                abort_if(! in_array($studentId, $enrolled, true), 422, 'Uno de los estudiantes no está matriculado en este grupo.');
                abort_if(isset($seen[$studentId]), 422, 'Un estudiante no puede estar en dos grupos del mismo conjunto.');
                $seen[$studentId] = true;
            }
            if (! empty($group['convention_id'])) {
                abort_if(! $conventions->has($group['convention_id']), 422, 'Esa convención no pertenece al docente de este curso.');
            } elseif (isset($group['score'])) {
                $this->assertScoreWithinRange((float) $group['score'], $gradeColumn, "groups.{$i}.score");
            }
        }

        /** @var array<int, array<int, array{0: ?float, 1: ?int}>> $gradeWrites columna => estudiante => [nota, convención] */
        $gradeWrites = [];

        DB::transaction(function () use ($validated, &$set, $existing, $gradeColumn, $conventions, &$gradeWrites) {
            if (! $set) {
                $set = WorkGroupSet::create([
                    'group_subject_id' => $gradeColumn->group_subject_id,
                    'period_id' => $gradeColumn->period_id,
                    'name' => trim($validated['set_name'] ?? '') ?: mb_substr('Grupos de '.$gradeColumn->name, 0, 80),
                ]);
            } elseif (trim($validated['set_name'] ?? '') !== '') {
                $set->update(['name' => trim($validated['set_name'])]);
            }

            $previousSetId = $gradeColumn->work_group_set_id;
            $gradeColumn->update(['work_group_set_id' => $set->id]);
            if ($previousSetId && $previousSetId !== $set->id && ! GradeColumn::where('work_group_set_id', $previousSetId)->exists()) {
                WorkGroupSet::whereKey($previousSetId)->delete();
            }

            // Las demás actividades que comparten el conjunto, con la nota de cada grupo en ellas.
            $otherColumns = GradeColumn::where('work_group_set_id', $set->id)->where('id', '!=', $gradeColumn->id)->get();
            $otherGrades = WorkGroupGrade::whereIn('work_group_id', $existing->keys())
                ->whereIn('grade_column_id', $otherColumns->pluck('id'))->get();
            $ownGrades = WorkGroupGrade::whereIn('work_group_id', $existing->keys())
                ->where('grade_column_id', $gradeColumn->id)->get()->keyBy('work_group_id');

            $keptIds = collect($validated['groups'])->pluck('id')->filter();
            WorkGroup::whereIn('id', $existing->keys()->diff($keptIds))->delete();
            // Se reescriben todos los integrantes: así un estudiante puede pasar de un grupo a
            // otro sin chocar con la llave única (conjunto, estudiante).
            DB::table('work_group_members')->where('work_group_set_id', $set->id)->delete();

            foreach ($validated['groups'] as $index => $item) {
                $convention = ! empty($item['convention_id']) ? $conventions->get($item['convention_id']) : null;
                $grade = [
                    'convention_id' => $convention?->id,
                    'score' => $convention ? $convention->scoreFor($gradeColumn) : ($item['score'] ?? null),
                ];

                $previous = isset($item['id']) ? $existing->get($item['id']) : null;
                $previousMembers = $previous ? $previous->members->pluck('id')->all() : [];
                $previousGrade = $previous ? $ownGrades->get($previous->id) : null;
                $gradeChanged = ! $previousGrade
                    ? ($grade['score'] !== null || $grade['convention_id'] !== null)
                    : ((int) $previousGrade->convention_id !== (int) $grade['convention_id']
                        || $this->differentScore($previousGrade->score, $grade['score']));

                if ($previous) {
                    $previous->update(['name' => trim($item['name']), 'sort_order' => $index]);
                    $group = $previous;
                } else {
                    $group = WorkGroup::create(['work_group_set_id' => $set->id, 'name' => trim($item['name']), 'sort_order' => $index]);
                }

                DB::table('work_group_members')->insert(array_map(fn ($studentId) => [
                    'work_group_id' => $group->id,
                    'work_group_set_id' => $set->id,
                    'student_id' => $studentId,
                ], $item['student_ids']));

                WorkGroupGrade::updateOrCreate(['work_group_id' => $group->id, 'grade_column_id' => $gradeColumn->id], $grade);

                $newMembers = array_values(array_diff($item['student_ids'], $previousMembers));
                $hasGrade = $grade['score'] !== null || $grade['convention_id'] !== null;
                // Un integrante nuevo de un grupo sin nota no recibe nada (no se le borra la suya).
                $targets = $gradeChanged ? $item['student_ids'] : ($hasGrade ? $newMembers : []);
                foreach ($targets as $studentId) {
                    $gradeWrites[$gradeColumn->id][$studentId] = [$grade['score'], $grade['convention_id']];
                }

                // En las otras actividades del conjunto, los integrantes nuevos reciben la nota que el grupo ya tenía allí.
                if ($previous && $newMembers !== []) {
                    foreach ($otherGrades->where('work_group_id', $previous->id) as $other) {
                        if ($other->score === null && $other->convention_id === null) {
                            continue;
                        }
                        foreach ($newMembers as $studentId) {
                            $gradeWrites[$other->grade_column_id][$studentId] = [$other->score, $other->convention_id];
                        }
                    }
                }
            }
        });

        // Igual que /grades/bulk: se escribe todo sin observers y se recalcula una vez por estudiante.
        $columns = GradeColumn::findMany(array_keys($gradeWrites))->keyBy('id');
        Grade::withoutEvents(function () use ($gradeWrites, $columns, $request) {
            foreach ($gradeWrites as $columnId => $students) {
                foreach ($students as $studentId => [$score, $conventionId]) {
                    Grade::updateOrCreate(
                        ['student_id' => $studentId, 'grade_column_id' => $columnId],
                        [
                            'group_subject_id' => $columns[$columnId]->group_subject_id,
                            'period_id' => $columns[$columnId]->period_id,
                            'score' => $score,
                            'convention_id' => $conventionId,
                            'registered_by' => $request->user()->id,
                        ]
                    );
                }
            }
        });
        $students = collect($gradeWrites)->flatMap(fn ($byStudent) => array_keys($byStudent))->unique();
        foreach ($students as $studentId) {
            $calculator->recalculateForStudent($studentId, $gradeColumn->group_subject_id, $gradeColumn->period_id);
        }

        return response()->json([
            'data' => self::groupsForColumns(collect([$gradeColumn->fresh()]))[$gradeColumn->id] ?? [],
            'grades_updated' => count($gradeWrites[$gradeColumn->id] ?? []),
        ]);
    }

    /**
     * Para la planilla: { columnId: [{ id, set_id, name, sort_order, score, convention_id, student_ids }] }
     * con la nota de cada grupo en esa actividad.
     *
     * @param  Collection<int, GradeColumn>  $columns
     */
    public static function groupsForColumns(Collection $columns): array
    {
        $linked = $columns->whereNotNull('work_group_set_id');
        if ($linked->isEmpty()) {
            return [];
        }

        $groups = WorkGroup::with('members:students.id')->whereIn('work_group_set_id', $linked->pluck('work_group_set_id')->unique())
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('work_group_set_id');
        $grades = WorkGroupGrade::whereIn('grade_column_id', $linked->pluck('id'))->get()
            ->keyBy(fn (WorkGroupGrade $g) => "{$g->grade_column_id}:{$g->work_group_id}");

        return $linked->mapWithKeys(fn (GradeColumn $column) => [
            $column->id => ($groups->get($column->work_group_set_id) ?? collect())->map(function (WorkGroup $group) use ($column, $grades) {
                $grade = $grades->get("{$column->id}:{$group->id}");

                return [
                    'id' => $group->id,
                    'set_id' => $group->work_group_set_id,
                    'grade_column_id' => $column->id,
                    'name' => $group->name,
                    'sort_order' => $group->sort_order,
                    'score' => $grade?->score,
                    'convention_id' => $grade?->convention_id,
                    'student_ids' => $group->members->pluck('id')->values(),
                ];
            })->values(),
        ])->all();
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
