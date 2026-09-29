<?php

namespace App\Domain\Operations;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * A plain-SQL copy of the database written by PHP, so backups work on shared hosting where mysqldump may not be
 * allowed. Every statement is on one line (text with line breaks or binary data is written as hex), so the file
 * can be replayed line by line without loading it into memory. Supports MySQL/MariaDB and SQLite (tests).
 */
class DatabaseDump
{
    /** Tables whose rows are not worth restoring (they rebuild themselves); their structure is still kept. */
    public const SKIP_ROWS = ['cache', 'cache_locks', 'sessions'];

    private const CHUNK = 250;

    public function __construct(private Connection $db) {}

    /**
     * Writes the dump to an open file handle.
     *
     * @param  resource  $out
     * @return array<string, int> rows written per table
     */
    public function write($out): array
    {
        $mysql = $this->isMysql();
        $this->line($out, '-- Maestro Touch Legal database backup, '.now()->toIso8601String().', driver '.$this->db->getDriverName());
        foreach ($mysql
            ? ["SET NAMES utf8mb4", "SET FOREIGN_KEY_CHECKS=0", "SET UNIQUE_CHECKS=0", "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'", "SET time_zone='+00:00'"]
            : ['PRAGMA foreign_keys=OFF'] as $statement) {
            $this->line($out, $statement.';');
        }

        $counts = [];
        // A consistent snapshot of InnoDB tables while the dump runs.
        $this->db->beginTransaction();
        try {
            foreach ($this->tables() as $table) {
                $this->line($out, $this->oneLine($this->createStatement($table)).';');
                $counts[$table] = in_array($table, self::SKIP_ROWS, true) ? 0 : $this->writeRows($out, $table);
            }
            if (! $mysql) {
                foreach ($this->db->select("SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'") as $index) {
                    $this->line($out, $this->oneLine($index->sql).';');
                }
            }
        } finally {
            $this->db->commit();
        }

        $this->line($out, $mysql ? 'SET FOREIGN_KEY_CHECKS=1;' : 'PRAGMA foreign_keys=ON;');
        $this->line($out, '-- end of backup');

        return $counts;
    }

    /**
     * Replays a dump written by write() into this (empty) database.
     *
     * @return int statements run
     */
    public function load(string $path): int
    {
        if ($this->tables() !== []) {
            throw new RuntimeException('The target database is not empty. Restore only into a new, empty database.');
        }
        $in = fopen($path, 'rb');
        if (! $in) {
            throw new RuntimeException('The database file could not be opened.');
        }

        $run = 0;
        $complete = false;
        // SQLite is far quicker inside one transaction; MySQL commits each CREATE TABLE anyway.
        $sqlite = ! $this->isMysql();
        if ($sqlite) {
            $this->db->unprepared('BEGIN');
        }
        try {
            while (($line = fgets($in)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '-- end of backup') {
                    $complete = true;
                }
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                $this->db->unprepared($line);
                $run++;
            }
            if ($sqlite) {
                $this->db->unprepared('COMMIT');
            }
        } catch (\Throwable $e) {
            if ($sqlite) {
                $this->db->unprepared('ROLLBACK');
            }
            throw $e;
        } finally {
            fclose($in);
        }
        if (! $complete) {
            throw new RuntimeException('The database file is incomplete (no end marker). The restore stopped part-way; discard the target database.');
        }

        return $run;
    }

    /** @return list<string> base tables, in name order */
    public function tables(): array
    {
        $rows = $this->isMysql()
            ? $this->db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
            : $this->db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

        $names = array_map(fn ($row) => (string) array_values((array) $row)[0], $rows);
        sort($names);

        return $names;
    }

    public function count(string $table): int
    {
        return (int) $this->db->table($table)->count();
    }

    private function writeRows($out, string $table): int
    {
        $query = $this->db->table($table);
        foreach ($this->primaryKey($table) as $column) {
            $query->orderBy($column);
        }

        $written = 0;
        $quotedTable = $this->quoteName($table);
        for ($offset = 0; ; $offset += self::CHUNK) {
            $rows = (clone $query)->offset($offset)->limit(self::CHUNK)->get();
            if ($rows->isEmpty()) {
                break;
            }
            $columns = implode(', ', array_map(fn ($c) => $this->quoteName($c), array_keys((array) $rows->first())));
            $values = $rows->map(fn ($row) => '('.implode(', ', array_map(fn ($v) => $this->literal($v), array_values((array) $row))).')');
            $this->line($out, "INSERT INTO {$quotedTable} ({$columns}) VALUES ".$values->implode(', ').';');
            $written += $rows->count();
            if ($rows->count() < self::CHUNK) {
                break;
            }
        }

        return $written;
    }

    private function createStatement(string $table): string
    {
        if ($this->isMysql()) {
            $row = (array) $this->db->selectOne('SHOW CREATE TABLE '.$this->quoteName($table));

            return (string) ($row['Create Table'] ?? array_values($row)[1]);
        }

        return (string) $this->db->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->sql;
    }

    /** @return list<string> */
    private function primaryKey(string $table): array
    {
        if ($this->isMysql()) {
            return array_map(fn ($r) => $r->Column_name,
                $this->db->select('SHOW KEYS FROM '.$this->quoteName($table)." WHERE Key_name = 'PRIMARY'"));
        }
        $columns = array_filter($this->db->select('PRAGMA table_info('.$this->quoteName($table).')'), fn ($c) => (int) $c->pk > 0);
        usort($columns, fn ($a, $b) => $a->pk <=> $b->pk);

        return array_map(fn ($c) => $c->name, $columns);
    }

    private function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value) ? var_export($value, true) : 'NULL',
            default => $this->stringLiteral((string) $value),
        };
    }

    private function stringLiteral(string $value): string
    {
        $utf8 = mb_check_encoding($value, 'UTF-8');
        if ($utf8 && ! preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return $this->db->getPdo()->quote($value);
        }
        $hex = bin2hex($value);
        if ($this->isMysql()) {
            return "0x{$hex}";
        }

        return $utf8 ? "CAST(X'{$hex}' AS TEXT)" : "X'{$hex}'";
    }

    private function quoteName(string $name): string
    {
        return $this->isMysql() ? '`'.str_replace('`', '``', $name).'`' : '"'.str_replace('"', '""', $name).'"';
    }

    private function oneLine(string $sql): string
    {
        return preg_replace('/\s*[\r\n]+\s*/', ' ', trim($sql));
    }

    private function isMysql(): bool
    {
        return in_array($this->db->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function line($out, string $text): void
    {
        if (fwrite($out, $text."\n") === false) {
            throw new RuntimeException('Could not write the database backup (is the disk full?).');
        }
    }
}
