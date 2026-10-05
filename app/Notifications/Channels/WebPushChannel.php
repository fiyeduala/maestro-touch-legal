<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Sends a notification to every browser the user turned alerts on in (D49). It never throws: a failed
 * push must not break the request that caused it. Each reason it gives up is logged under "webpush",
 * and `php artisan mtl:push-check` walks the same ground. Subscriptions the push service reports as
 * gone (404/410) are removed.
 */
class WebPushChannel
{
    public static function configured(): bool
    {
        return trim((string) config('push.vapid.public_key')) !== '' && trim((string) config('push.vapid.private_key')) !== '';
    }

    public static function client(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => (string) config('push.vapid.subject'),
                'publicKey' => trim((string) config('push.vapid.public_key')),
                'privateKey' => trim((string) config('push.vapid.private_key')),
            ],
        ], ['TTL' => 3600], max(2, (int) config('push.timeout', 5)));
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWebPush') || ! self::configured()) {
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $notifiable->getKey())->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $type = class_basename($notification);
        try {
            $push = self::client();
            $payload = json_encode($notification->toWebPush($notifiable));
            foreach ($subscriptions as $subscription) {
                $push->queueNotification(Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => ['p256dh' => $subscription->p256dh, 'auth' => $subscription->auth],
                ]), $payload);
            }
            $reports = $push->flush();
        } catch (Throwable $e) {
            Log::error('webpush: send failed before anything left the server', ['notification' => $type, 'user_id' => $notifiable->getKey(), 'error' => $e->getMessage()]);

            return;
        }

        foreach ($reports as $report) {
            if ($report->isSuccess()) {
                continue;
            }
            Log::warning('webpush: rejected by the push service', [
                'notification' => $type,
                'user_id' => $notifiable->getKey(),
                'service' => parse_url($report->getEndpoint(), PHP_URL_HOST),
                'status' => $report->getResponse()?->getStatusCode(),
                'reason' => trim((string) $report->getReason()),
            ]);
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }
}
