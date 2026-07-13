<?php

namespace App\Console\Commands\Notifications;

use App\Jobs\SendWebPushNotificationJob;
use App\Models\AppNotification;
use App\Models\ClassSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Cada minuto: push "5 minutos antes de tu clase" (spec línea 1768, unifica
 * también el widget "clase en curso" de la línea 673 — ver Contexto del plan
 * de esta fase). Ventana de 1 minuto porque el comando corre cada minuto.
 */
class NotifyUpcomingClassPush extends Command
{
    protected $signature = 'notifications:upcoming-class-push';

    protected $description = 'Envía un push 5 minutos antes de que inicie cada bloque de clase';

    public function handle(): int
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $windowStart = $now->copy()->addMinutes(4)->format('H:i:s');
        $windowEnd = $now->copy()->addMinutes(5)->format('H:i:s');

        $blocks = ClassSchedule::where('day_of_week', $now->isoWeekday())
            ->where('is_active', true)
            ->where('start_time', '>=', $windowStart)
            ->where('start_time', '<', $windowEnd)
            ->with('groupSubject.subject', 'groupSubject.group', 'teacher')
            ->get();

        foreach ($blocks as $block) {
            $teacher = $block->teacher;
            $groupSubject = $block->groupSubject;
            if (! $teacher || ! $groupSubject || ! $teacher->wantsNotification('upcoming_class_push')) {
                continue;
            }

            $notification = AppNotification::firstOrCreate(
                ['user_id' => $teacher->id, 'dedupe_key' => "upcoming_class_push:{$block->id}:{$today}"],
                [
                    'type' => 'upcoming_class_push',
                    'title' => 'Tu próxima clase',
                    'body' => "En 5 minutos: {$groupSubject->group->name} — {$groupSubject->subject->name}".($block->classroom ? " en {$block->classroom}" : ''),
                    'data' => ['class_schedule_id' => $block->id],
                    'priority' => 'info',
                    'created_at' => now(),
                ]
            );

            if ($notification->wasRecentlyCreated) {
                // Job en cola: si varias clases empiezan en la misma ventana de 1 minuto,
                // no serializamos las llamadas de red del push dentro de este comando.
                SendWebPushNotificationJob::dispatch($teacher->id, $notification->title, $notification->body, $notification->data);
            }
        }

        return self::SUCCESS;
    }
}
