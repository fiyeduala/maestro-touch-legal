<?php

namespace App\Domain\Operations;

use App\Domain\RuleViolation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Encrypted backups of the database and the stored files (DECISIONS D38). A backup is one zip:
 *   manifest.json   what is inside, with row counts and a checksum of the database file
 *   database.sql    see DatabaseDump
 *   files/<disk>/…  the private, confidential, media and public storage areas
 * Every entry is AES-256 encrypted with BACKUP_PASSWORD. Backups stay on this server, outside the web root;
 * that guards against mistakes, not against losing the hosting account, so copies must also go elsewhere.
 * Restores go only into an empty database and an empty folder: nothing live is overwritten automatically.
 */
class Backups
{
    public const STATUS_KEY = 'ops.backup_last';

    private const NAME_PATTERN = '/^mtl-backup-\d{8}-\d{6}\.zip$/';

    public function directory(): string
    {
        return rtrim((string) config('backup.path'), '/\\');
    }

    public function configured(): bool
    {
        return filled(config('backup.password'));
    }

    /**
     * @return array{file: string, size: int, tables: int, rows: int, files: int, seconds: float}
     */
    public function create(bool $withFiles = true): array
    {
        if (! $this->configured()) {
            throw new RuleViolation('Backups are not set up: add BACKUP_PASSWORD to .env first. Backups are never written unencrypted.');
        }
        if (! ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)) {
            throw new RuleViolation('This server\'s PHP zip extension cannot encrypt (AES-256), so no backup was written.');
        }

        $started = microtime(true);
        $dir = $this->prepareDirectory();
        $name = 'mtl-backup-'.now()->format('Ymd-His').'.zip';
        $final = $dir.DIRECTORY_SEPARATOR.$name;
        $partial = $final.'.part';
        $sql = $dir.DIRECTORY_SEPARATOR.'.dump-'.Str::random(12).'.sql';

        try {
            $out = fopen($sql, 'wb');
            if (! $out) {
                throw new RuntimeException('Could not create a temporary file in the backup folder.');
            }
            try {
                $rows = (new DatabaseDump(DB::connection()))->write($out);
            } finally {
                fclose($out);
            }

            $zip = new ZipArchive;
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the backup file.');
            }
            $password = (string) config('backup.password');
            $this->add($zip, $sql, 'database.sql', $password);
            $files = $withFiles ? $this->addFiles($zip, $password) : [];

            $manifest = [
                'format' => 1,
                'app' => config('app.name'),
                'created_at' => now()->toIso8601String(),
                'environment' => app()->environment(),
                'database' => ['driver' => DB::connection()->getDriverName(), 'sha256' => hash_file('sha256', $sql), 'rows' => $rows],
                'files' => $files,
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, $password);
            if (! $zip->close()) {
                throw new RuntimeException('The backup file could not be finished (is the disk full?).');
            }

            $this->verify($partial, $manifest['database']['sha256']);
            if (! rename($partial, $final)) {
                throw new RuntimeException('The finished backup could not be moved into place.');
            }
        } catch (Throwable $e) {
            @unlink($partial);
            $this->remember(['ok' => false, 'error' => $this->safeMessage($e)]);
            Audit::record('backup.failed', 'Backup failed: '.$this->safeMessage($e));
            throw $e;
        } finally {
            @unlink($sql);
        }

        $result = [
            'file' => $name,
            'size' => (int) filesize($final),
            'tables' => count($rows),
            'rows' => array_sum($rows),
            'files' => array_sum(array_map('count', $files)),
            'seconds' => round(microtime(true) - $started, 1),
        ];
        $pruned = $this->prune();
        $this->remember(['ok' => true] + $result);
        Audit::record('backup.created', "Backup {$name} created (".$this->human($result['size']).')',
            context: ['tables' => $result['tables'], 'rows' => $result['rows'], 'files' => $result['files'], 'removed_old' => $pruned]);

        return $result;
    }

    /** Removes the oldest backups beyond the number to keep. Never touches anything but finished backups. */
    public function prune(): int
    {
        $keep = max(1, (int) config('backup.keep'));
        $removed = 0;
        foreach (array_slice($this->list(), $keep) as $backup) {
            if (@unlink($backup['path'])) {
                $removed++;
            }
        }

        return $removed;
    }

    /** @return list<array{name: string, path: string, size: int, created: int}> newest first */
    public function list(): array
    {
        $dir = $this->directory();
        if (! is_dir($dir)) {
            return [];
        }
        $backups = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match(self::NAME_PATTERN, $name)) {
                $path = $dir.DIRECTORY_SEPARATOR.$name;
                $backups[] = ['name' => $name, 'path' => $path, 'size' => (int) filesize($path), 'created' => (int) filemtime($path)];
            }
        }
        usort($backups, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $backups;
    }

    /** Full path of a finished backup, by file name only (no paths accepted). */
    public function find(string $name): ?string
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }
        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }

    /** @return array<string, mixed>|null */
    public function lastRun(): ?array
    {
        return Cache::get(self::STATUS_KEY);
    }

    /**
     * Restores a backup into an EMPTY database connection and/or an EMPTY folder. Nothing live is replaced;
     * moving restored files into place is a deliberate, documented manual step.
     *
     * @return array{tables: int, rows: int, statements: int, files: int, mismatches: list<string>}
     */
    public function restore(string $path, ?string $connection, ?string $filesTo, ?string $password = null): array
    {
        $zip = $this->openForReading($path, $password ?? (string) config('backup.password'));
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (! is_array($manifest) || ($manifest['format'] ?? null) !== 1) {
                throw new RuleViolation('This is not a readable Maestro Touch Legal backup, or the password is wrong.');
            }

            $report = ['tables' => 0, 'rows' => 0, 'statements' => 0, 'files' => 0, 'mismatches' => []];

            if ($connection !== null) {
                $report = $this->restoreDatabase($zip, $manifest, $connection) + $report;
            }
            if ($filesTo !== null) {
                $report['files'] = $this->restoreFiles($zip, $filesTo);
            }

            return $report;
        } finally {
            $zip->close();
        }
    }

    /** @return array{tables: int, rows: int, statements: int, mismatches: list<string>} */
    private function restoreDatabase(ZipArchive $zip, array $manifest, string $connection): array
    {
        $db = DB::connection($connection);
        $dump = new DatabaseDump($db);
        if ($dump->tables() !== []) {
            throw new RuleViolation('The target database is not empty. Create a new, empty database to restore into.');
        }

        $temp = tempnam(sys_get_temp_dir(), 'mtl-restore-');
        try {
            $this->extractEntry($zip, 'database.sql', $temp);
            if (! hash_equals((string) ($manifest['database']['sha256'] ?? ''), hash_file('sha256', $temp))) {
                throw new RuleViolation('The database file inside the backup does not match its checksum. Do not use this backup.');
            }
            $statements = $dump->load($temp);
        } finally {
            @unlink($temp);
        }

        $mismatches = [];
        $expected = (array) ($manifest['database']['rows'] ?? []);
        foreach ($expected as $table => $rows) {
            if (in_array($table, DatabaseDump::SKIP_ROWS, true)) {
                continue;
            }
            $actual = in_array($table, $dump->tables(), true) ? $dump->count($table) : null;
            if ($actual !== (int) $rows) {
                $mismatches[] = "{$table}: expected {$rows} rows, found ".($actual ?? 'no table');
            }
        }

        return ['tables' => count($expected), 'rows' => array_sum($expected), 'statements' => $statements, 'mismatches' => $mismatches];
    }

    private function restoreFiles(ZipArchive $zip, string $target): int
    {
        if (is_dir($target) && count(scandir($target) ?: []) > 2) {
            throw new RuleViolation('The folder to restore files into must be new or empty.');
        }
        if (! is_dir($target) && ! mkdir($target, 0700, true)) {
            throw new RuleViolation('The folder to restore files into could not be created.');
        }

        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (! str_starts_with($entry, 'files/') || str_ends_with($entry, '/')) {
                continue;
            }
            $relative = substr($entry, strlen('files/'));
            // Never write outside the target folder, whatever the archive says.
            if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0") || preg_match('#^([a-zA-Z]:|/|\\\\)#', $relative)) {
                throw new RuleViolation("Unsafe path in backup: {$entry}");
            }
            $destination = rtrim($target, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0700, true);
            }
            $this->extractEntry($zip, $entry, $destination);
            $count++;
        }

        return $count;
    }

    private function openForReading(string $path, string $password): ZipArchive
    {
        $zip = new ZipArchive;
        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuleViolation('The backup file could not be opened.');
        }
        $zip->setPassword($password);

        return $zip;
    }

    private function extractEntry(ZipArchive $zip, string $entry, string $destination): void
    {
        $in = $zip->getStream($entry);
        $out = fopen($destination, 'wb');
        if (! $in || ! $out) {
            throw new RuleViolation("Could not read {$entry} from the backup (wrong password?).");
        }
        try {
            if (stream_copy_to_stream($in, $out) === false) {
                throw new RuleViolation("Could not read {$entry} from the backup (wrong password?).");
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** Reads the database file back out of the finished zip and compares checksums. */
    private function verify(string $path, string $sha256): void
    {
        $zip = $this->openForReading($path, (string) config('backup.password'));
        $temp = tempnam(sys_get_temp_dir(), 'mtl-verify-');
        try {
            $this->extractEntry($zip, 'database.sql', $temp);
            if (! hash_equals($sha256, hash_file('sha256', $temp))) {
                throw new RuntimeException('The backup could not be read back correctly.');
            }
        } finally {
            $zip->close();
            @unlink($temp);
        }
    }

    /** @return array<string, list<string>> relative paths added, per disk */
    private function addFiles(ZipArchive $zip, string $password): array
    {
        $added = [];
        $backupDir = realpath($this->directory()) ?: $this->directory();
        foreach ((array) config('backup.disks') as $disk) {
            $root = realpath((string) config("filesystems.disks.{$disk}.root"));
            $added[$disk] = [];
            if (! $root || ! is_dir($root)) {
                continue;
            }
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($items as $file) {
                if (! $file->isFile() || $file->isLink()) {
                    continue;
                }
                $absolute = $file->getPathname();
                if (str_starts_with($absolute, $backupDir.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
                if (in_array(explode('/', $relative)[0], (array) config('backup.exclude'), true)) {
                    continue;
                }
                $this->add($zip, $absolute, "files/{$disk}/{$relative}", $password);
                $added[$disk][] = $relative;
            }
        }

        return $added;
    }

    private function add(ZipArchive $zip, string $path, string $entry, string $password): void
    {
        if (! $zip->addFile($path, $entry) || ! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password)) {
            throw new RuntimeException("Could not add {$entry} to the backup.");
        }
    }

    private function prepareDirectory(): string
    {
        $dir = $this->directory();
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new RuntimeException('The backup folder could not be created.');
        }
        // Belt and braces in case the folder ever ends up under the web root.
        if (! is_file($dir.'/.htaccess')) {
            file_put_contents($dir.'/.htaccess', "Require all denied\nDeny from all\n");
        }

        return $dir;
    }

    private function remember(array $status): void
    {
        Cache::forever(self::STATUS_KEY, ['at' => now()->toIso8601String()] + $status);
    }

    /** Error text for the Operations page and audit: no paths or connection details. */
    private function safeMessage(Throwable $e): string
    {
        $message = $e instanceof RuleViolation || ($e instanceof RuntimeException && ! $e instanceof \PDOException) ? $e->getMessage() : class_basename($e).' while creating the backup';

        return Str::limit(str_replace([base_path(), (string) config('backup.password') ?: '§'], ['', '[redacted]'], $message), 300);
    }

    public function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? $bytes : number_format($bytes, 1)).' '.$unit;
            }
            $bytes /= 1024;
        }

        return (string) $bytes;
    }
}
