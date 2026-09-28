<?php

use App\Domain\Matters\Tasks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| cPanel runs `php artisan schedule:run` from cron no more often than every 5 minutes, so nothing
| here is scheduled more frequently than that.
*/

Artisan::command('tasks:notify', function (Tasks $tasks) {
    $result = $tasks->sendDueNotifications();
    $this->info("Reminders sent: {$result['reminded']}; overdue escalations: {$result['escalated']}.");
})->purpose('Send due task reminders and escalate overdue matter tasks');

Schedule::command('tasks:notify')->everyFifteenMinutes()->withoutOverlapping();
