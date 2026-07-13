<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envía un push a un usuario en segundo plano — evita que un comando programado
 * (ej. NotifyUpcomingClassPush, que corre cada minuto) quede bloqueado
 * serializando llamadas de red una por una cuando varias clases empiezan en la
 * misma ventana de tiempo.
 */
class SendWebPushNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(WebPushService $webPush): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $webPush->sendToUser($user, $this->title, $this->body, $this->data);
    }
}
