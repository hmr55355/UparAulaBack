<?php

namespace App\Console\Commands\Notifications;

use App\Models\AppNotification;
use App\Models\BehaviorAnnotation;
use Illuminate\Console\Command;

/**
 * Diario: anotación negativa con requires_parent_contact=true sin contactar
 * hace más de 7 días (módulo 17). Se notifica una sola vez por anotación
 * (dedupe_key sin fecha) — si el docente sigue sin contactar, no se repite.
 */
class NotifyUncontactedBehavior extends Command
{
    protected $signature = 'notifications:uncontacted-behavior';

    protected $description = 'Notifica anotaciones negativas sin contactar al padre hace más de 7 días';

    public function handle(): int
    {
        $cutoff = now()->subDays(7)->toDateString();

        $annotations = BehaviorAnnotation::where('requires_parent_contact', true)
            ->where('parent_contacted', false)
            ->whereDate('date', '<=', $cutoff)
            ->with('student')
            ->get();

        foreach ($annotations as $annotation) {
            AppNotification::firstOrCreate(
                ['user_id' => $annotation->registered_by, 'dedupe_key' => "uncontacted_behavior:{$annotation->id}"],
                [
                    'type' => 'uncontacted_behavior',
                    'title' => 'Contacto con padre pendiente',
                    'body' => "Hace más de 7 días que {$annotation->student->last_name} {$annotation->student->first_name} tiene una anotación sin contactar al acudiente.",
                    'data' => ['behavior_annotation_id' => $annotation->id],
                    'priority' => 'danger',
                    'created_at' => now(),
                ]
            );
        }

        return self::SUCCESS;
    }
}
