<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Envoltura fina sobre minishlink/web-push (módulo 17/18 — Web Push). No
 * envía nada en tests: siempre se sustituye por un mock (enviar un push real
 * requiere red saliente a los servidores de Google/Mozilla, no verificable
 * en este entorno).
 */
class WebPushService
{
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('services.vapid.subject'),
                'publicKey' => config('services.vapid.public_key'),
                'privateKey' => config('services.vapid.private_key'),
            ],
        ]);

        $subscriptions = PushSubscription::where('user_id', $user->id)->get();
        $payload = json_encode(['title' => $title, 'body' => $body, 'data' => $data]);

        foreach ($subscriptions as $subscription) {
            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->p256dh,
                    'authToken' => $subscription->auth,
                ]),
                $payload
            );

            if (! $report->isSuccess() && $report->isSubscriptionExpired()) {
                $subscription->delete();
            }
        }
    }
}
