<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\GradeConvention;
use App\Services\GradeCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Convenciones de calificación del docente. Son personales: cada docente ve y
 * edita solo las suyas, y la planilla de un curso usa las del docente del curso.
 */
class GradeConventionController extends Controller
{
    /** Las que se cargan con "Agregar sugeridas" (sin nota: el docente decide cuánto vale cada una). */
    private const SUGGESTED = [
        ['code' => 'NP', 'label' => 'No presentó'],
        ['code' => 'NA', 'label' => 'No asistió'],
        ['code' => 'Su', 'label' => 'Suspendido'],
        ['code' => 'Ok', 'label' => 'Entregado'],
        ['code' => 'Rg', 'label' => 'Regular'],
        ['code' => 'Pe', 'label' => 'Pendiente'],
        ['code' => 'Op', 'label' => 'Oportunidad'],
        ['code' => '✓', 'label' => 'Entregado'],
        ['code' => '✗', 'label' => 'No entregado'],
        ['code' => '☺', 'label' => 'Entregado'],
        ['code' => '☹', 'label' => 'No entregado'],
    ];

    public function index(Request $request)
    {
        return response()->json(['data' => $this->conventionsOf($request->user()->id)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules($request));

        $convention = GradeConvention::create($validated + [
            'user_id' => $request->user()->id,
            'sort_order' => GradeConvention::where('user_id', $request->user()->id)->max('sort_order') + 1,
        ]);

        return response()->json(['data' => $convention], 201);
    }

    public function storeSuggested(Request $request)
    {
        $userId = $request->user()->id;
        $existing = GradeConvention::where('user_id', $userId)->pluck('code')->map(fn ($c) => mb_strtolower($c));
        $order = (int) GradeConvention::where('user_id', $userId)->max('sort_order');

        foreach (self::SUGGESTED as $suggestion) {
            if (! $existing->contains(mb_strtolower($suggestion['code']))) {
                GradeConvention::create($suggestion + ['user_id' => $userId, 'sort_order' => ++$order]);
            }
        }

        return response()->json(['data' => $this->conventionsOf($userId)], 201);
    }

    /**
     * Si cambia el valor, las notas ya puestas con esta convención se actualizan y
     * se recalculan las definitivas de los estudiantes afectados.
     */
    public function update(Request $request, GradeConvention $gradeConvention, GradeCalculatorService $calculator)
    {
        abort_unless($gradeConvention->user_id === $request->user()->id, 403);

        $validated = $request->validate($this->rules($request, $gradeConvention));
        $valueChanged = array_key_exists('value', $validated)
            && $this->differs($validated['value'], $gradeConvention->value);

        $gradeConvention->update($validated);

        if ($valueChanged) {
            $affected = [];
            Grade::withoutEvents(function () use ($gradeConvention, &$affected) {
                // Las notas de períodos cerrados conservan el valor con que se cerraron.
                $gradeConvention->grades()->whereHas('period', fn ($q) => $q->where('is_closed', false))
                    ->with('gradeColumn')->get()->each(function (Grade $grade) use ($gradeConvention, &$affected) {
                    $grade->update(['score' => $gradeConvention->scoreFor($grade->gradeColumn)]);
                    $affected["{$grade->student_id}:{$grade->group_subject_id}:{$grade->period_id}"] =
                        [$grade->student_id, $grade->group_subject_id, $grade->period_id];
                });
            });
            foreach ($affected as [$studentId, $groupSubjectId, $periodId]) {
                $calculator->recalculateForStudent($studentId, $groupSubjectId, $periodId);
            }
        }

        return response()->json(['data' => $gradeConvention->fresh()]);
    }

    public function destroy(Request $request, GradeConvention $gradeConvention)
    {
        abort_unless($gradeConvention->user_id === $request->user()->id, 403);

        $used = $gradeConvention->grades()->count();
        abort_if($used > 0, 422, "Esta convención está puesta en {$used} ".($used === 1 ? 'nota' : 'notas').'. Cámbialas antes de eliminarla.');

        $gradeConvention->delete();

        return response()->json(['message' => 'Convención eliminada.']);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        DB::transaction(function () use ($validated, $request) {
            foreach ($validated['ids'] as $index => $id) {
                GradeConvention::where('id', $id)->where('user_id', $request->user()->id)->update(['sort_order' => $index]);
            }
        });

        return response()->json(['data' => $this->conventionsOf($request->user()->id)]);
    }

    private function conventionsOf(int $userId)
    {
        return GradeConvention::where('user_id', $userId)->orderBy('sort_order')->orderBy('id')->get();
    }

    private function rules(Request $request, ?GradeConvention $convention = null): array
    {
        $required = $convention ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:10',
                Rule::unique('grade_conventions')->where('user_id', $request->user()->id)->ignore($convention?->id)],
            'label' => [$required, 'string', 'max:100'],
            'value' => ['nullable', 'numeric', 'min:1', 'max:10'],
        ];
    }

    private function differs(mixed $new, mixed $old): bool
    {
        if ($new === null || $old === null) {
            return $new !== $old;
        }

        return round((float) $new, 1) !== round((float) $old, 1);
    }
}
