<?php

namespace App\Support;

use Illuminate\Database\Connection;

/**
 * MariaDB before 10.10 (and MySQL with explicit_defaults_for_timestamp off) silently gives the first NOT NULL
 * TIMESTAMP column of a table "ON UPDATE CURRENT_TIMESTAMP", so dates such as an invitation's expiry or an
 * applicant's consent time would be overwritten whenever the row changed (DECISIONS D39). Only updated_at may
 * change by itself; this finds and repairs any other such column. Nothing to do on SQLite.
 */
class TimestampColumns
{
    /** @return list<array{table: string, column: string, precision: int, nullable: bool}> */
    public static function autoUpdating(Connection $db): array
    {
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }

        $rows = $db->select("SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATETIME_PRECISION AS p, IS_NULLABLE AS n FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp' AND LOWER(EXTRA) LIKE '%on update%' AND COLUMN_NAME <> 'updated_at'");

        return array_map(fn ($r) => ['table' => $r->t, 'column' => $r->c, 'precision' => (int) $r->p, 'nullable' => $r->n === 'YES'], $rows);
    }

    /** Keeps each column NOT NULL with its insert default, but stops it changing on update. */
    public static function repair(Connection $db): int
    {
        $columns = self::autoUpdating($db);
        foreach ($columns as $col) {
            $type = $col['precision'] > 0 ? "TIMESTAMP({$col['precision']})" : 'TIMESTAMP';
            $default = $col['precision'] > 0 ? "CURRENT_TIMESTAMP({$col['precision']})" : 'CURRENT_TIMESTAMP';
            $null = $col['nullable'] ? 'NULL' : 'NOT NULL';
            $db->statement("ALTER TABLE `{$col['table']}` MODIFY `{$col['column']}` {$type} {$null} DEFAULT {$default}");
        }

        return count($columns);
    }
}
