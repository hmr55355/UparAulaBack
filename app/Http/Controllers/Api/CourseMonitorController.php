<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\CourseMonitor;
use App\Models\GroupSubject;
use App\Models\MonitorSubmission;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\User;
use App\Services\MonitorReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Lado del docente: habilitar monitores en sus cursos y revisar (aprobar o
 * rechazar) lo que ellos registran.
 */
class CourseMonitorController extends Controller
{
    public function __construct(private MonitorReviewService $reviews) {}

    public function index(GroupSubject $groupSubject)
    {
        $this->authorize('update', $groupSubject);

        $monitors = CourseMonitor::where('group_subject_id', $groupSubject->id)
            ->with(['user:id,name,username', 'student:id,first_name,last_name'])
            ->withCount(['submissions as pending_count' => fn ($q) => $q->where('status', 'pending')])
            ->latest()
            ->get();

        return response()->json(['data' => $monitors]);
    }

    public function store(Request $request, GroupSubject $groupSubject)
    {
        $this->authorize('update', $groupSubject);

        $enrolled = StudentGroup::where('group_id', $groupSubject->group_id)->where('status', 'activo')->pluck('student_id')->all();
        $validated = $request->validate([
            'student_id' => ['required', 'integer', Rule::in($enrolled)],
            'username' => ['required', 'string', 'min:4', 'max:60', 'alpha_dash', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'min:6', 'max:100'],
        ], [
            'student_id.in' => 'El estudiante no pertenece a este curso.',
            'username.unique' => 'Ese usuario ya existe. Elige otro.',
        ]);

        $student = Student::findOrFail($validated['student_id']);

        $monitor = DB::transaction(function () use ($validated, $student, $groupSubject, $request) {
            $user = User::create([
                'name' => trim("{$student->first_name} {$student->last_name}"),
                'username' => mb_strtolower($validated['username']),
                'password' => Hash::make($validated['password']),
                'account_type' => 'monitor',
            ]);

            return CourseMonitor::create([
                'group_subject_id' => $groupSubject->id,
                'user_id' => $user->id,
                'student_id' => $student->id,
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json(['data' => $monitor->load(['user:id,name,username', 'student:id,first_name,last_name'])], 201);
    }

    public function update(Request $request, CourseMonitor $courseMonitor)
    {
        $this->authorize('update', $courseMonitor->groupSubject);

        $validated = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', 'min:6', 'max:100'],
        ]);

        if (array_key_exists('is_active', $validated)) {
            $courseMonitor->update(['is_active' => $validated['is_active']]);
            // Desactivado: cerrarle la sesión de inmediato.
            if (! $validated['is_active']) {
                $courseMonitor->user->tokens()->delete();
            }
        }
        if (array_key_exists('password', $validated)) {
            $courseMonitor->user->update(['password' => Hash::make($validated['password'])]);
        }

        return response()->json(['data' => $courseMonitor->fresh(['user:id,name,username', 'student:id,first_name,last_name'])]);
    }

    /** Envíos de monitores en los cursos del docente (por defecto, pendientes). */
    public function submissions(Request $request)
    {
        $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]]);

        $submissions = MonitorSubmission::whereHas('groupSubject', fn ($q) => $q->where('user_id', $request->user()->id))
            ->where('status', $request->query('status', 'pending'))
            ->with(['groupSubject.group:id,name', 'groupSubject.subject:id,name,color', 'submitter:id,name'])
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['data' => $submissions]);
    }

    /**
     * Detalle para revisar: nombres de los estudiantes involucrados y, en asistencia,
     * el estado actual de cada uno ese día, para ver exactamente qué cambiaría.
     */
    public function showSubmission(MonitorSubmission $submission)
    {
        $this->authorize('update', $submission->groupSubject);
        $submission->load(['groupSubject.group:id,name', 'groupSubject.subject:id,name,color', 'submitter:id,name', 'reviewer:id,name']);

        $payload = $submission->payload;
        $studentIds = collect($payload['records'] ?? $payload['entries'] ?? [])->pluck('student_id')
            ->push($payload['student_id'] ?? null)->filter()->unique();

        $current = [];
        if ($submission->type === 'attendance') {
            $current = AttendanceRecord::where('group_subject_id', $submission->group_subject_id)
                ->whereDate('date', $payload['date'])
                ->whereIn('student_id', $studentIds)
                ->pluck('status', 'student_id');
        }

        return response()->json([
            'data' => $submission,
            'students' => Student::whereIn('id', $studentIds)->get(['id', 'first_name', 'last_name'])->keyBy('id'),
            'current_attendance' => (object) $current,
        ]);
    }

    public function approve(Request $request, MonitorSubmission $submission)
    {
        $this->authorize('update', $submission->groupSubject);

        return response()->json(['data' => $this->reviews->approve($submission, $request->user())]);
    }

    public function reject(Request $request, MonitorSubmission $submission)
    {
        $this->authorize('update', $submission->groupSubject);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->reviews->reject($submission, $request->user(), $validated['notes'] ?? null)]);
    }
}
