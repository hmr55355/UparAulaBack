<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\BehaviorAnnotation;
use App\Models\GroupSubject;
use App\Models\MonitorSubmission;
use App\Models\Participation;
use App\Models\StudentGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;

/**
 * Flujo de lo que envía un monitor: se valida y queda "pendiente", se le avisa
 * al docente, y solo al aprobarlo se escribe en las tablas reales (asistencia,
 * comportamiento, participación) — por los mismos caminos que usa el docente.
 */
class MonitorReviewService
{
    public const ATTENDANCE_STATUSES = ['presente', 'ausente_injustificado', 'ausente_justificado', 'tarde', 'retirado_temprano'];

    public function __construct(
        private AttendanceService $attendance,
        private GradeCalculatorService $calculator,
    ) {}

    /** Valida el payload según el tipo y que todos los estudiantes sean del curso. */
    public function validatePayload(GroupSubject $groupSubject, string $type, array $payload): array
    {
        $enrolled = StudentGroup::where('group_id', $groupSubject->group_id)->where('status', 'activo')->pluck('student_id')->all();
        $student = ['required', 'integer', Rule::in($enrolled)];

        $rules = match ($type) {
            'attendance' => [
                'date' => ['required', 'date', 'before_or_equal:today'],
                'records' => ['required', 'array', 'min:1'],
                'records.*.student_id' => $student,
                'records.*.status' => ['required', Rule::in(self::ATTENDANCE_STATUSES)],
                'records.*.justification' => ['nullable', 'string', 'max:500'],
            ],
            'behavior' => [
                'date' => ['required', 'date', 'before_or_equal:today'],
                'student_id' => $student,
                'type' => ['required', Rule::in(['positiva', 'negativa'])],
                'observation' => ['required', 'string', 'max:1000'],
            ],
            'participation' => [
                'date' => ['required', 'date', 'before_or_equal:today'],
                'entries' => ['required', 'array', 'min:1'],
                'entries.*.student_id' => $student,
                'entries.*.points' => ['required', 'integer', 'min:1', 'max:20'],
            ],
            default => throw ValidationException::withMessages(['type' => ['Tipo de registro no válido.']]),
        };

        return Validator::make($payload, $rules, [
            'records.*.student_id.in' => 'Uno de los estudiantes no pertenece a este curso.',
            'entries.*.student_id.in' => 'Uno de los estudiantes no pertenece a este curso.',
            'student_id.in' => 'El estudiante no pertenece a este curso.',
        ])->validate();
    }

    public function notifyTeacher(MonitorSubmission $submission): void
    {
        $submission->loadMissing(['groupSubject.group', 'groupSubject.subject', 'submitter']);
        $groupSubject = $submission->groupSubject;
        $what = ['attendance' => 'asistencia', 'behavior' => 'una anotación de comportamiento', 'participation' => 'participaciones'][$submission->type];

        $notification = AppNotification::firstOrNew([
            'user_id' => $groupSubject->user_id,
            'dedupe_key' => "monitor_submission:{$submission->id}",
        ]);
        $notification->fill([
            'type' => 'monitor_submission',
            'title' => 'Tu monitor registró cambios',
            'body' => "{$submission->submitter->name} registró {$what} en {$groupSubject->group->name} — {$groupSubject->subject->name}. Revísalo para aprobarlo o rechazarlo.",
            'data' => ['submission_id' => $submission->id, 'url' => "/monitors/reviews/{$submission->id}"],
            'priority' => 'warning',
            'read_at' => null, // si el monitor lo editó, vuelve a aparecer como nuevo
        ]);
        $notification->created_at ??= now();
        $notification->save();
    }

    public function approve(MonitorSubmission $submission, User $reviewer): MonitorSubmission
    {
        $this->assertPending($submission);
        $groupSubject = $submission->groupSubject;
        $payload = $submission->payload;

        $period = $this->attendance->periodForDate($groupSubject, $payload['date']);
        abort_if($period?->is_closed, 422, 'El período de esa fecha está cerrado: ya no se puede aplicar este cambio.');

        DB::transaction(function () use ($submission, $groupSubject, $payload) {
            match ($submission->type) {
                'attendance' => $this->attendance->saveDay($groupSubject, $payload['date'], $payload['records'], $submission->submitted_by),
                'behavior' => BehaviorAnnotation::create([
                    'student_id' => $payload['student_id'],
                    'group_subject_id' => $groupSubject->id,
                    'group_id' => $groupSubject->group_id,
                    'registered_by' => $submission->submitted_by,
                    'date' => $payload['date'],
                    'type' => $payload['type'],
                    'category' => 'actitud',
                    'title' => $payload['type'] === 'positiva' ? 'Punto positivo (monitor)' : 'Punto negativo (monitor)',
                    'description' => $payload['observation'],
                ]),
                'participation' => collect($payload['entries'])->each(fn ($entry) => Participation::create([
                    'group_subject_id' => $groupSubject->id,
                    'student_id' => $entry['student_id'],
                    'date' => $payload['date'],
                    'points' => $entry['points'],
                    'registered_by' => $submission->submitted_by,
                    'monitor_submission_id' => $submission->id,
                ])),
            };
        });

        // La nota de participación es relativa al curso: recalcular a todos.
        if ($submission->type === 'participation' && $period) {
            $this->calculator->recalculateCourse($groupSubject->id, $period->id);
        }

        $submission->update(['status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
        $this->markNotificationRead($submission);

        return $submission;
    }

    public function reject(MonitorSubmission $submission, User $reviewer, ?string $notes): MonitorSubmission
    {
        $this->assertPending($submission);
        $submission->update([
            'status' => 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_notes' => $notes,
        ]);
        $this->markNotificationRead($submission);

        return $submission;
    }

    private function assertPending(MonitorSubmission $submission): void
    {
        abort_if($submission->status !== 'pending', 422, 'Este registro ya fue revisado.');
    }

    private function markNotificationRead(MonitorSubmission $submission): void
    {
        AppNotification::where('dedupe_key', "monitor_submission:{$submission->id}")->whereNull('read_at')->update(['read_at' => now()]);
    }
}
