<?php

use App\Support\TimestampColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// See App\Support\TimestampColumns (DECISIONS D39). Must stay the last migration that creates timestamp columns,
// or be followed by another call to repair() in any later migration that adds NOT NULL timestamps.
return new class extends Migration
{
    public function up(): void
    {
        TimestampColumns::repair(DB::connection());
    }

    public function down(): void
    {
        // Nothing to undo: the automatic update was never wanted.
    }
};
