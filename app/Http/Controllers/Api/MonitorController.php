<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\CourseMonitor;
use App\Models\MonitorSubmission;
use App\Models\Student;
use App\Services\MonitorReviewService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API de la cuenta de monitor (solo rutas /monitor, middleware `monitor`). El
 * monitor solo ve y propone cambios en los cursos donde está activo; todo lo que
 * envía queda pendiente hasta que su docente lo apruebe.
 */
class MonitorController extends Controller
{
    public function __construct(private MonitorReviewService $reviews) {}

    public function courses(Request $request)
    {
        $courses = $request->user()->courseMonitors()
            ->where('is_active', true)
            ->with(['groupSubject.group:id,name', 'groupSubject.subject:id,name,color', 'groupSubject.teacher:id,name'])
            ->get()
            ->map(fn (CourseMonitor $m) => [
                'id' => $m->id,
                'group_subject_id' => $m->group_subject_id,
                'group_name' => $m->groupSubject->group->name,
                'subject_name' => $m->groupSubject->subject->name,
                'subject_color' => $m->groupSubject->subject->color,
                'teacher_name' => $m->groupSubject->teacher?->name,
            ]);

        return response()->json(['data' => $courses]);
    }

    /** Lista del curso + la asistencia ya aprobada del día + un envío pendiente de ese día, si hay. */
    public function roster(Request $request, CourseMonitor $courseMonitor)
    {
        $this->authorizeMonitor($request, $courseMonitor);
        $request->validate(['date' => ['nullable', 'date']]);
        $groupSubject = $courseMonitor->groupSubject;

        $students = Student::whereHas(
            'studentGroups',
            fn ($q) => $q->where('group_id', $groupSubject->group_id)->where('status', 'activo')
        )->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        $records = (object) [];
        $pending = null;
        if ($request->filled('date')) {
            $records = AttendanceRecord::where('group_subject_id', $groupSubject->id)
                ->whereDate('date', $request->date)
                ->get(['student_id', 'status', 'justification'])
                ->keyBy('student_id');
            $pending = $this->pendingAttendanceFor($courseMonitor, $request->date);
        }

        return response()->json(['students' => $students, 'records' => $records, 'pending_submission' => $pending]);
    }

    public function submit(Request $request, CourseMonitor $courseMonitor)
    {
        $this->authorizeMonitor($request, $courseMonitor);
        $validated = $request->validate([
            'type' => ['required', Rule::in(MonitorSubmission::TYPES)],
            'payload' => ['required', 'array'],
        ]);

        $payload = $this->reviews->validatePayload($courseMonitor->groupSubject, $validated['type'], $validated['payload']);

        // Volver a pasar lista el mismo día mientras el docente no ha revisado = editar
        // el envío pendiente, no crear otro.
        $submission = $validated['type'] === 'attendance'
            ? $this->pendingAttendanceFor($courseMonitor, $payload['date'])
            : null;

        if ($submission) {
            $submission->update(['payload' => $payload]);
        } else {
            $submission = MonitorSubmission::create([
                'course_monitor_id' => $courseMonitor->id,
                'group_subject_id' => $courseMonitor->group_subject_id,
                'submitted_by' => $request->user()->id,
                'type' => $validated['type'],
                'payload' => $payload,
            ]);
        }

        $this->reviews->notifyTeacher($submission);

        return response()->json(['data' => $submission->fresh()], 201);
    }

    public function submissions(Request $request)
    {
        $submissions = MonitorSubmission::where('submitted_by', $request->user()->id)
            ->with(['groupSubject.group:id,name', 'groupSubject.subject:id,name'])
            ->latest()
            ->limit(50)
            ->get();

        return response()->json(['data' => $submissions]);
    }

    public function cancel(Request $request, MonitorSubmission $submission)
    {
        abort_unless($submission->submitted_by === $request->user()->id, 403);
        abort_if($submission->status !== 'pending', 422, 'Solo se pueden cancelar envíos pendientes.');

        $submission->delete();

        return response()->json(['message' => 'Envío cancelado.']);
    }

    private function authorizeMonitor(Request $request, CourseMonitor $courseMonitor): void
    {
        abort_unless(
            $courseMonitor->user_id === $request->user()->id && $courseMonitor->is_active,
            403,
            'No eres monitor activo de este curso.'
        );
    }

    private function pendingAttendanceFor(CourseMonitor $courseMonitor, string $date): ?MonitorSubmission
    {
        return MonitorSubmission::where('course_monitor_id', $courseMonitor->id)
            ->where('type', 'attendance')
            ->where('status', 'pending')
            ->get()
            ->first(fn ($s) => ($s->payload['date'] ?? null) === $date);
    }
}
