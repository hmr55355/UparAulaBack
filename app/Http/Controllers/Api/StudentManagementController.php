<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\ParentGuardian;
use App\Models\Student;
use App\Models\StudentGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Estudiantes y matrícula a mano (antes solo entraban por Excel): crear y editar
 * datos, retirar, trasladar de grupo y volver a matricular. Siempre el
 * administrador; los docentes (director del grupo o quien dicta en él) solo si la
 * institución lo habilitó (Group::studentsCanBeManagedBy). Retirar o trasladar no borra nada: la matrícula anterior queda
 * con su estado y fecha, y las notas, asistencia y anotaciones se conservan.
 */
class StudentManagementController extends Controller
{
    /** Matrículas del grupo, incluidas las retiradas y trasladadas (para gestionarlas). */
    public function enrollments(Request $request, Group $group)
    {
        $this->authorizeGroup($request, $group);

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
        $this->authorizeGroup($request, $group);

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
        $this->authorizeStudent($request, $student);

        $validated = $request->validate($this->studentRules($student->institution_id, $student->id, partial: true));
        $student->update($validated);

        return response()->json(['data' => $student->fresh()]);
    }

    /**
     * Eliminar del todo, para registros hechos por error (duplicado, mal digitado).
     * Borra al estudiante con sus matrículas y acudientes sin otros estudiantes. Si ya
     * tiene notas, asistencia u otros registros, se avisa qué se perdería y solo un
     * administrador puede confirmarlo: para los demás casos existe "Retirar".
     */
    public function destroy(Request $request, Student $student)
    {
        // Como editar: admin, o docente habilitado con el estudiante activo en uno de sus grupos.
        $this->authorizeStudent($request, $student);

        $records = $this->recordsOf($student);
        if ($records !== []) {
            $detail = collect($records)->map(fn ($count, $label) => "{$label}: {$count}")->implode(', ');

            abort_unless(
                $request->user()->isAdminOf($student->institution_id),
                422,
                "Este estudiante ya tiene registros ({$detail}). Solo un administrador puede eliminarlo; si se fue del colegio, usa \"Retirar\"."
            );

            if (! $request->boolean('confirm')) {
                return response()->json([
                    'message' => "Este estudiante ya tiene registros ({$detail}). Al eliminarlo se borra todo y no se puede deshacer.",
                    'records' => $records,
                    'requires_confirmation' => true,
                ], 409);
            }
        }

        DB::transaction(function () use ($student) {
            $groupIds = $student->studentGroups()->pluck('group_id');
            $parentIds = $student->parents()->pluck('parents.id');

            // Borrado real (el modelo usa SoftDeletes): las tablas hijas caen en cascada
            // y el número de documento queda libre para volver a registrarlo bien.
            $student->forceDelete();

            ParentGuardian::whereIn('id', $parentIds)->whereDoesntHave('students')->delete();
            // La cascada de la base no dispara StudentGroupObserver.
            Group::findMany($groupIds)->each->refreshStudentCount();
        });

        return response()->json(['message' => 'Estudiante eliminado.']);
    }

    public function withdraw(Request $request, Student $student)
    {
        $this->authorizeGroup($request, Group::findOrFail($request->input('group_id')));

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
        $this->authorizeGroup($request, Group::findOrFail($request->input('from_group_id')));

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
        $this->authorizeGroup($request, Group::findOrFail($request->input('group_id')));

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

    private function authorizeGroup(Request $request, Group $group): void
    {
        abort_unless(
            $group->studentsCanBeManagedBy($request->user()),
            403,
            'No tienes permiso para gestionar los estudiantes de este grupo.'
        );
    }

    /** Editar datos: basta con poder gestionar alguno de los grupos donde está activo (o ser admin). */
    private function authorizeStudent(Request $request, Student $student): void
    {
        $user = $request->user();
        $allowed = $user->isAdminOf($student->institution_id)
            || $student->studentGroups()->where('status', 'activo')->with('group.institution')->get()
                ->contains(fn (StudentGroup $e) => $e->group?->studentsCanBeManagedBy($user));
        abort_unless($allowed, 403, 'No tienes permiso para editar a este estudiante.');
    }

    /**
     * Lo que se perdería al eliminarlo, con su etiqueta. No cuenta lo que el sistema
     * crea solo (matrículas, cobros en "debe", definitivas calculadas).
     *
     * @return array<string, int>
     */
    private function recordsOf(Student $student): array
    {
        $id = $student->id;

        return array_filter([
            'notas' => DB::table('grades')->where('student_id', $id)
                ->where(fn ($q) => $q->whereNotNull('score')->orWhereNotNull('convention_id'))->count(),
            'registros de asistencia' => DB::table('attendance_records')->where('student_id', $id)->count(),
            'participaciones' => DB::table('participations')->where('student_id', $id)->count(),
            'entregas de tareas' => DB::table('homework_deliveries')->where('student_id', $id)->count(),
            'anotaciones de comportamiento' => DB::table('behavior_annotations')->where('student_id', $id)->count(),
            'observaciones' => DB::table('student_observations')->where('student_id', $id)->count(),
            'citaciones' => DB::table('parent_citations')->where('student_id', $id)->count(),
            'pagos registrados' => DB::table('student_copy_payments')->where('student_id', $id)->where('status', '!=', 'debe')->count(),
            'préstamos' => DB::table('resource_loans')->where('student_id', $id)->count(),
        ]);
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
