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

    public function test_tables_are_created_as_innodb_even_where_the_server_defaults_to_myisam(): void
    {
        $this->assertSame('InnoDB', config('database.connections.mysql.engine'));
        $this->assertSame('InnoDB', config('database.connections.mariadb.engine'));

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }
        $other = DB::table('information_schema.tables')->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_type', 'BASE TABLE')->where('engine', '!=', 'InnoDB')->pluck('table_name')->all();
        $this->assertSame([], $other, 'Tables not using InnoDB (no transactions or foreign keys).');
    }
}
