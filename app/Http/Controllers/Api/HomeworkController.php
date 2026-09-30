<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeSection;
use App\Models\GroupSubject;
use App\Models\Homework;
use App\Models\HomeworkDelivery;
use App\Models\Period;
use App\Models\Student;
use App\Services\GradeCalculatorService;
use App\Services\PerformanceScale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HomeworkController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'groupSubjectId' => ['required', 'integer', 'exists:group_subjects,id'],
            'periodId' => ['required', 'integer', 'exists:periods,id'],
        ]);

        $groupSubject = GroupSubject::findOrFail($request->groupSubjectId);
        $this->authorize('view', $groupSubject);

        $homeworks = Homework::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $request->periodId)
            ->withCount([
                'deliveries as delivered_count' => fn ($q) => $q->whereIn('status', ['entregado', 'entregado_tarde']),
                'deliveries as total_deliveries',
            ])
            ->orderBy('due_date')
            ->get();

        return response()->json(['data' => $homeworks]);
    }

    public function store(Request $request, GradeCalculatorService $calculator)
    {
        $validated = $request->validate([
            'group_subject_id' => ['required', 'integer', 'exists:group_subjects,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:assigned_date'],
            'max_score' => ['sometimes', 'numeric', 'min:1'],
            'is_graded' => ['sometimes', 'boolean'],
            'grade_section_id' => ['required_if:is_graded,true', 'integer', 'exists:grade_sections,id'],
            // automatic: la columna nueva y las que ya tiene la sección quedan con el mismo peso.
            'weight_mode' => ['sometimes', Rule::in(['manual', 'automatic'])],
            'weight' => [
                Rule::requiredIf(fn () => $request->boolean('is_graded') && $request->input('weight_mode', 'manual') === 'manual'),
                'nullable', 'numeric', 'min:0', 'max:100',
            ],
            'notes' => ['nullable', 'string'],
        ]);

        $groupSubject = GroupSubject::findOrFail($validated['group_subject_id']);
        $this->authorize('update', $groupSubject);

        $maxScore = $validated['max_score'] ?? PerformanceScale::maxForInstitution($groupSubject->institution);
        $automaticWeight = ($validated['weight_mode'] ?? 'manual') === 'automatic';
        $gradeColumnId = null;

        if ($validated['is_graded'] ?? false) {
            $section = GradeSection::findOrFail($validated['grade_section_id']);
            abort_unless($section->group_subject_id === $groupSubject->id, 403);

            $gradeColumnId = GradeColumn::create([
                'grade_section_id' => $section->id,
                'group_subject_id' => $section->group_subject_id,
                'period_id' => $section->period_id,
                'column_type' => 'manual',
                'name' => $validated['title'],
                'weight' => $automaticWeight ? 0 : $validated['weight'],
                'max_score' => $maxScore,
                'sort_order' => $section->columns()->count(),
            ])->id;

            if ($automaticWeight) {
                $section->distributeWeightsEqually();
                $calculator->recalculateCourse($groupSubject->id, $section->period_id);
            }
        }

        $homework = Homework::create([
            'group_subject_id' => $groupSubject->id,
            'period_id' => $validated['period_id'],
            'registered_by' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'assigned_date' => $validated['assigned_date'],
            'due_date' => $validated['due_date'],
            'max_score' => $maxScore,
            'is_graded' => $validated['is_graded'] ?? false,
            'grade_column_id' => $gradeColumnId,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(['data' => $homework], 201);
    }

    public function update(Request $request, Homework $homework)
    {
        $this->authorize('update', $homework->groupSubject);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_date' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'date'],
            'max_score' => ['sometimes', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        $homework->update($validated);

        // La columna que genera la tarea en la planilla sigue su nombre y su nota máxima.
        if ($homework->grade_column_id) {
            $columnChanges = array_filter([
                'name' => $validated['title'] ?? null,
                'max_score' => $validated['max_score'] ?? null,
            ], fn ($value) => $value !== null);
            if ($columnChanges) {
                GradeColumn::where('id', $homework->grade_column_id)->update($columnChanges);
            }
        }

        return response()->json(['data' => $homework->fresh()]);
    }

    public function destroy(Request $request, Homework $homework, GradeCalculatorService $calculator)
    {
        $this->authorize('update', $homework->groupSubject);

        if ($homework->grade_column_id) {
            $hasGrades = Grade::where('grade_column_id', $homework->grade_column_id)->exists();

            if ($hasGrades && ! $request->boolean('confirm')) {
                return response()->json([
                    'message' => 'Esta tarea tiene notas registradas en la planilla. Confirma la eliminación.',
                    'requires_confirmation' => true,
                ], 409);
            }

            GradeColumn::where('id', $homework->grade_column_id)->delete();
            $homework->delete();

            // Sin esa columna, las definitivas del curso cambian: se recalculan ya,
            // o quedarían mostrando valores que incluían las notas de la tarea borrada.
            $calculator->recalculateCourse($homework->group_subject_id, $homework->period_id);

            return response()->json(['message' => 'Tarea eliminada.']);
        }

        $homework->delete();

        return response()->json(['message' => 'Tarea eliminada.']);
    }

    public function deliveries(Request $request, Homework $homework)
    {
        $this->authorize('view', $homework->groupSubject);

        $students = Student::whereHas(
            'studentGroups',
            fn ($q) => $q->where('group_id', $homework->groupSubject->group_id)->where('status', 'activo')
        )->orderBy('last_name')->orderBy('first_name')->get();

        $deliveries = HomeworkDelivery::where('homework_id', $homework->id)->get()->keyBy('student_id');

        return response()->json([
            'homework' => $homework,
            'students' => $students,
            'deliveries' => $deliveries,
        ]);
    }

    public function bulkDeliveries(Request $request, Homework $homework, GradeCalculatorService $calculator)
    {
        $this->authorize('update', $homework->groupSubject);
        // Las entregas de una tarea con nota escriben en la planilla: con el período cerrado, no.
        abort_if(
            $homework->is_graded && Period::whereKey($homework->period_id)->value('is_closed'),
            422,
            'El período está cerrado. Las notas no se pueden editar.'
        );

        $validated = $request->validate([
            'deliveries' => ['required', 'array', 'min:1'],
            'deliveries.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'deliveries.*.status' => ['required', 'in:entregado,no_entregado,entregado_tarde,excusado'],
            'deliveries.*.delivery_date' => ['nullable', 'date'],
            'deliveries.*.score' => ['nullable', 'numeric', 'min:1'],
            'deliveries.*.notes' => ['nullable', 'string'],
        ]);

        $gradedStudentIds = [];

        // withoutEvents: como en GradeController::bulk, guardamos todas las notas del
        // taller sin disparar GradeObserver por fila, y recalculamos una sola vez por
        // estudiante único al final en vez de una cascada completa por cada entrega.
        $saved = Grade::withoutEvents(function () use ($validated, $homework, $request, &$gradedStudentIds) {
            return collect($validated['deliveries'])->map(function (array $item) use ($homework, $request, &$gradedStudentIds) {
                $delivery = HomeworkDelivery::updateOrCreate(
                    ['homework_id' => $homework->id, 'student_id' => $item['student_id']],
                    [
                        'status' => $item['status'],
                        'delivery_date' => $item['delivery_date'] ?? null,
                        'score' => $item['score'] ?? null,
                        'notes' => $item['notes'] ?? null,
                    ]
                );

                if ($homework->is_graded && $homework->grade_column_id && ($item['score'] ?? null) !== null) {
                    Grade::updateOrCreate(
                        ['student_id' => $item['student_id'], 'grade_column_id' => $homework->grade_column_id],
                        [
                            'group_subject_id' => $homework->group_subject_id,
                            'period_id' => $homework->period_id,
                            'score' => $item['score'],
                            'convention_id' => null,
                            'registered_by' => $request->user()->id,
                        ]
                    );
                    $gradedStudentIds[] = $item['student_id'];
                }

                return $delivery;
            });
        });

        foreach (array_unique($gradedStudentIds) as $studentId) {
            $calculator->recalculateForStudent($studentId, $homework->group_subject_id, $homework->period_id);
        }

        return response()->json(['data' => $saved, 'count' => $saved->count()], 201);
    }

}
