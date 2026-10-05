<?php

namespace App\Console\Commands;

use App\Support\Video\DailyVideo;
use Illuminate\Console\Command;

/** Deployment check for video calls (D50): the Daily key and domain, a test room, recording off, a token. */
class VideoCheck extends Command
{
    protected $signature = 'mtl:video-check';

    protected $description = 'Check the Daily.co video call set-up';

    public function handle(DailyVideo $daily): int
    {
        if (! $daily->configured()) {
            $this->error('DAILY_API_KEY and/or DAILY_DOMAIN are empty in .env. Meetings can be scheduled, but nobody can join a call.');
            $this->line('Fix: put both in .env (Daily dashboard → Developers), then php artisan optimize');

            return self::FAILURE;
        }

        $this->line('Daily domain: '.$daily->domain());
        $ok = true;
        foreach ($daily->probe() as $step) {
            $ok = $ok && $step['ok'];
            $step['ok'] ? $this->info("  ✓ {$step['step']}: {$step['detail']}") : $this->error("  ✗ {$step['step']}: {$step['detail']}");
        }
        $this->newLine();
        $ok ? $this->info('Video calls are ready. Make a short test call between two devices before relying on it.')
            : $this->error('Video calls will not work until the failure above is fixed.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
