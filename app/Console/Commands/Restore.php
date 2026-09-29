<?php

namespace App\Console\Commands;

use App\Domain\Operations\Audit;
use App\Domain\Operations\Backups;
use App\Domain\RuleViolation;
use Illuminate\Console\Command;

/**
 * Restores a backup into a NEW, EMPTY database and/or a new, empty folder. It never overwrites the live
 * database or the live files; switching over is a separate, deliberate step (docs/BACKUP-AND-RESTORE.md).
 */
class Restore extends Command
{
    protected $signature = 'mtl:restore
        {file : Backup file name in the backup folder, or a full path}
        {--db-name= : Name of the new, empty database to restore into (same server and database user as .env)}
        {--connection= : Or a database connection name from config/database.php}
        {--files-to= : A new or empty folder to unpack the stored files into}
        {--ask-password : Type the backup password instead of using BACKUP_PASSWORD from .env}';

    protected $description = 'Restore a backup into an empty database and/or an empty folder';

    public function handle(Backups $backups): int
    {
        $file = (string) $this->argument('file');
        $path = $backups->find($file) ?? (is_file($file) ? $file : null);
        if (! $path) {
            $this->error('Backup not found. Run without arguments to see names: ls '.$backups->directory());

            return self::FAILURE;
        }

        $connection = $this->option('connection') ?: null;
        if ($name = $this->option('db-name')) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                $this->error('Database names may only contain letters, numbers and underscores.');

                return self::FAILURE;
            }
            if ($name === config('database.connections.'.config('database.default').'.database')) {
                $this->error('That is the live database. Restore into a new, empty database instead.');

                return self::FAILURE;
            }
            config(['database.connections.restore_target' => array_merge(config('database.connections.'.config('database.default')), ['database' => $name])]);
            $connection = 'restore_target';
        }
        $filesTo = $this->option('files-to') ?: null;
        if (! $connection && ! $filesTo) {
            $this->error('Say where to restore to: --db-name (and/or --connection) for the database, --files-to for the files.');

            return self::FAILURE;
        }

        $password = $this->option('ask-password') ? (string) $this->secret('Backup password') : null;

        try {
            $report = $backups->restore($path, $connection, $filesTo, $password);
        } catch (RuleViolation $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($connection) {
            $this->info("Database restored: {$report['tables']} tables, {$report['rows']} rows ({$report['statements']} statements).");
            foreach ($report['mismatches'] as $problem) {
                $this->error("Row count differs – {$problem}");
            }
        }
        if ($filesTo) {
            $this->info("Files unpacked: {$report['files']} into {$filesTo}");
        }
        Audit::record('backup.restored', 'Backup '.basename($path).' restored into '.($connection ? 'an empty database' : '').($connection && $filesTo ? ' and ' : '').($filesTo ? 'an empty folder' : ''),
            context: ['mismatches' => count($report['mismatches'])]);

        return $report['mismatches'] ? self::FAILURE : self::SUCCESS;
    }
}
