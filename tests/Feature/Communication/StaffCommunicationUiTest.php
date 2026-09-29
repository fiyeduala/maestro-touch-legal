<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Conversations;
use App\Domain\Identity\Role;
use App\Domain\Operations\Settings;
use App\Filament\Pages\Operations;
use App\Filament\Pages\SiteSettings;
use App\Filament\Resources\Consultations\Pages\ListConsultations;
use App\Filament\Resources\ConsultationTypes\Pages\CreateConsultationType;
use App\Filament\Resources\Matters\Pages\MatterConversation;
use App\Jobs\SendMessageNotices;
use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Models\Digest;
use App\Models\DigestRun;
use App\Models\Message;
use App\Notifications\ConsultationNotice;
use Database\Seeders\ConsultationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

/** The staff screens for Phase 4: conversations, consultations, recap operations and their settings. */
class StaffCommunicationUiTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('confidential');
        Notification::fake();
        Bus::fake([SendMessageNotices::class]);
        $this->setUpPractice();
        $this->seed(ConsultationTypeSeeder::class);
    }

    public function test_admin_can_open_every_phase_four_page(): void
    {
        [$matter] = $this->openMatter();
        app(Conversations::class)->send($matter, $this->lawyer, 'client', 'Hello');

        $this->actingAsStaff($this->admin);
        foreach (['/admin/messages', "/admin/matters/{$matter->id}/conversation", '/admin/consultations',
            '/admin/consultation-types', '/admin/consultation-types/create', '/admin/operations', '/admin/settings'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get("/admin/matters/{$matter->id}")->assertOk()->assertSee('Conversation');
    }

    public function test_conversation_page_keeps_internal_notes_separate(): void
    {
        [$matter, $contact] = $this->openMatter();
        $this->actingAsStaff($this->lawyer);

        Livewire::test(MatterConversation::class, ['record' => $matter->id])
            ->set('clientBody', 'Please send your ID')->call('send', 'client')
            ->set('internalBody', 'Check the ID carefully')->call('send', 'internal')
            ->set('noteAudience', 'internal')->set('noteChannel', 'phone')->set('noteBody', 'Client called about fees')->call('recordCall')
            ->assertSee('INTERNAL — never shown to the client')
            ->assertSee('Check the ID carefully');

        $this->assertSame(['client' => 1, 'internal' => 2],
            Message::where('matter_id', $matter->id)->get()->countBy('audience')->sortKeys()->all());
        $this->assertTrue(Message::where('audience', 'internal')->where('kind', 'call_note')->exists());

        // A correction is a new message; the original is untouched.
        $original = Message::where('audience', 'client')->firstOrFail();
        Livewire::test(MatterConversation::class, ['record' => $matter->id])
            ->call('startAmend', $original->id)->set('amendBody', 'Please send your passport')->call('amend');
        $this->assertSame('Please send your ID', $original->refresh()->body);
        $this->assertTrue(Message::where('amends_message_id', $original->id)->exists());

        // The client never sees the internal pane's content.
        $this->actingAs($contact)->get(route('portal.matters.show', $matter))->assertDontSee('Check the ID carefully')->assertDontSee('Client called about fees');
    }

    public function test_conversation_page_is_limited_to_the_matter_team(): void
    {
        [$matter, $contact] = $this->openMatter();
        $outsider = $this->userWithRoles(Role::Lawyer);

        $this->actingAsStaff($outsider)->get("/admin/matters/{$matter->id}/conversation")->assertNotFound();
        $this->actingAs($contact)->get("/admin/matters/{$matter->id}/conversation")->assertForbidden();
        $this->actingAsStaff($outsider)->get('/admin/messages')->assertOk()->assertDontSee($matter->reference);
    }

    public function test_staff_book_confirm_and_cancel_a_consultation(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 08:00', 'UTC'));
        [$matter, $contact] = $this->openMatter();
        $type = ConsultationType::firstOrFail();
        $this->actingAsStaff($this->lawyer);

        Livewire::test(ListConsultations::class)
            ->callAction('schedule', [
                'link' => 'matter', 'matter_id' => $matter->id, 'contact_user_id' => $contact->id,
                'type_id' => $type->id, 'starts_at' => '2026-10-06 11:00:00', 'host_id' => $this->lawyer->id, 'confirm' => false,
            ])
            ->assertHasNoActionErrors();
        $booking = Consultation::firstOrFail();
        $this->assertSame('requested', $booking->status);
        $this->assertTrue($booking->starts_at->equalTo(Carbon::parse('2026-10-06 11:00', 'Africa/Lagos')));

        // A plain-http link is refused; https is accepted and the client is emailed.
        Livewire::test(ListConsultations::class)
            ->callTableAction('confirm', $booking, ['host_id' => $this->lawyer->id, 'meeting_url' => 'http://meet.example.com/abc']);
        $this->assertSame('requested', $booking->refresh()->status);
        Livewire::test(ListConsultations::class)
            ->callTableAction('confirm', $booking, ['host_id' => $this->lawyer->id, 'meeting_url' => 'https://meet.example.com/abc']);
        $this->assertSame('confirmed', $booking->refresh()->status);
        Notification::assertSentTo($contact, ConsultationNotice::class);

        Livewire::test(ListConsultations::class)->callTableAction('cancel', $booking, ['reason' => 'The lawyer is unwell']);
        $this->assertSame('cancelled', $booking->refresh()->status);

        // Another lawyer does not see it.
        $this->actingAsStaff($this->userWithRoles(Role::Lawyer))->get("/admin/consultations/{$booking->id}")->assertNotFound();
    }

    public function test_consultation_types_store_fees_in_minor_units(): void
    {
        $this->actingAsStaff($this->admin);
        Livewire::test(CreateConsultationType::class)
            ->fillForm(['name' => 'Strategy session', 'duration_minutes' => 60, 'is_free' => false, 'fee' => '25,000.50', 'currency' => 'NGN'])
            ->call('create')
            ->assertHasNoFormErrors();

        $type = ConsultationType::where('name', 'Strategy session')->firstOrFail();
        $this->assertSame(2500050, (int) $type->fee_minor);
        $this->assertDatabaseHas('audit_events', ['action' => 'consultation_type.created']);

        $this->actingAsStaff($this->lawyer)->get('/admin/consultation-types')->assertForbidden();
    }

    public function test_consultation_and_recap_settings_save(): void
    {
        $this->actingAsStaff($this->admin);

        Livewire::test(SiteSettings::class)
            ->fillForm(['digest.time' => '7pm'])
            ->call('save')
            ->assertHasFormErrors(['digest.time']);

        Livewire::test(SiteSettings::class)
            ->fillForm([
                'digest.time' => '19:30',
                'digest.firm_recipients' => [$this->admin->email],
                'consultations.buffer_minutes' => '10',
                'consultations.capacity' => '2',
                'consultations.reminder_hours' => ['24', '3'],
                'consultations.blocked' => [['from' => '2026-12-24', 'to' => '2026-12-26', 'reason' => 'Christmas']],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        Settings::flush();
        $this->assertSame('19:30', Settings::get('digest.time'));
        $this->assertSame(10, Settings::get('consultations.buffer_minutes'));
        $this->assertSame(2, Settings::get('consultations.capacity'));
        $this->assertSame([24, 3], Settings::get('consultations.reminder_hours'));
        $this->assertSame('2026-12-24', Settings::get('consultations.blocked')[0]['from']);
        $this->assertCount(5, Settings::get('consultations.hours'));
    }

    public function test_recap_retry_is_admin_only_and_audited(): void
    {
        $run = DigestRun::create(['cutoff_at' => now(), 'window_start' => now()->subDay(), 'status' => 'planned']);
        $digest = Digest::create([
            'digest_run_id' => $run->id, 'kind' => 'firm', 'user_id' => $this->admin->id, 'email' => $this->admin->email,
            'mode' => 'full', 'message_ids' => [], 'window_start' => now()->subDay(), 'window_end' => now(),
            'status' => 'uncertain', 'dedupe_key' => 'test',
        ]);

        $this->actingAsStaff($this->lawyer)->get('/admin/operations')->assertForbidden();

        $this->actingAsStaff($this->admin);
        Livewire::test(Operations::class)->assertSee('Outcome unknown')->callTableAction('retry', $digest);
        $this->assertSame('pending', $digest->refresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'digest.retried']);
    }
}
