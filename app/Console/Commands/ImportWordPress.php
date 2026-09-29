<?php

namespace App\Console\Commands;

use App\Domain\Content\WordPressImporter;
use App\Domain\Content\WxrReader;
use Illuminate\Console\Command;

/**
 * Imports blog content from WordPress. Safe to re-run: unchanged items are skipped and local edits are kept.
 * Reads a captured REST directory by default; --live reads the site's public REST API (GET only, no credentials);
 * --wxr reads a WordPress export file (Tools > Export), which also holds drafts and moderated comments.
 */
class ImportWordPress extends Command
{
    protected $signature = 'mtl:import-wordpress
        {--path=docs/source-capture/rest : Directory of captured REST JSON (posts, media, categories, tags, comments[, users])}
        {--live= : Read from a live site instead, e.g. https://mtouchlegal.com}
        {--wxr= : Read a WordPress export (WXR .xml) file instead}
        {--html-path=docs/source-capture/html : Captured post pages, used to find author names when users.json is absent}
        {--author=* : Author name override as WP_USER_ID=Name}
        {--draft=* : Post slugs to import as drafts (default: hello-world)}
        {--dry-run : Report what would change without saving anything}';

    protected $description = 'Import WordPress posts, media records, categories, tags and comments';

    public function handle(WordPressImporter $importer): int
    {
        $authors = [];
        foreach ((array) $this->option('author') as $pair) {
            if (! preg_match('/^(\d+)=(.+)$/', $pair, $m)) {
                $this->error("Invalid --author value '{$pair}'. Use ID=Name.");

                return self::FAILURE;
            }
            $authors[$m[1]] = trim($m[2]);
        }

        $live = $this->option('live');
        if ($live && ! preg_match('#^https?://#i', $live)) {
            $this->error('--live must be an http(s) URL.');

            return self::FAILURE;
        }

        $wxr = $this->option('wxr');
        if ($live && $wxr) {
            $this->error('Use either --live or --wxr, not both.');

            return self::FAILURE;
        }

        $data = match (true) {
            (bool) $live => WordPressImporter::readLive($live),
            (bool) $wxr => WxrReader::read($wxr),
            default => WordPressImporter::readDirectory(base_path((string) $this->option('path'))),
        };

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Importing from '.($live ?: $wxr ?: $this->option('path')).'...');

        $run = $importer->import([
            'data' => $data,
            'authors' => $authors,
            'html_path' => $live || $wxr ? null : base_path((string) $this->option('html-path')),
            'draft_slugs' => $this->option('draft') ?: ['hello-world'],
            'dry_run' => $dryRun,
        ]);

        $stats = $run->stats ?? [];
        ksort($stats);
        $this->table(['Item', 'Count'], collect($stats)->map(fn ($n, $k) => [$k, $n])->values()->all());

        foreach ($run->issues ?? [] as $issue) {
            $line = strtoupper($issue['level'])." {$issue['type']} #{$issue['id']}: {$issue['message']}";
            $issue['level'] === 'error' ? $this->error($line) : ($issue['level'] === 'warning' ? $this->warn($line) : $this->line($line));
        }

        $this->info("Run #{$run->id} {$run->status}".($dryRun ? ' (nothing saved)' : '').($run->report_path ? "; report: storage/app/private/{$run->report_path}" : ''));

        return $run->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
