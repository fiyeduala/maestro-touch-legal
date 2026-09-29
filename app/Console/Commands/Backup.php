<?php

namespace App\Console\Commands;

use App\Domain\Operations\Backups;
use App\Domain\RuleViolation;
use Illuminate\Console\Command;

/** Nightly from the scheduler; can also be run by hand from the cPanel terminal before any risky change. */
class Backup extends Command
{
    protected $signature = 'mtl:backup {--database-only : Leave out the stored files}';

    protected $description = 'Write an encrypted backup of the database and stored files';

    public function handle(Backups $backups): int
    {
        try {
            $result = $backups->create(withFiles: ! $this->option('database-only'));
        } catch (RuleViolation $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup written: {$result['file']} (".$backups->human($result['size']).", {$result['tables']} tables, {$result['rows']} rows, {$result['files']} files, {$result['seconds']}s).");
        $this->line('Folder: '.$backups->directory());
        $this->warn('This copy is on the same server. Download a copy regularly and keep it somewhere else (see docs/BACKUP-AND-RESTORE.md).');

        return self::SUCCESS;
    }
}
