<?php

namespace App\Console\Commands\Notifications;

use App\Models\AppNotification;
use App\Models\Homework;
use Illuminate\Console\Command;

/**
 * 8:30 AM diario: tareas vencidas con entregas sin calificar (ítem de la
 * campanita del módulo 17 que no tenía un comando automático propio en el
 * spec — se unifica aquí para reutilizar la misma infraestructura).
 */
class NotifyMissingHomeworkGrades extends Command
{
    protected $signature = 'notifications:missing-homework-grades';

    protected $description = 'Notifica tareas vencidas con entregas sin calificar';

    public function handle(): int
    {
        $today = now()->toDateString();

        $homeworks = Homework::where('is_graded', true)
            ->whereDate('due_date', '<', $today)
            ->whereHas('deliveries', fn ($q) => $q->where('status', 'entregado')->whereNull('score'))
            ->with('groupSubject.subject', 'groupSubject.group')
            ->get();

        foreach ($homeworks as $homework) {
            $groupSubject = $homework->groupSubject;
            if (! $groupSubject) {
                continue;
            }

            AppNotification::firstOrCreate(
                ['user_id' => $groupSubject->user_id, 'dedupe_key' => "missing_homework:{$homework->id}"],
                [
                    'type' => 'missing_homework',
                    'title' => 'Tarea vencida sin calificar',
                    'body' => "\"{$homework->title}\" de {$groupSubject->subject->name} — {$groupSubject->group->name} tiene entregas sin calificar.",
                    'data' => ['homework_id' => $homework->id],
                    'priority' => 'warning',
                    'created_at' => now(),
                ]
            );
        }

        return self::SUCCESS;
    }
}
