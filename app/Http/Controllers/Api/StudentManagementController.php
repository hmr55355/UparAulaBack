<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Student;
use App\Models\StudentGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Estudiantes y matrícula a mano (antes solo entraban por Excel): crear y editar
 * datos, retirar, trasladar de grupo y volver a matricular. Solo administradores
 * (manageAcademics). Retirar o trasladar no borra nada: la matrícula anterior queda
 * con su estado y fecha, y las notas, asistencia y anotaciones se conservan.
 */
class StudentManagementController extends Controller
{
    /** Matrículas del grupo, incluidas las retiradas y trasladadas (para gestionarlas). */
    public function enrollments(Group $group)
    {
        $this->authorize('manageAcademics', $group->institution);

        $enrollments = $group->studentGroups()
            ->with('student')
            ->whereHas('student')
            ->get()
            ->sortBy(fn (StudentGroup $e) => [$e->status !== 'activo', $e->student->last_name, $e->student->first_name])
            ->values();

        return response()->json(['data' => $enrollments]);
    }

    public function store(Request $request)
    {
        $group = Group::findOrFail($request->input('group_id'));
        $this->authorize('manageAcademics', $group->institution);

        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
            'enrollment_date' => ['nullable', 'date'],
            ...$this->studentRules($group->institution_id),
        ]);

        $student = DB::transaction(function () use ($validated, $group) {
            $student = Student::create([
                ...collect($validated)->except(['group_id', 'enrollment_date'])->all(),
                'institution_id' => $group->institution_id,
            ]);
            StudentGroup::create([
                'student_id' => $student->id,
                'group_id' => $group->id,
                'academic_year_id' => $group->academic_year_id,
                'enrollment_date' => $validated['enrollment_date'] ?? now()->toDateString(),
                'status' => 'activo',
            ]);

            return $student;
        });

        return response()->json(['data' => $student], 201);
    }

    public function update(Request $request, Student $student)
    {
        $this->authorize('manageAcademics', $student->institution);

        $validated = $request->validate($this->studentRules($student->institution_id, $student->id, partial: true));
        $student->update($validated);

        return response()->json(['data' => $student->fresh()]);
    }

    public function withdraw(Request $request, Student $student)
    {
        $this->authorize('manageAcademics', $student->institution);

        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
            'withdrawal_date' => ['required', 'date'],
            'withdrawal_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $enrollment = $this->activeEnrollment($student, (int) $validated['group_id']);
        $enrollment->update([
            'status' => 'retirado',
            'withdrawal_date' => $validated['withdrawal_date'],
            'withdrawal_reason' => $validated['withdrawal_reason'] ?? null,
        ]);
        $this->syncActiveFlag($student);

        return response()->json(['data' => $enrollment->fresh()]);
    }

    /**
     * Traslado a otro grupo del mismo año: la matrícula actual queda "trasladado"
     * (con fecha) y se abre una nueva en el grupo de destino. Sus notas del grupo
     * anterior no se mueven: cada curso es de un grupo.
     */
    public function transfer(Request $request, Student $student)
    {
        $this->authorize('manageAcademics', $student->institution);

        $validated = $request->validate([
            'from_group_id' => ['required', 'integer'],
            'to_group_id' => ['required', 'integer', 'different:from_group_id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $enrollment = $this->activeEnrollment($student, (int) $validated['from_group_id']);
        $target = Group::where('institution_id', $student->institution_id)->findOrFail($validated['to_group_id']);
        if ($target->academic_year_id !== $enrollment->academic_year_id) {
            throw ValidationException::withMessages(['to_group_id' => ['Solo se puede trasladar a un grupo del mismo año escolar.']]);
        }

        $new = DB::transaction(function () use ($enrollment, $student, $target, $validated) {
            $enrollment->update([
                'status' => 'trasladado',
                'withdrawal_date' => $validated['date'],
                'withdrawal_reason' => $validated['reason'] ?? "Traslado al grupo {$target->name}",
            ]);

            return $this->enrollIn($student, $target, $validated['date']);
        });

        return response()->json(['data' => $new], 201);
    }

    /** Volver a matricular (p. ej. un estudiante retirado que regresa). */
    public function enroll(Request $request, Student $student)
    {
        $this->authorize('manageAcademics', $student->institution);

        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
            'enrollment_date' => ['required', 'date'],
        ]);
        $group = Group::where('institution_id', $student->institution_id)->findOrFail($validated['group_id']);

        $alreadyActive = $student->studentGroups()
            ->where('academic_year_id', $group->academic_year_id)
            ->where('status', 'activo')
            ->with('group:id,name')
            ->first();
        if ($alreadyActive) {
            throw ValidationException::withMessages([
                'group_id' => ["Ya está matriculado en {$alreadyActive->group->name} este año. Usa \"Trasladar\"."],
            ]);
        }

        $enrollment = $this->enrollIn($student, $group, $validated['enrollment_date']);
        $this->syncActiveFlag($student);

        return response()->json(['data' => $enrollment], 201);
    }

    /**
     * Matrícula activa en el grupo. Si ya existió una (retirada o trasladada) en ese
     * mismo grupo, se reactiva en vez de duplicarla.
     */
    private function enrollIn(Student $student, Group $group, string $date): StudentGroup
    {
        $existing = StudentGroup::where('student_id', $student->id)->where('group_id', $group->id)->first();
        if ($existing) {
            $existing->update(['status' => 'activo', 'enrollment_date' => $date, 'withdrawal_date' => null, 'withdrawal_reason' => null]);

            return $existing->fresh();
        }

        return StudentGroup::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'academic_year_id' => $group->academic_year_id,
            'enrollment_date' => $date,
            'status' => 'activo',
        ]);
    }

    /** students.is_active refleja si le queda alguna matrícula activa (lo muestra el perfil). */
    private function syncActiveFlag(Student $student): void
    {
        $student->update(['is_active' => $student->studentGroups()->where('status', 'activo')->exists()]);
    }

    private function activeEnrollment(Student $student, int $groupId): StudentGroup
    {
        $enrollment = $student->studentGroups()->where('group_id', $groupId)->where('status', 'activo')->first();
        abort_unless($enrollment, 422, 'El estudiante no tiene una matrícula activa en ese grupo.');

        return $enrollment;
    }

    private function studentRules(int $institutionId, ?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'first_name' => [$required, 'string', 'max:255'],
            'last_name' => [$required, 'string', 'max:255'],
            'document_type' => [$partial ? 'sometimes' : 'nullable', Rule::in(['TI', 'CC', 'CE', 'PA', 'PPT'])],
            // Mismo documento dos veces en la institución = estudiante duplicado.
            'document_number' => ['nullable', 'string', 'max:30',
                Rule::unique('students')->where('institution_id', $institutionId)->whereNull('deleted_at')->ignore($ignoreId)],
            'birthdate' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['masculino', 'femenino', 'otro'])],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
