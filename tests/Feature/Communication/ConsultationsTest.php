<?php

namespace Tests\Feature\Communication;

use App\Domain\Consultations\Consultations;
use App\Domain\Intake\EnquiryStatus;
use App\Domain\Operations\Settings;
use App\Domain\RuleViolation;
use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Notifications\ConsultationNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

class ConsultationsTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    private ConsultationType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('confidential');
        $this->setUpPractice();
        $this->seed(\Database\Seeders\ConsultationTypeSeeder::class);
        $this->type = ConsultationType::firstOrFail();
        // Thursday 1 October 2026, 09:00 WAT.
        $this->travelTo(Carbon::parse('2026-10-01 08:00', 'UTC'));
    }

    private function bookings(): Consultations
    {
        return app(Consultations::class);
    }

    private function at(string $lagosTime): Carbon
    {
        return Carbon::parse($lagosTime, 'Africa/Lagos')->utc();
    }

    public function test_default_hours_are_24_7_up_to_midnight(): void
    {
        $slots = $this->bookings()->slots($this->type);

        $saturday = collect($slots['2026-10-03'])->map(fn ($s) => $s->timezone('Africa/Lagos')->format('H:i'))->all();
        $this->assertSame('00:00', $saturday[0]);
        $this->assertSame('23:15', end($saturday)); // 45-minute steps; the last one ends by midnight
        $this->assertArrayHasKey('2026-10-04', $slots); // Sunday
    }

    public function test_slots_follow_hours_notice_buffer_and_blocked_dates(): void
    {
        $weekdays = collect(range(1, 5))->map(fn ($d) => ['day' => (string) $d, 'start' => '09:00', 'end' => '17:00'])->all();
        Settings::set(['consultations.hours' => $weekdays]);
        $slots = $this->bookings()->slots($this->type);

        $this->assertArrayNotHasKey('2026-10-01', $slots); // inside the 24-hour notice period
        $this->assertArrayNotHasKey('2026-10-03', $slots); // Saturday
        $friday = collect($slots['2026-10-02'])->map(fn ($s) => $s->timezone('Africa/Lagos')->format('H:i'))->all();
        $this->assertSame('09:00', $friday[0]);
        $this->assertSame('09:45', $friday[1]); // 30 minutes + 15 minute buffer
        $this->assertSame('16:30', end($friday)); // must finish by 17:00
        $this->assertArrayNotHasKey('2026-11-05', $slots); // beyond 30 days

        Settings::set(['consultations.blocked' => [['from' => '2026-10-05', 'to' => '2026-10-06', 'reason' => 'Court']]]);
        $slots = $this->bookings()->slots($this->type);
        $this->assertArrayNotHasKey('2026-10-05', $slots);
        $this->assertArrayNotHasKey('2026-10-06', $slots);
        $this->assertArrayHasKey('2026-10-07', $slots);
    }

    public function test_the_same_slot_cannot_be_booked_twice(): void
    {
        [$matterA, $contactA] = $this->openMatter();
        [$matterB, $contactB] = $this->openMatter();
        $slot = $this->at('2026-10-02 10:30');

        $booking = $this->bookings()->request($contactA, $matterA->client, $this->type, $slot, 'Discuss my filing');
        $this->assertSame('requested', $booking->status);
        $this->assertStringStartsWith('CON', $booking->reference);
        Notification::assertSentTo($contactA, ConsultationNotice::class, fn ($n) => $n->kind === 'requested');

        $this->assertThrows(fn () => $this->bookings()->request($contactB, $matterB->client, $this->type, $slot, null), RuleViolation::class);
        // The buffer also protects the neighbouring slots.
        $this->assertNotContains($slot->toIso8601String(), collect($this->bookings()->slots($this->type)['2026-10-02'])->map->toIso8601String()->all());

        // A cancelled booking frees the slot.
        $this->bookings()->cancel($booking, null, $contactA);
        $this->bookings()->request($contactB, $matterB->client, $this->type, $slot, null);
        $this->assertSame(1, Consultation::active()->count());
    }

    public function test_clients_book_only_for_themselves_and_admins_never_as_clients(): void
    {
        [$matterA, $contactA] = $this->openMatter();
        [$matterB] = $this->openMatter();
        $slot = $this->at('2026-10-02 09:00');

        $this->assertThrows(fn () => $this->bookings()->request($contactA, $matterB->client, $this->type, $slot, null), AuthorizationException::class);
        $this->assertThrows(fn () => $this->bookings()->request($this->admin, $matterA->client, $this->type, $slot, null), AuthorizationException::class);
        $this->assertThrows(fn () => $this->bookings()->request($contactA, $matterA->client, $this->type, $this->at('2026-10-02 09:10'), null), RuleViolation::class);
    }

    public function test_staff_schedule_for_an_enquiry_confirm_and_invite(): void
    {
        $enquiry = $this->readyEnquiry();
        $booking = $this->bookings()->schedule([
            'type_id' => $this->type->id, 'starts_at' => $this->at('2026-10-01 15:00'), 'enquiry_id' => $enquiry->id, 'host_id' => $this->lawyer->id,
        ], $this->lawyer);

        $this->assertSame(EnquiryStatus::ConsultationScheduled, $enquiry->refresh()->status);
        $this->assertSame($enquiry->contact_email, $booking->contact_email);

        // The host cannot be double-booked, even outside published hours.
        $this->assertThrows(fn () => $this->bookings()->schedule([
            'type_id' => $this->type->id, 'starts_at' => $this->at('2026-10-01 15:20'), 'enquiry_id' => $enquiry->id, 'host_id' => $this->lawyer->id,
        ], $this->lawyer), RuleViolation::class);

        $this->assertThrows(fn () => $this->bookings()->confirm($booking, null, 'http://meet.example.test/abc', $this->lawyer), RuleViolation::class);
        $this->bookings()->confirm($booking, null, 'https://meet.example.test/abc', $this->lawyer);
        $this->assertSame('confirmed', $booking->refresh()->status);
        $this->assertSame(1, $booking->sequence);

        Notification::assertSentOnDemand(ConsultationNotice::class, function (ConsultationNotice $n, $channels, $notifiable) use ($enquiry) {
            if ($n->kind !== 'confirmed') {
                return false;
            }
            $mail = $n->toMail($notifiable);
            $this->assertSame($enquiry->contact_email, $notifiable->routes['mail']);
            $this->assertStringContainsString('BEGIN:VCALENDAR', $mail->rawAttachments[0]['data']);
            $this->assertSame('https://meet.example.test/abc', $mail->actionUrl);

            return true;
        });
    }

    public function test_client_changes_respect_the_cut_off_and_need_reconfirming(): void
    {
        [$matter, $contact] = $this->openMatter();
        $booking = $this->bookings()->request($contact, $matter->client, $this->type, $this->at('2026-10-05 10:30'), null);
        $this->bookings()->confirm($booking, $this->lawyer->id, null, $this->admin);

        $this->bookings()->reschedule($booking->refresh(), $this->at('2026-10-06 11:15'), $contact);
        $booking->refresh();
        $this->assertSame('requested', $booking->status);
        $this->assertSame(2, $booking->sequence);
        $this->assertTrue($booking->starts_at->equalTo($this->at('2026-10-06 11:15')));

        $this->travelTo($this->at('2026-10-06 00:00')); // 11 hours before; the cut-off is 12
        $this->assertThrows(fn () => $this->bookings()->cancel($booking, null, $contact), RuleViolation::class);
        $this->assertThrows(fn () => $this->bookings()->cancel($booking, 'x', $this->lawyer), RuleViolation::class); // staff must give a reason
        $this->bookings()->cancel($booking, 'Client unwell, rebook', $this->lawyer);
        $this->assertSame('cancelled', $booking->refresh()->status);
    }

    public function test_reminders_are_sent_once_for_the_latest_threshold(): void
    {
        [$matter, $contact] = $this->openMatter();
        $booking = $this->bookings()->request($contact, $matter->client, $this->type, $this->at('2026-10-05 10:30'), null);
        $this->assertSame(0, $this->bookings()->sendReminders()); // not confirmed yet
        $this->bookings()->confirm($booking, $this->lawyer->id, null, $this->admin);

        $this->travelTo($this->at('2026-10-05 09:00')); // 1.5 hours before: both the 24h and 2h thresholds have passed
        $this->assertSame(1, $this->bookings()->sendReminders());
        $this->assertSame(0, $this->bookings()->sendReminders());
        Notification::assertSentToTimes($contact, ConsultationNotice::class, 3); // requested, confirmed, one reminder

        // A contact who lost access gets no reminder.
        $other = $this->bookings()->request($contact, $matter->client, $this->type, $this->at('2026-10-07 10:30'), null);
        $this->bookings()->confirm($other, $this->lawyer->id, null, $this->admin);
        app(\App\Domain\Clients\ClientContacts::class)->revoke($matter->client, $contact, $this->admin, 'Left the company');
        $this->travelTo($this->at('2026-10-06 11:00'));
        $this->assertSame(0, $this->bookings()->sendReminders());
    }

    public function test_outcomes_are_recorded_after_the_start(): void
    {
        [$matter, $contact] = $this->openMatter();
        $booking = $this->bookings()->request($contact, $matter->client, $this->type, $this->at('2026-10-05 10:30'), null);
        $this->assertThrows(fn () => $this->bookings()->recordOutcome($booking, 'completed', 'Went well', $this->admin), RuleViolation::class);

        $this->travelTo($this->at('2026-10-05 11:00'));
        $this->bookings()->recordOutcome($booking, 'no_show', null, $this->admin);
        $this->assertSame('no_show', $booking->refresh()->status);
    }
}
