<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Institution\AssignCourseRequest;
use App\Http\Requests\Institution\CreateTeacherRequest;
use App\Http\Requests\Institution\InviteTeacherRequest;
use App\Http\Requests\Institution\StoreInstitutionRequest;
use App\Http\Resources\InstitutionResource;
use App\Http\Resources\TeacherResource;
use App\Models\AcademicYear;
use App\Models\AppNotification;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\Subject;
use App\Models\Group;
use App\Models\InstitutionTeacher;
use App\Services\PerformanceScale;
use App\Models\Period;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstitutionController extends Controller
{
    public function search(Request $request)
    {
        $request->validate(['name' => ['nullable', 'string'], 'nit' => ['nullable', 'string']]);

        $institutions = Institution::query()
            ->when($request->name, fn ($q) => $q->where('name', 'like', "%{$request->name}%"))
            ->when($request->nit, fn ($q) => $q->where('nit', $request->nit))
            ->limit(20)
            ->get(['id', 'name', 'city', 'department', 'nit']);

        return response()->json(['data' => $institutions]);
    }

    public function current(Request $request)
    {
        $membership = $request->user()->institutionTeachers()->where('status', 'active')->first();

        if (! $membership) {
            return response()->json(['message' => 'No perteneces a ninguna institución todavía.'], 404);
        }

        return new InstitutionResource($membership->institution);
    }

    public function store(StoreInstitutionRequest $request)
    {
        $institution = DB::transaction(function () use ($request) {
            $institution = Institution::create([
                'name' => $request->name,
                'city' => $request->city,
                'department' => $request->department,
                'nit' => $request->nit,
                'rector' => $request->rector,
                'grading_scale' => $request->input('grading_scale', '1_to_10'),
                'min_passing_grade' => $request->input('min_passing_grade', 6.0),
                'created_by' => $request->user()->id,
            ]);

            InstitutionTeacher::create([
                'institution_id' => $institution->id,
                'user_id' => $request->user()->id,
                'role' => 'admin',
                'status' => 'active',
                'accepted_at' => now(),
            ]);

            $academicYear = AcademicYear::create([
                'institution_id' => $institution->id,
                'year' => $request->academic_year,
                'is_active' => true,
                'start_date' => $request->academic_year_start,
                'end_date' => $request->academic_year_end,
            ]);

            $this->createDefaultPeriods($academicYear);
            // Toda institución arranca con una jornada; el admin puede renombrarla o agregar más.
            $institution->shifts()->create(['name' => 'Jornada única']);
            // Y con la escala de valoración sugerida; el admin la ajusta a su SIEE.
            foreach (PerformanceScale::defaultsFor($institution->grading_scale, (float) $institution->min_passing_grade) as $index => $level) {
                $institution->performanceLevels()->create($level + ['sort_order' => $index]);
            }

            return $institution;
        });

        return new InstitutionResource($institution);
    }

    public function update(Request $request, Institution $institution)
    {
        $this->authorize('update', $institution);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'department' => ['sometimes', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:50'],
            'rector' => ['nullable', 'string', 'max:255'],
            'logo' => ['sometimes', 'file', 'image', 'max:2048'],
        ]);
        // La escala y la nota mínima se cambian solo desde PUT …/performance-levels:
        // la nota mínima sale de la escala y no deben quedar desincronizadas.

        if ($request->hasFile('logo')) {
            if ($institution->logo) {
                Storage::disk('local')->delete($institution->logo);
            }
            $validated['logo'] = $request->file('logo')->store("private/institutions/{$institution->id}", 'local');
        }

        $institution->update($validated);

        return new InstitutionResource($institution);
    }

    /**
     * Sirve el logo institucional (disco privado, mismo patrón que
     * VoiceNoteController::show) — cualquier miembro activo puede verlo.
     */
    public function logo(Request $request, Institution $institution)
    {
        abort_unless($request->user()->isActiveMemberOf($institution->id), 403);
        abort_if(! $institution->logo, 404);

        return Storage::disk('local')->response($institution->logo);
    }

    public function teachers(Institution $institution)
    {
        $this->authorize('manageTeachers', $institution);

        $teachers = $institution->teachers()->with('user')->orderBy('id')->paginate(50);

        return TeacherResource::collection($teachers);
    }

    /**
     * Crea la cuenta del docente directamente (a diferencia de inviteTeacher,
     * que exige que el usuario ya exista) y lo deja activo de inmediato —
     * el admin ya está vouching por la cuenta, no hace falta que nadie la
     * acepte.
     */
    public function createTeacher(CreateTeacherRequest $request, Institution $institution)
    {
        $this->authorize('manageTeachers', $institution);

        $membership = DB::transaction(function () use ($request, $institution) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            return InstitutionTeacher::create([
                'institution_id' => $institution->id,
                'user_id' => $user->id,
                'role' => $request->input('role', 'teacher'),
                'status' => 'active',
                'invited_by' => $request->user()->id,
                'invited_at' => now(),
                'accepted_at' => now(),
            ]);
        });

        return new TeacherResource($membership->load('user'));
    }

    public function inviteTeacher(InviteTeacherRequest $request, Institution $institution)
    {
        $this->authorize('manageTeachers', $institution);

        $invitedUser = User::where('email', $request->email)->first();

        if (! $invitedUser) {
            return response()->json([
                'message' => 'No existe un usuario registrado con ese correo. Pídele que cree su cuenta en UparAula primero.',
            ], 422);
        }

        $membership = InstitutionTeacher::updateOrCreate(
            ['institution_id' => $institution->id, 'user_id' => $invitedUser->id],
            [
                'role' => $request->input('role', 'teacher'),
                'status' => 'pending',
                'invitation_token' => Str::uuid()->toString(),
                'invited_by' => $request->user()->id,
                'invited_at' => now(),
            ]
        );

        Mail::raw(
            "Has sido invitado a unirte a {$institution->name} en UparAula. Ingresa a la app y acepta la invitación con el código: {$membership->invitation_token}",
            fn ($message) => $message->to($invitedUser->email)
                ->subject('Invitación a UparAula')
                ->from('noreply@uparaula.com', 'UparAula')
        );

        return new TeacherResource($membership->load('user'));
    }

    public function updateTeacherRole(Request $request, Institution $institution, int $userId)
    {
        $this->authorize('manageTeachers', $institution);

        $request->validate(['role' => ['required', 'in:admin,teacher']]);

        $membership = InstitutionTeacher::where('institution_id', $institution->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $membership->update(['role' => $request->role]);

        Log::info('AUDIT: institution_teacher role changed', [
            'institution_id' => $institution->id,
            'target_user_id' => $userId,
            'new_role' => $request->role,
            'changed_by' => $request->user()->id,
        ]);

        return new TeacherResource($membership->load('user'));
    }

    public function removeTeacher(Request $request, Institution $institution, int $userId)
    {
        $this->authorize('manageTeachers', $institution);

        $membership = InstitutionTeacher::where('institution_id', $institution->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $membership->delete();

        Log::info('AUDIT: institution_teacher removed', [
            'institution_id' => $institution->id,
            'target_user_id' => $userId,
            'removed_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Docente removido de la institución.']);
    }

    public function join(Request $request)
    {
        $request->validate(['institution_id' => ['required', 'integer', 'exists:institutions,id']]);

        $institution = Institution::findOrFail($request->institution_id);

        $membership = InstitutionTeacher::updateOrCreate(
            ['institution_id' => $institution->id, 'user_id' => $request->user()->id],
            ['role' => 'teacher', 'status' => 'pending']
        );

        $adminIds = $institution->teachers()->where('role', 'admin')->where('status', 'active')->pluck('user_id');
        foreach ($adminIds as $adminId) {
            AppNotification::create([
                'user_id' => $adminId,
                'type' => 'institution_join_request',
                'title' => 'Nueva solicitud de ingreso',
                'body' => "{$request->user()->name} solicitó unirse a {$institution->name}.",
                'priority' => 'info',
            ]);
        }

        return new TeacherResource($membership);
    }

    public function acceptInvitation(Request $request)
    {
        $request->validate(['token' => ['required', 'string']]);

        $membership = InstitutionTeacher::where('invitation_token', $request->token)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'Código de invitación inválido.'], 422);
        }

        $membership->update([
            'status' => 'active',
            'accepted_at' => now(),
            'invitation_token' => null,
        ]);

        return new TeacherResource($membership->load('institution'));
    }

    public function assignmentGrid(Institution $institution)
    {
        $this->authorize('manageAcademics', $institution);

        $academicYear = AcademicYear::where('institution_id', $institution->id)->where('is_active', true)->first();

        $groups = $institution->groups()->where('academic_year_id', $academicYear?->id)
            ->orderBy('grade_level')->orderBy('name')
            ->get(['id', 'name', 'grade_level', 'section', 'grade_level_id', 'shift_id']);
        $subjects = $institution->subjects()->with('gradeLevels:id')->orderBy('name')->get(['id', 'name', 'color'])
            ->map(fn ($subject) => [
                'id' => $subject->id,
                'name' => $subject->name,
                'color' => $subject->color,
                'grade_level_ids' => $subject->gradeLevels->pluck('id'),
            ]);

        // Combinaciones grupo + materia que tienen sentido: la materia debe estar
        // vinculada al grado del grupo (un grupo sin grado acepta cualquier materia).
        $availablePairs = $groups->flatMap(fn ($group) => $subjects
            ->filter(fn ($subject) => $group->grade_level_id === null || $subject['grade_level_ids']->contains($group->grade_level_id))
            ->map(fn ($subject) => ['group_id' => $group->id, 'subject_id' => $subject['id']])
        )->values();

        $assignments = GroupSubject::where('institution_id', $institution->id)
            ->where('academic_year_id', $academicYear?->id)
            ->where('is_active', true)
            ->with('teacher:id,name')
            ->get(['id', 'group_id', 'subject_id', 'user_id']);

        return response()->json([
            'groups' => $groups,
            'subjects' => $subjects,
            'assignments' => $assignments,
            'available_pairs' => $availablePairs,
            'grade_levels' => $institution->gradeLevels()->get(['id', 'name', 'level']),
            'shifts' => $institution->shifts()->get(['id', 'name']),
        ]);
    }

    public function assignCourse(AssignCourseRequest $request, Institution $institution)
    {
        $this->authorize('manageAcademics', $institution);

        $group = Group::findOrFail($request->group_id);
        abort_if(
            $group->grade_level_id !== null
                && ! Subject::whereKey($request->subject_id)->whereHas('gradeLevels', fn ($q) => $q->whereKey($group->grade_level_id))->exists(),
            422,
            'Esta materia no está vinculada al grado del grupo. Vincúlala en Institución → Materias.'
        );

        $groupSubject = GroupSubject::updateOrCreate(
            [
                'group_id' => $request->group_id,
                'subject_id' => $request->subject_id,
                'academic_year_id' => $request->academic_year_id,
            ],
            [
                'user_id' => $request->user_id,
                'institution_id' => $institution->id,
                'is_active' => true,
            ]
        );

        return response()->json(['data' => $groupSubject->load('teacher:id,name')]);
    }

    public function unassignCourse(Request $request, Institution $institution)
    {
        $this->authorize('manageAcademics', $institution);

        $request->validate([
            'group_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'academic_year_id' => ['required', 'integer'],
        ]);

        GroupSubject::where('institution_id', $institution->id)
            ->where('group_id', $request->group_id)
            ->where('subject_id', $request->subject_id)
            ->where('academic_year_id', $request->academic_year_id)
            ->delete();

        return response()->json(['message' => 'Asignación removida.']);
    }

    private function createDefaultPeriods(AcademicYear $academicYear): void
    {
        $names = ['Primer Período', 'Segundo Período', 'Tercer Período', 'Cuarto Período'];
        $totalDays = $academicYear->start_date->diffInDays($academicYear->end_date);
        $chunk = intdiv($totalDays, 4);

        foreach ($names as $index => $name) {
            $start = $academicYear->start_date->copy()->addDays($chunk * $index);
            $end = $index === 3
                ? $academicYear->end_date->copy()
                : $academicYear->start_date->copy()->addDays($chunk * ($index + 1) - 1);

            Period::create([
                'academic_year_id' => $academicYear->id,
                'number' => $index + 1,
                'name' => $name,
                'start_date' => $start,
                'end_date' => $end,
                'is_active' => $index === 0,
            ]);
        }
    }
}
