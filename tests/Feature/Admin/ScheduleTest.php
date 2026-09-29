<?php

namespace Tests\Feature\Admin;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Shared hosting: one cron call every 5 minutes, no daemon. Every task must be locked and bounded. */
class ScheduleTest extends TestCase
{
    private function events(): array
    {
        return collect(app(Schedule::class)->events())
            ->mapWithKeys(fn (Event $event) => [trim(preg_replace('/^.*artisan[\'"]?\s+/', '', $event->command)) => $event])
            ->all();
    }

    public function test_background_tasks_are_scheduled_locked_and_bounded(): void
    {
        $events = $this->events();

        foreach (['digests:run', 'consultations:remind', 'tasks:notify', 'payments:reconcile'] as $command) {
            $this->assertArrayHasKey($command, $events, "{$command} is not scheduled");
            $this->assertTrue($events[$command]->withoutOverlapping, "{$command} can overlap");
        }
        $this->assertArrayHasKey('ops:heartbeat', $events);

        $worker = collect($events)->first(fn (Event $e, string $command) => str_starts_with($command, 'queue:work'));
        $this->assertNotNull($worker);
        $this->assertTrue($worker->withoutOverlapping);
        $this->assertStringContainsString('--stop-when-empty', $worker->command);
        $this->assertStringContainsString('--max-time=180', $worker->command);
    }

    public function test_heartbeat_records_the_last_run(): void
    {
        Artisan::call('ops:heartbeat');

        $this->assertNotNull(Cache::get('ops.scheduler_last_run'));
    }
}
