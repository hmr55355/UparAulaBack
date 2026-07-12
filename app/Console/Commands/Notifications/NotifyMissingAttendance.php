<?php

namespace App\Console\Commands\Notifications;

use App\Models\AppNotification;
use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 8:00 AM diario: recordatorio de asistencias del día sin registrar (módulo 17).
 */
class NotifyMissingAttendance extends Command
{
    protected $signature = 'notifications:missing-attendance';

    protected $description = 'Notifica a los docentes con clases de hoy sin asistencia registrada';

    public function handle(): int
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $currentTime = $now->format('H:i:s');

        $blocks = ClassSchedule::where('day_of_week', $now->isoWeekday())
            ->where('is_active', true)
            ->where('start_time', '<=', $currentTime)
            ->with('groupSubject.group', 'groupSubject.subject')
            ->get();

        foreach ($blocks as $block) {
            $groupSubject = $block->groupSubject;
            if (! $groupSubject) {
                continue;
            }

            $hasAttendance = AttendanceRecord::where('group_subject_id', $groupSubject->id)
                ->whereDate('date', $today)
                ->exists();

            if ($hasAttendance) {
                continue;
            }

            AppNotification::firstOrCreate(
                ['user_id' => $block->user_id, 'dedupe_key' => "missing_attendance:{$block->id}:{$today}"],
                [
                    'type' => 'missing_attendance',
                    'title' => 'Asistencia sin registrar',
                    'body' => "No has registrado la asistencia de {$groupSubject->subject->name} — {$groupSubject->group->name} de hoy.",
                    'data' => ['group_subject_id' => $groupSubject->id],
                    'priority' => 'warning',
                    'created_at' => now(),
                ]
            );
        }

        return self::SUCCESS;
    }
}
