<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\Role;
use App\Domain\Operations\Backups;
use App\Domain\RuleViolation;
use App\Filament\Pages\Operations;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/** Encrypted backups, restore into empty targets only, and the audited download (D38). */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mtl-backup-test-'.uniqid();
        foreach (['local', 'confidential', 'media', 'public'] as $disk) {
            mkdir("{$this->work}/disks/{$disk}", 0777, true);
            config(["filesystems.disks.{$disk}.root" => "{$this->work}/disks/{$disk}"]);
        }
        config(['backup.path' => "{$this->work}/backups", 'backup.password' => 'test-backup-password-123', 'backup.keep' => 2]);
    }

    protected function tearDown(): void
    {
        $this->dropRestoreTarget();
        (new Filesystem)->deleteDirectory($this->work);
        parent::tearDown();
    }

    public function test_no_backup_is_written_without_a_password(): void
    {
        config(['backup.password' => null]);

        $this->expectException(RuleViolation::class);
        app(Backups::class)->create();
    }

    public function test_backup_is_encrypted_and_restores_into_empty_targets(): void
    {
        User::factory()->create(['name' => "Line one\nLine two – O'Brien"]);
        mkdir("{$this->work}/disks/confidential/matters/7", 0777, true);
        file_put_contents("{$this->work}/disks/confidential/matters/7/letter.pdf", "%PDF-1.4 secret\x00\x01");
        mkdir("{$this->work}/disks/local/livewire-tmp");
        file_put_contents("{$this->work}/disks/local/livewire-tmp/upload.tmp", 'temporary');

        $result = app(Backups::class)->create();
        $path = "{$this->work}/backups/{$result['file']}";
        $this->assertFileExists($path);
        $this->assertSame(1, $result['files'], 'Temporary uploads are left out.');
        $this->assertTrue(app(Backups::class)->lastRun()['ok']);
        $this->assertDatabaseHas('audit_events', ['action' => 'backup.created']);

        // Every entry is AES-256 encrypted; nothing can be read without the password.
        $zip = new ZipArchive;
        $zip->open($path);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertSame(ZipArchive::EM_AES_256, $zip->statIndex($i)['encryption_method'], $zip->getNameIndex($i));
        }
        $this->assertFalse($zip->getFromName('manifest.json'));
        $zip->close();

        // A wrong password is refused.
        $this->assertThrows(fn () => app(Backups::class)->restore($path, null, "{$this->work}/wrong", 'not the password'), RuleViolation::class);

        // Database and files restore into empty targets, and the row counts match.
        $connection = $this->restoreTarget();
        $report = app(Backups::class)->restore($path, $connection, "{$this->work}/restored");
        $this->assertSame([], $report['mismatches']);
        $this->assertSame(User::count(), DB::connection($connection)->table('users')->count());
        $this->assertSame("Line one\nLine two – O'Brien", DB::connection($connection)->table('users')->where('name', 'like', 'Line one%')->value('name'));
        $this->assertSame("%PDF-1.4 secret\x00\x01", file_get_contents("{$this->work}/restored/confidential/matters/7/letter.pdf"));

        // Never over something that already exists.
        $this->assertThrows(fn () => app(Backups::class)->restore($path, $connection, null), RuleViolation::class, 'not empty');
        $this->assertThrows(fn () => app(Backups::class)->restore($path, null, "{$this->work}/restored"), RuleViolation::class, 'new or empty');
    }

    public function test_only_the_newest_backups_are_kept(): void
    {
        mkdir("{$this->work}/backups");
        foreach (['20260101-010000', '20260102-010000', '20260103-010000'] as $stamp) {
            file_put_contents("{$this->work}/backups/mtl-backup-{$stamp}.zip", 'x');
        }
        file_put_contents("{$this->work}/backups/keep-me.txt", 'not a backup');

        $this->assertSame(1, app(Backups::class)->prune());
        $this->assertSame(['mtl-backup-20260103-010000.zip', 'mtl-backup-20260102-010000.zip'], array_column(app(Backups::class)->list(), 'name'));
        $this->assertFileExists("{$this->work}/backups/keep-me.txt");
        $this->assertNull(app(Backups::class)->find('../.env'));
    }

    public function test_only_a_technical_administrator_downloads_after_confirming_their_password(): void
    {
        $name = app(Backups::class)->create(withFiles: false)['file'];
        $tech = $this->userWithRoles(Role::TechnicalAdministrator);
        $principal = $this->userWithRoles(Role::FirmPrincipal);

        $this->actingAsStaff($principal);
        Livewire::test(Operations::class)->assertOk()->assertSee('Backups')->assertActionHidden('downloadBackup');

        $this->actingAsStaff($tech);
        Livewire::test(Operations::class)->assertActionVisible('downloadBackup')
            ->callAction('downloadBackup', ['name' => $name, 'current_password' => 'wrong'])
            ->assertHasActionErrors(['current_password']);
        Livewire::test(Operations::class)
            ->callAction('downloadBackup', ['name' => $name, 'current_password' => 'password'])
            ->assertRedirectContains('/admin/download/backups/');

        $signed = URL::temporarySignedRoute('admin.backup-download', now()->addMinutes(5), ['name' => $name, 'user' => $tech->id]);
        $this->get($signed)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('audit_events', ['action' => 'backup.downloaded', 'actor_id' => $tech->id]);

        // Unsigned, expired, or someone else's link: refused.
        $this->get(route('admin.backup-download', ['name' => $name, 'user' => $tech->id]))->assertForbidden();
        $this->travel(6)->minutes();
        $this->get($signed)->assertForbidden();
        $this->travelBack();
        $this->actingAsStaff($principal)->get(URL::temporarySignedRoute('admin.backup-download', now()->addMinutes(5), ['name' => $name, 'user' => $principal->id]))->assertForbidden();
    }

    public function test_the_commands_back_up_and_refuse_to_restore_over_the_live_database(): void
    {
        $this->artisan('mtl:backup', ['--database-only' => true])->assertSuccessful();
        $name = app(Backups::class)->list()[0]['name'];

        $this->artisan('mtl:restore', ['file' => $name])->assertFailed();
        $this->artisan('mtl:restore', ['file' => $name, '--db-name' => config('database.connections.'.config('database.default').'.database')])->assertFailed();
    }

    /** An empty database of the same kind as the one under test. */
    private function restoreTarget(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            touch("{$this->work}/restore.sqlite");
            config(['database.connections.restore_test' => ['driver' => 'sqlite', 'database' => "{$this->work}/restore.sqlite", 'prefix' => '', 'foreign_key_constraints' => true]]);
        } else {
            config(['database.connections.restore_test' => array_merge(config('database.connections.'.config('database.default')), ['database' => 'mtl_restore_test'])]);
            $this->dropRestoreTarget();
        }

        return 'restore_test';
    }

    private function dropRestoreTarget(): void
    {
        if (! config('database.connections.restore_test') || config('database.connections.restore_test.driver') === 'sqlite') {
            DB::purge('restore_test');

            return;
        }
        $db = DB::connection('restore_test');
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $db->statement('DROP TABLE `'.array_values((array) $row)[0].'`');
        }
        $db->statement('SET FOREIGN_KEY_CHECKS=1');
        DB::purge('restore_test');
    }
}
