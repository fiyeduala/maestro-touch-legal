<?php

namespace Tests\Feature\Communication;

use App\Domain\Consultations\Consultations;
use App\Domain\Identity\Role;
use App\Domain\Meetings\Meetings;
use App\Domain\Meetings\VideoRooms;
use App\Domain\RuleViolation;
use App\Models\AuditEvent;
use App\Models\ConsultationType;
use App\Models\Meeting;
use App\Notifications\ConsultationNotice;
use App\Notifications\MeetingNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

class MeetingsTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('confidential');
        $this->setUpPractice();
        $this->travelTo(Carbon::parse('2026-10-01 08:00', 'UTC'));
        config(['video.daily.api_key' => 'test-key', 'video.daily.domain' => 'mtl-test']);
    }

    private function meetings(): Meetings
    {
        return app(Meetings::class);
    }

    private function fakeDaily(?array $roomConfig = null): void
    {
        Http::fake([
            'api.daily.co/v1/rooms/*' => Http::response(['name' => 'x', 'config' => ['exp' => now()->addYear()->getTimestamp()] + ($roomConfig ?? [])]),
            'api.daily.co/v1/rooms' => fn (Request $r) => Http::response(['name' => $r['name'], 'config' => ($roomConfig ?? [])]),
            'api.daily.co/v1/meeting-tokens' => Http::response(['token' => 'tok-123']),
        ]);
    }

    private function meetingWithClient(): array
    {
        [$matter, $contact] = $this->openMatter();
        $meeting = $this->meetings()->schedule([
            'title' => 'Case review', 'starts_at' => now()->addHour(), 'duration_minutes' => 30,
            'matter_id' => $matter->id, 'client_user_ids' => [$contact->id],
        ], $this->lawyer);

        return [$meeting, $matter, $contact];
    }

    public function test_a_lawyer_schedules_with_a_client_on_their_matter_and_everyone_is_told(): void
    {
        [$meeting, $matter, $contact] = $this->meetingWithClient();

        $this->assertSame($matter->client_id, $meeting->client_id);
        $this->assertTrue($meeting->hasParticipant($contact));
        $this->assertTrue($meeting->hasParticipant($this->lawyer));
        Notification::assertSentTo($contact, MeetingNotice::class);
        Notification::assertNotSentTo($this->lawyer, MeetingNotice::class);
        $this->assertTrue(AuditEvent::where('action', 'meeting.scheduled')->exists());
    }

    public function test_a_lawyer_cannot_link_another_matter_or_a_bare_client_but_an_admin_can(): void
    {
        [$matter, $contact] = $this->openMatter();
        $outsider = $this->userWithRoles(Role::Lawyer);

        $this->assertThrows(fn () => $this->meetings()->schedule([
            'title' => 'Review', 'starts_at' => now()->addHour(), 'duration_minutes' => 30,
            'matter_id' => $matter->id, 'client_user_ids' => [$contact->id],
        ], $outsider), AuthorizationException::class);

        $this->assertThrows(fn () => $this->meetings()->schedule([
            'title' => 'Review', 'starts_at' => now()->addHour(), 'duration_minutes' => 30,
            'client_id' => $matter->client_id, 'client_user_ids' => [$contact->id],
        ], $this->lawyer), RuleViolation::class);

        $this->assertThrows(fn () => $this->meetings()->schedule([
            'title' => 'Review', 'starts_at' => now()->addHour(), 'duration_minutes' => 30, 'client_user_ids' => [$contact->id],
        ], $this->admin), RuleViolation::class);

        $meeting = $this->meetings()->schedule([
            'title' => 'Review', 'starts_at' => now()->addHour(), 'duration_minutes' => 30,
            'client_id' => $matter->client_id, 'client_user_ids' => [$contact->id],
        ], $this->admin);
        $this->assertTrue($meeting->hasParticipant($contact));
    }

    public function test_only_this_clients_contacts_and_active_staff_can_be_invited(): void
    {
        [$matter] = $this->openMatter();
        [, $otherContact] = $this->openMatter();
        $finance = $this->userWithRoles(Role::FinanceOfficer);

        $this->assertThrows(fn () => $this->meetings()->schedule([
            'title' => 'Review', 'starts_at' => now()->addHour(), 'duration_minutes' => 30,
            'matter_id' => $matter->id, 'client_user_ids' => [$otherContact->id],
        ], $this->lawyer), RuleViolation::class);

        $this->assertThrows(fn () => $this->meetings()->schedule([
            'title' => 'Review', 'starts_at' => now()->subHour(), 'duration_minutes' => 30, 'staff_ids' => [$finance->id],
        ], $this->lawyer), RuleViolation::class);

        $staffOnly = $this->meetings()->schedule([
            'title' => 'Team huddle', 'starts_at' => now()->addHour(), 'duration_minutes' => 20, 'staff_ids' => [$finance->id],
        ], $this->lawyer);
        $this->assertNull($staffOnly->client_id);
        $this->assertThrows(fn () => $this->meetings()->invite($staffOnly, ['client_user_ids' => [$otherContact->id]], $this->lawyer), RuleViolation::class);
    }

    public function test_only_the_organiser_or_an_admin_can_change_or_cancel(): void
    {
        [$meeting, $matter] = $this->meetingWithClient();
        $colleague = $this->teamMember($matter, Role::Lawyer);

        $this->assertThrows(fn () => $this->meetings()->cancel($meeting, null, $colleague), AuthorizationException::class);

        $this->meetings()->reschedule($meeting, now()->addHours(2), 45, $this->lawyer);
        $this->assertSame(45, (int) $meeting->refresh()->starts_at->diffInMinutes($meeting->ends_at));

        $this->meetings()->cancel($meeting, 'Client asked to postpone', $this->admin);
        $this->assertSame('cancelled', $meeting->refresh()->status);
        $this->assertThrows(fn () => $this->meetings()->reschedule($meeting, now()->addHours(3), 30, $this->admin), RuleViolation::class);
    }

    public function test_reminders_go_once_shortly_before_the_start(): void
    {
        [$meeting, , $contact] = $this->meetingWithClient();

        $this->assertSame(0, $this->meetings()->sendReminders());
        $this->travelTo($meeting->starts_at->copy()->subMinutes(10));
        $this->assertSame(1, $this->meetings()->sendReminders());
        $this->assertSame(0, $this->meetings()->sendReminders());
        Notification::assertSentTo($contact, MeetingNotice::class, fn (MeetingNotice $n) => $n->kind === 'reminder');
        Notification::assertSentTo($this->lawyer, MeetingNotice::class, fn (MeetingNotice $n) => $n->kind === 'reminder');
    }

    public function test_joining_follows_the_invitation_and_the_time_window(): void
    {
        $this->fakeDaily();
        [$meeting, $matter, $contact] = $this->meetingWithClient();
        $rooms = app(VideoRooms::class);
        $colleague = $this->teamMember($matter, Role::Lawyer);

        $this->assertSame('early', $rooms->joinMeeting($meeting, $contact)['reason']);
        Http::assertNothingSent();

        $this->travelTo($meeting->starts_at->copy()->subMinutes(5));
        $this->assertSame('not_invited', $rooms->joinMeeting($meeting, $colleague)['reason']);

        $joined = $rooms->joinMeeting($meeting, $contact);
        $this->assertTrue($joined['ok']);
        $this->assertSame('tok-123', $joined['token']);
        $this->assertStringStartsWith('https://mtl-test.daily.co/mtg-'.$meeting->id.'-', $joined['url']);
        $room = $meeting->refresh()->video_room;
        $this->assertNotNull($room);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.daily.co/v1/rooms'
            && $r['privacy'] === 'private' && ! array_key_exists('enable_recording', $r['properties']));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'meeting-tokens')
            && $r['properties']['is_owner'] === false && ! array_key_exists('enable_recording', $r['properties']));

        // The organiser joins the same room as its owner.
        $this->assertSame($joined['url'], $rooms->joinMeeting($meeting->refresh(), $this->lawyer)['url']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'meeting-tokens') && $r['properties']['is_owner'] === true);

        $this->travelTo($meeting->ends_at->copy()->addMinutes(61));
        $this->assertSame('over', $rooms->joinMeeting($meeting, $contact)['reason']);
    }

    public function test_video_is_reported_as_not_set_up_without_keys_and_unreachable_on_errors(): void
    {
        [$meeting, , $contact] = $this->meetingWithClient();
        $this->travelTo($meeting->starts_at);
        $rooms = app(VideoRooms::class);

        config(['video.daily.api_key' => '']);
        Http::fake();
        $this->assertSame('not_configured', $rooms->joinMeeting($meeting, $contact)['reason']);
        Http::assertNothingSent();

        config(['video.daily.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);
        $this->assertSame('unreachable', $rooms->joinMeeting($meeting, $contact)['reason']);
    }

    public function test_recording_reported_by_daily_is_audited(): void
    {
        $this->fakeDaily(['enable_recording' => 'cloud']);
        [$meeting, , $contact] = $this->meetingWithClient();
        $this->travelTo($meeting->starts_at);

        $this->assertTrue(app(VideoRooms::class)->joinMeeting($meeting, $contact)['ok']);
        $this->assertTrue(AuditEvent::where('action', 'video.recording_enabled_unexpectedly')->exists());
    }

    public function test_the_join_page_needs_sign_in_and_shows_the_call(): void
    {
        $this->fakeDaily();
        [$meeting, , $contact] = $this->meetingWithClient();
        $this->travelTo($meeting->starts_at);

        $this->get(route('meet.meeting', $meeting))->assertRedirect(route('login'));

        $this->actingAs($contact)->get(route('meet.meeting', $meeting))
            ->assertOk()
            ->assertSee('DailyIframe', false)
            ->assertSee('tok-123', false)
            ->assertHeader('Permissions-Policy');

        [, $other] = $this->openMatter();
        $this->actingAs($other)->get(route('meet.meeting', $meeting))->assertOk()->assertSee('You are not invited to this call');
    }

    public function test_a_video_consultation_gives_a_guest_a_signed_link_that_expires(): void
    {
        $this->fakeDaily();
        $this->seed(\Database\Seeders\ConsultationTypeSeeder::class);
        $type = ConsultationType::firstOrFail();
        $enquiry = $this->readyEnquiry();

        $consultation = app(Consultations::class)->schedule([
            'type_id' => $type->id, 'starts_at' => now()->addHours(3), 'enquiry_id' => $enquiry->id,
            'host_id' => $this->lawyer->id, 'confirm' => true, 'video' => true,
        ], $this->lawyer);
        $this->assertTrue($consultation->video);
        $this->assertNull($consultation->meeting_url);

        $link = VideoRooms::guestLink($consultation);
        Notification::assertSentOnDemand(ConsultationNotice::class);

        $this->get(route('meet.consultation.guest', $consultation))->assertForbidden();
        $this->get($link)->assertOk()->assertSee('Not open yet');

        $this->travelTo($consultation->starts_at);
        $this->get($link)->assertOk()->assertSee('tok-123', false);

        $this->travelTo($consultation->ends_at->copy()->addMinutes(61));
        $this->get($link)->assertForbidden();
    }

    public function test_confirming_a_consultation_as_video_clears_the_pasted_link(): void
    {
        $this->seed(\Database\Seeders\ConsultationTypeSeeder::class);
        $type = ConsultationType::firstOrFail();
        $enquiry = $this->readyEnquiry();
        $bookings = app(Consultations::class);
        $consultation = $bookings->schedule([
            'type_id' => $type->id, 'starts_at' => now()->addHours(3), 'enquiry_id' => $enquiry->id,
            'host_id' => $this->lawyer->id, 'meeting_url' => 'https://meet.example.test/abc',
        ], $this->lawyer);

        $bookings->confirm($consultation, null, 'https://meet.example.test/abc', $this->lawyer, video: true);

        $consultation->refresh();
        $this->assertTrue($consultation->video);
        $this->assertNull($consultation->meeting_url);
        $this->assertSame('confirmed', $consultation->status);
    }

    public function test_staff_see_only_meetings_they_are_in_unless_full_admin(): void
    {
        [$meeting, $matter] = $this->meetingWithClient();
        $colleague = $this->teamMember($matter, Role::Lawyer);

        $this->assertTrue($this->lawyer->can('view', $meeting));
        $this->assertFalse($colleague->can('view', $meeting));
        $this->assertTrue($this->admin->can('view', $meeting));
        $this->assertSame([$meeting->id], Meeting::visibleTo($this->lawyer)->pluck('id')->all());
    }
}
