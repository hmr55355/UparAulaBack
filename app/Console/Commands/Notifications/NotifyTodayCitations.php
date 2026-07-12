<?php

namespace App\Console\Commands\Notifications;

use App\Models\AppNotification;
use App\Models\ParentCitation;
use Illuminate\Console\Command;

/**
 * 7:00 AM diario: recordatorio de citación para hoy (módulo 17).
 */
class NotifyTodayCitations extends Command
{
    protected $signature = 'notifications:today-citations';

    protected $description = 'Notifica a los docentes con citaciones programadas para hoy';

    public function handle(): int
    {
        $today = now()->toDateString();

        $citations = ParentCitation::whereDate('scheduled_date', $today)
            ->with('student')
            ->get();

        foreach ($citations as $citation) {
            AppNotification::firstOrCreate(
                ['user_id' => $citation->registered_by, 'dedupe_key' => "today_citation:{$citation->id}:{$today}"],
                [
                    'type' => 'today_citation',
                    'title' => 'Citación programada para hoy',
                    'body' => "Tienes una citación con el acudiente de {$citation->student->last_name} {$citation->student->first_name} hoy.",
                    'data' => ['citation_id' => $citation->id],
                    'priority' => 'info',
                    'created_at' => now(),
                ]
            );
        }

        return self::SUCCESS;
    }
}
