<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\Role;
use App\Domain\Recruitment\ApplicationStatus;
use App\Models\StaffApplication;
use App\Models\StaffApplicationFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationFileDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function file(): StaffApplicationFile
    {
        Storage::fake('local');
        Storage::disk('local')->put('staff-applications/cv.pdf', '%PDF-1.4 test');

        $application = StaffApplication::create([
            'reference' => 'APP-TEST1', 'status' => ApplicationStatus::Submitted, 'full_name' => 'Ada Obi',
            'email' => 'ada@example.com', 'phone' => '+2348000000000', 'location' => 'Lagos',
            'professional_category' => 'lawyer', 'practice_areas' => ['corporate'], 'years_experience' => 3,
            'qualifications' => 'LLB, BL', 'consent_at' => now(), 'consent_version' => 'test',
            'applicant_token_hash' => str_repeat('b', 64),
        ]);

        return StaffApplicationFile::create([
            'staff_application_id' => $application->id, 'kind' => 'cv', 'disk' => 'local',
            'path' => 'staff-applications/cv.pdf', 'original_name' => 'cv.pdf', 'mime_type' => 'application/pdf',
            'size' => 13, 'sha256' => str_repeat('c', 64), 'created_at' => now(),
        ]);
    }

    public function test_full_administrator_downloads_and_it_is_audited(): void
    {
        $file = $this->file();

        $this->actingAsStaff($this->userWithRoles(Role::FirmPrincipal))
            ->get(route('admin.application-file', $file))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->assertDownload('cv.pdf');

        $this->assertDatabaseHas('audit_events', ['action' => 'staff_application.file_downloaded']);
    }

    public function test_other_staff_guests_and_unverified_sessions_are_refused(): void
    {
        $file = $this->file();
        $url = route('admin.application-file', $file);

        $this->get($url)->assertRedirect();
        $this->actingAsStaff($this->userWithRoles(Role::Lawyer, Role::ContentEditor))->get($url)->assertForbidden();
        $this->actingAs($this->userWithRoles(Role::FirmPrincipal))->get($url)->assertRedirect('/admin/login');
        $this->assertDatabaseMissing('audit_events', ['action' => 'staff_application.file_downloaded']);
    }
}
