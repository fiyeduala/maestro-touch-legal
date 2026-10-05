<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Says why browser push notifications are or are not arriving (D49): the keys, the site address, who has
 * turned alerts on, and optionally a real test push to one person, printing what each push service said.
 *
 *   php artisan mtl:push-check
 *   php artisan mtl:push-check --send=someone@example.com
 */
class PushCheck extends Command
{
    protected $signature = 'mtl:push-check {--send= : Email address of a user to send a test push to}';

    protected $description = 'Check the browser push notification set-up';

    public function handle(): int
    {
        $ok = true;

        $this->line('<options=bold>1. Keys</>');
        $public = trim((string) config('push.vapid.public_key'));
        $private = trim((string) config('push.vapid.private_key'));
        if ($public === '' || $private === '') {
            $this->error('  VAPID_PUBLIC_KEY and/or VAPID_PRIVATE_KEY are empty. Nobody can turn alerts on.');
            $this->line('  Fix: php artisan mtl:vapid-keys, put both lines in .env, then php artisan optimize');
            $ok = false;
        } else {
            try {
                VAPID::validate(['subject' => (string) config('push.vapid.subject'), 'publicKey' => $public, 'privateKey' => $private]);
                $this->info('  Both keys are set and valid.');
            } catch (Throwable $e) {
                $this->error('  The keys do not parse: '.$e->getMessage().' (often a line break or quotes picked up when pasting).');
                $ok = false;
            }
        }

        $this->newLine();
        $this->line('<options=bold>2. Site address</>');
        $url = (string) config('app.url');
        $this->line('  APP_URL: '.$url);
        if (! str_starts_with($url, 'https://')) {
            $this->warn('  Not https: browsers only allow push on https sites (localhost excepted).');
        }

        $this->newLine();
        $this->line('<options=bold>3. Who has alerts on</>');
        if (! Schema::hasTable('push_subscriptions')) {
            $this->error('  The push_subscriptions table is missing: php artisan migrate --force');

            return self::FAILURE;
        }
        $subscriptions = PushSubscription::with('user:id,name,email')->latest('id')->get();
        if ($subscriptions->isEmpty()) {
            $this->warn('  Nobody yet. Each person turns alerts on with the "Alerts" button (staff: top bar of the staff portal; clients: Client Area).');
        }
        foreach ($subscriptions as $subscription) {
            $this->line(sprintf('   - %-34s %-16s %s', $subscription->user?->email ?? '(deleted user)', $this->service($subscription->endpoint), $subscription->created_at?->diffForHumans()));
        }

        if ($email = $this->option('send')) {
            $this->newLine();
            $this->line('<options=bold>4. Test push</>');
            $ok = $ok && $this->testSend((string) $email);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function testSend(string $email): bool
    {
        $user = User::where('email', $email)->first();
        $subscriptions = $user ? PushSubscription::where('user_id', $user->id)->get() : collect();
        if ($subscriptions->isEmpty()) {
            $this->error('  That person has no browser with alerts turned on.');

            return false;
        }

        $push = WebPushChannel::client();
        $payload = json_encode([
            'title' => 'Maestro Touch Legal',
            'body' => 'Test alert. If you can read this, push notifications work on this device.',
            'url' => url('/'),
        ]);
        foreach ($subscriptions as $subscription) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'keys' => ['p256dh' => $subscription->p256dh, 'auth' => $subscription->auth],
            ]), $payload);
        }

        $accepted = 0;
        foreach ($push->flush() as $report) {
            if ($report->isSuccess()) {
                $accepted++;
                $this->info('   accepted by '.$this->service($report->getEndpoint()));

                continue;
            }
            $this->error('   rejected by '.$this->service($report->getEndpoint()).': HTTP '.($report->getResponse()?->getStatusCode() ?? 'no response').' '.trim((string) $report->getReason()));
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
                $this->line('   That browser no longer accepts alerts and was removed; it must turn them on again.');
            }
        }

        return $accepted > 0;
    }

    private function service(string $endpoint): string
    {
        $host = (string) parse_url($endpoint, PHP_URL_HOST);

        return match (true) {
            str_contains($host, 'google') => 'Chrome/Android',
            str_contains($host, 'mozilla') => 'Firefox',
            str_contains($host, 'apple') => 'Safari/iPhone',
            str_contains($host, 'windows') => 'Edge',
            default => $host,
        };
    }
}
