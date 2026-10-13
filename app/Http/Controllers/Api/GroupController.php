<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $institution = $this->resolveInstitution($request);

        $groups = $institution->groups()
            ->when($request->query('academicYearId'), fn ($q, $id) => $q->where('academic_year_id', $id))
            ->when($request->query('shiftId'), fn ($q, $id) => $q->where('shift_id', $id))
            ->with(['shift:id,name', 'director:id,name'])
            ->orderBy('grade_level')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $groups]);
    }

    public function store(Request $request)
    {
        $institution = $this->resolveInstitution($request);
        $this->authorize('manageAcademics', $institution);

        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'name' => ['required', 'string', 'max:255'],
            'grade_level_id' => ['required_without:grade_level', 'nullable', 'integer', Rule::exists('grade_levels', 'id')->where('institution_id', $institution->id)],
            'grade_level' => ['required_without:grade_level_id', 'nullable', 'string', 'max:50'],
            'shift_id' => ['nullable', 'integer', Rule::exists('shifts', 'id')->where('institution_id', $institution->id)],
            'section' => ['nullable', 'string', 'max:50'],
        ]);

        $validated['shift_id'] ??= $institution->shifts()->value('id');
        $group = $institution->groups()->create($this->withLegacyGradeLevel($validated));

        return response()->json(['data' => $group->load('shift:id,name')], 201);
    }

    public function update(Request $request, Group $group)
    {
        $this->authorize('manageAcademics', $group->institution);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'grade_level_id' => ['sometimes', 'nullable', 'integer', Rule::exists('grade_levels', 'id')->where('institution_id', $group->institution_id)],
            'grade_level' => ['sometimes', 'string', 'max:50'],
            'shift_id' => ['sometimes', 'integer', Rule::exists('shifts', 'id')->where('institution_id', $group->institution_id)],
            'section' => ['nullable', 'string', 'max:50'],
            // Director de grupo: debe ser un docente activo de la institución (null lo quita).
            'director_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('institution_teachers', 'user_id')->where('institution_id', $group->institution_id)->where('status', 'active')],
        ]);

        $group->update($this->withLegacyGradeLevel($validated));

        return response()->json(['data' => $group->load(['shift:id,name', 'director:id,name'])]);
    }

    /**
     * `groups.grade_level` (texto) se conserva por compatibilidad con reportes y
     * exportes: al elegir un grado, se llena con su número (o su nombre).
     */
    private function withLegacyGradeLevel(array $validated): array
    {
        if (! empty($validated['grade_level_id'])) {
            $gradeLevel = GradeLevel::find($validated['grade_level_id']);
            $validated['grade_level'] = $gradeLevel->level !== null ? (string) $gradeLevel->level : $gradeLevel->name;
        }

        return $validated;
    }

    /**
     * Solo se borra un grupo vacío (p. ej. creado por error). Borrarlo arrastra en
     * cascada, sin papelera, todo lo que cuelga de él en la base: matrículas, cursos
     * (y con ellos notas y asistencia), cobros, anotaciones, citaciones y observaciones.
     */
    public function destroy(Group $group)
    {
        $this->authorize('manageAcademics', $group->institution);

        $dependencies = array_filter([
            'estudiantes matriculados' => $group->studentGroups()->count(),
            'cursos asignados' => $group->groupSubjects()->count(),
            'cobros de copias' => DB::table('copy_charges')->where('group_id', $group->id)->count(),
            'anotaciones' => DB::table('behavior_annotations')->where('group_id', $group->id)->count(),
            'citaciones' => DB::table('parent_citations')->where('group_id', $group->id)->count(),
            'observaciones' => DB::table('student_observations')->where('group_id', $group->id)->count(),
        ]);
        if ($dependencies) {
            $detail = collect($dependencies)->map(fn ($count, $what) => "{$count} {$what}")->implode(', ');
            abort(422, "No se puede eliminar el grupo {$group->name}: tiene {$detail}. Solo se pueden eliminar grupos vacíos.");
        }

        $group->delete();

        return response()->json(['message' => 'Grupo eliminado.']);
    }

    /**
     * Grupos donde puedo gestionar estudiantes o que dirijo (para las páginas
     * "Estudiantes" y "Dirección de grupo"): todos si soy admin; si soy docente,
     * los que dirijo y aquellos donde dicto.
     */
    public function mine(Request $request)
    {
        $institution = $this->resolveInstitution($request);
        $user = $request->user();
        $isAdmin = $user->isAdminOf($institution->id);

        $groups = $institution->groups()
            ->whereHas('academicYear', fn ($q) => $q->where('is_active', true))
            ->when(! $isAdmin, fn ($q) => $q->where(fn ($w) => $w
                ->where('director_id', $user->id)
                ->orWhereHas('groupSubjects', fn ($gs) => $gs->where('user_id', $user->id)->where('is_active', true))))
            ->with(['shift:id,name', 'director:id,name'])
            ->orderBy('grade_level')->orderBy('name')
            ->get()
            ->map(fn (Group $group) => [
                ...$group->toArray(),
                'is_director' => $group->isDirectedBy($user),
                'can_oversee' => $isAdmin || $group->isDirectedBy($user),
                'can_manage_students' => $isAdmin || ($institution->teachers_manage_students),
            ]);

        return response()->json(['data' => $groups]);
    }

    public function students(Group $group)
    {
        $this->authorize('view', $group->institution);

        $students = $group->studentGroups()
            ->where('status', 'activo')
            ->with('student')
            ->get()
            ->pluck('student');

        return response()->json(['data' => $students]);
    }

    private function resolveInstitution(Request $request): Institution
    {
        $institutionId = $request->query('institutionId') ?? $request->input('institution_id');

        if ($institutionId) {
            return Institution::findOrFail($institutionId);
        }

        return $request->user()->institutionTeachers()->where('status', 'active')->firstOrFail()->institution;
    }
}
