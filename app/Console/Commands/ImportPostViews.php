<?php

namespace App\Console\Commands;

use App\Domain\Content\PostViews;
use Illuminate\Console\Command;

/**
 * Fills in blog visits from before the analytics existed (D51), from the raw access logs cPanel keeps
 * (Metrics > Raw Access, or ~/logs/*.gz). Running it twice on the same log adds nothing new.
 */
class ImportPostViews extends Command
{
    protected $signature = 'mtl:import-post-views {files* : Access log files, plain or .gz}';

    protected $description = 'Count past blog post visits from web server access logs (approximate)';

    public function handle(PostViews $views): int
    {
        $totals = ['lines' => 0, 'counted' => 0];

        foreach ($this->argument('files') as $file) {
            if (! is_file($file) || ! is_readable($file)) {
                $this->error("Cannot read {$file}.");

                return self::FAILURE;
            }

            $result = $views->importAccessLog($this->lines($file));
            $this->line("{$file}: {$result['lines']} lines, {$result['counted']} visits added.");
            $totals['lines'] += $result['lines'];
            $totals['counted'] += $result['counted'];
        }

        $this->info("Done: {$totals['counted']} visits added from {$totals['lines']} lines.");
        $this->warn('These are page loads, not reads. Behind Cloudflare the logged address may be Cloudflare\'s, so treat the numbers as approximate.');

        return self::SUCCESS;
    }

    /** @return \Generator<string> */
    private function lines(string $file): \Generator
    {
        $gz = str_ends_with($file, '.gz');
        $handle = $gz ? gzopen($file, 'rb') : fopen($file, 'rb');

        try {
            while (($line = $gz ? gzgets($handle) : fgets($handle)) !== false) {
                yield $line;
            }
        } finally {
            $gz ? gzclose($handle) : fclose($handle);
        }
    }
}
