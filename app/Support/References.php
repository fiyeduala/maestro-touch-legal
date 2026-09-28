<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Human-readable yearly references such as ENQ-2026-0001. Call inside the creating
 * transaction; the table's unique index is the final guard against a race.
 */
final class References
{
    public static function next(string $table, string $prefix, string $column = 'reference'): string
    {
        $year = now()->year;
        $like = "{$prefix}-{$year}-%";
        $last = DB::table($table)->where($column, 'like', $like)->lockForUpdate()->max($column);
        $number = $last ? (int) substr($last, strlen("{$prefix}-{$year}-")) : 0;

        do {
            $number++;
            $reference = sprintf('%s-%d-%04d', $prefix, $year, $number);
        } while (DB::table($table)->where($column, $reference)->exists());

        return $reference;
    }
}
