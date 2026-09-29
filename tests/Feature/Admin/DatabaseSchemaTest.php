<?php

namespace Tests\Feature\Admin;

use App\Support\TimestampColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Checks that only matter on MariaDB/MySQL (run with phpunit.mariadb.xml). */
class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_date_other_than_updated_at_changes_by_itself(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('MariaDB/MySQL only: run with -c phpunit.mariadb.xml.');
        }

        $this->assertSame([], TimestampColumns::autoUpdating(DB::connection()),
            'A NOT NULL timestamp got ON UPDATE CURRENT_TIMESTAMP; call TimestampColumns::repair() in the migration (D39).');
    }
}
