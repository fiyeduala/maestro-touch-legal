<?php

namespace App\Console\Commands;

use App\Domain\Content\ImportVerifier;
use App\Domain\Content\WordPressImporter;
use App\Domain\Content\WxrReader;
use Illuminate\Console\Command;

/** Compares imported content with its WordPress source and writes a report. Changes nothing. */
class VerifyWordPressImport extends Command
{
    protected $signature = 'mtl:verify-import
        {--path=database/wordpress-capture/rest : Directory of captured REST JSON}
        {--live= : Compare against a live site instead, e.g. https://mtouchlegal.com}
        {--wxr= : Compare against a WordPress export (WXR .xml) file}
        {--draft=* : Post slugs deliberately imported as drafts (default: hello-world)}';

    protected $description = 'Check imported WordPress content against the source: counts, text, dates, taxonomies, media, links and redirects';

    public function handle(ImportVerifier $verifier): int
    {
        $live = $this->option('live');
        $wxr = $this->option('wxr');
        if ($live && ! preg_match('#^https?://#i', $live)) {
            $this->error('--live must be an http(s) URL.');

            return self::FAILURE;
        }

        $data = match (true) {
            (bool) $live => WordPressImporter::readLive($live),
            (bool) $wxr => WxrReader::read($wxr),
            default => WordPressImporter::readDirectory(base_path((string) $this->option('path'))),
        };

        $result = $verifier->verify($data, $this->option('draft') ?: ['hello-world']);

        $rows = [];
        foreach ($result['counts'] as $type => $c) {
            $rows[] = is_array($c) ? [$type, $c['source'], $c['imported']] : [str_replace('_', ' ', $type), '', $c];
        }
        $this->table(['Item', 'Source', 'Imported'], $rows);

        foreach ($result['findings'] as $f) {
            $line = "{$f['item']}: {$f['message']}";
            $f['level'] === 'fail' ? $this->warn('DIFF '.$line) : $this->line('note '.$line);
        }

        $result['ok'] ? $this->info('No differences found.') : $this->error('Differences found.');
        $this->line("Report: storage/app/private/{$result['report_path']}");

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
