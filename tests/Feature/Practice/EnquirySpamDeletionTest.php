<?php

namespace Tests\Feature\Practice;

use App\Domain\Intake\Enquiries;
use App\Domain\RuleViolation;
use App\Filament\Resources\Enquiries\Pages\ListEnquiries;
use App\Filament\Resources\Enquiries\Pages\ViewEnquiry;
use App\Models\AuditEvent;
use App\Models\Enquiry;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

/** Spam enquiries can be deleted by a full administrator; anything that became work cannot (D48). */
class EnquirySpamDeletionTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpPractice();
    }

    public function test_spam_is_deleted_with_its_history_and_the_audit_log_keeps_the_reference(): void
    {
        $spam = $this->enquiry(['contact_name' => 'Cheap Pills']);
        app(Enquiries::class)->addNote($spam, 'Looks like spam.', $this->admin);

        app(Enquiries::class)->deleteAsSpam($spam, $this->admin);

        $this->assertNull(Enquiry::find($spam->id));
        $this->assertSame(0, Party::where('enquiry_id', $spam->id)->count());
        $this->assertSame(0, Party::where('name', 'Cheap Pills')->count());
        $this->assertTrue(AuditEvent::where('action', 'enquiry.deleted_as_spam')->where('summary', 'like', "%{$spam->reference}%")->exists());
    }

    public function test_only_full_administrators_and_never_enquiries_that_became_work(): void
    {
        $owned = $this->enquiry();
        app(Enquiries::class)->assign($owned, $this->lawyer, $this->admin);
        try {
            app(Enquiries::class)->deleteAsSpam($owned->refresh(), $this->lawyer);
            $this->fail('A lawyer deleted an enquiry.');
        } catch (RuleViolation) {
        }

        $real = $this->readyEnquiry();
        $this->expectException(RuleViolation::class);
        try {
            app(Enquiries::class)->deleteAsSpam($real, $this->admin);
        } finally {
            $this->assertNotNull(Enquiry::find($real->id));
            $this->assertNotNull(Enquiry::find($owned->id));
        }
    }

    public function test_admin_screens_delete_one_or_many_and_skip_linked_ones(): void
    {
        $one = $this->enquiry();
        $many = [$this->enquiry(), $this->enquiry()];
        $real = $this->readyEnquiry();

        $this->actingAs($this->admin);
        Livewire::test(ViewEnquiry::class, ['record' => $one->getRouteKey()])->callAction('deleteSpam')->assertRedirect();
        $this->assertNull(Enquiry::find($one->id));

        Livewire::test(ListEnquiries::class)->callTableBulkAction('deleteSpam', [...$many, $real]);
        $this->assertSame([$real->id], Enquiry::pluck('id')->all());

        $this->actingAs($this->lawyer);
        Livewire::test(ViewEnquiry::class, ['record' => $real->getRouteKey()])->assertActionHidden('deleteSpam');
    }
}
