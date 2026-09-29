<?php

use App\Domain\Communication\Digests;
use App\Domain\Consultations\Consultations;
use App\Domain\Matters\Tasks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
| cPanel runs `php artisan schedule:run` from cron every 5 minutes (Namecheap's minimum interval), and
| there is no long-running worker. Tasks below that should happen on every cron call are scheduled
| everyMinute(): the cron interval, not Laravel, sets the real cadence. Each task is bounded in time,
| locked with withoutOverlapping(), and works out what became due since its last run, so a late or
| missed cron call is caught up on the next one. Together they stay well under five minutes.
*/

Artisan::command('tasks:notify', function (Tasks $tasks) {
    $result = $tasks->sendDueNotifications();
    $this->info("Reminders sent: {$result['reminded']}; overdue escalations: {$result['escalated']}.");
})->purpose('Send due task reminders and escalate overdue matter tasks');

Artisan::command('digests:run {--batch=40} {--seconds=60}', function (Digests $digests) {
    $result = $digests->run(batch: (int) $this->option('batch'), maxSeconds: (int) $this->option('seconds'));
    $this->info(collect($result)->map(fn ($n, $k) => "{$k}: {$n}")->implode('; '));
})->purpose('Plan and send the end-of-day conversation emails that are due');

Artisan::command('consultations:remind', function (Consultations $consultations) {
    $this->info('Reminders sent: '.$consultations->sendReminders());
})->purpose('Send consultation reminders that are due');

Artisan::command('ops:heartbeat', function () {
    Cache::forever('ops.scheduler_last_run', now()->toIso8601String());
})->purpose('Record that the scheduler ran (shown on the admin Operations page)');

Schedule::command('ops:heartbeat')->everyMinute();
Schedule::command('digests:run')->everyMinute()->withoutOverlapping(10);
Schedule::command('consultations:remind')->everyMinute()->withoutOverlapping(10);
Schedule::command('tasks:notify')->everyFifteenMinutes()->withoutOverlapping();
// Emails and other queued jobs: a short, bounded worker per cron call instead of a daemon.
Schedule::command('queue:work --stop-when-empty --max-time=180 --tries=3 --backoff=60 --timeout=60')
    ->everyMinute()->withoutOverlapping(5);

Artisan::command('payments:reconcile {--limit=50}', function (App\Domain\Billing\PaystackPayments $paystack) {
    $checked = $paystack->reconcile((int) $this->option('limit'));
    Cache::forever('ops.paystack_last_reconcile', now()->toIso8601String());
    $this->info("Online payments checked with Paystack: {$checked}");
})->purpose('Verify pending Paystack payments nobody returned from, and close abandoned ones');

// Catches payments whose browser callback and webhook were both missed.
Schedule::command('payments:reconcile')->everyFifteenMinutes()->withoutOverlapping(10);

// Nightly encrypted backup (D38). BACKUP_AT should fall on a 5-minute mark so the cPanel cron call catches it.
Schedule::command('mtl:backup')->dailyAt((string) config('backup.at'))->timezone((string) config('app.firm_timezone'))
    ->withoutOverlapping(180);
