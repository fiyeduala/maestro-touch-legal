<?php

namespace Tests\Feature\Portal;

use App\Domain\Communication\Conversations;
use App\Domain\Operations\Settings;
use App\Jobs\SendMessageNotices;
use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

/** The client's side of Phase 4: matter chat, the messages list, appointments and recap preferences. */
class PortalConversationTest extends TestCase
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
    }

    public function test_client_sees_the_conversation_but_never_internal_notes(): void
    {
        [$matter, $contact] = $this->openMatter();
        $chat = app(Conversations::class);
        $chat->send($matter, $this->lawyer, 'client', 'Please confirm your address');
        $chat->send($matter, $this->lawyer, 'internal', 'INTERNAL: client seems unsure');

        $this->actingAs($contact)->get(route('portal.matters.show', $matter))
            ->assertOk()
            ->assertSee('Please confirm your address')
            ->assertSee('(Maestro Touch Legal)')
            ->assertDontSee('INTERNAL');

        // Viewing marks the messages read.
        $this->assertSame([], $chat->clientUnread($contact));

        $newer = $chat->send($matter, $this->lawyer, 'client', 'Thanks, one more question');
        $chat->send($matter, $this->lawyer, 'internal', 'INTERNAL second note');
        $this->actingAs($contact)->getJson(route('portal.matters.messages.poll', $matter).'?after='.($newer->id - 1))
            ->assertOk()
            ->assertJsonPath('last_id', $newer->id)
            ->assertSee('one more question')
            ->assertDontSee('INTERNAL');
    }

    public function test_client_posts_and_other_clients_are_refused(): void
    {
        [$matter, $contact] = $this->openMatter();
        [, $stranger] = $this->openMatter();

        $this->actingAs($contact)->post(route('portal.matters.messages.store', $matter), ['body' => 'Here is my update'])
            ->assertRedirect(route('portal.matters.show', $matter).'#messages');
        $message = Message::firstOrFail();
        $this->assertTrue($message->sender_is_client);
        $this->assertSame('client', $message->audience);

        $this->actingAs($contact)->postJson(route('portal.matters.messages.store', $matter), ['body' => ''])->assertStatus(422);

        $this->actingAs($stranger)->post(route('portal.matters.messages.store', $matter), ['body' => 'Sneaky'])->assertForbidden();
        $this->actingAs($stranger)->getJson(route('portal.matters.messages.poll', $matter))->assertForbidden();
        $this->actingAs($stranger)->get(route('portal.matters.show', $matter))->assertForbidden();
        $this->assertSame(1, Message::count());

        // Staff are sent to the staff panel.
        $this->actingAs($this->admin)->post(route('portal.matters.messages.store', $matter), ['body' => 'As client'])->assertRedirect(url('/admin'));
        $this->assertSame(1, Message::count());
    }

    public function test_messages_list_shows_unread_counts(): void
    {
        [$matter, $contact] = $this->openMatter();
        app(Conversations::class)->send($matter, $this->lawyer, 'client', 'Update');
        app(Conversations::class)->send($matter, $this->lawyer, 'internal', 'Note');

        $this->actingAs($contact)->get(route('portal.messages'))->assertOk()->assertSee($matter->title)->assertSee('1 unread');
        $this->actingAs($contact)->get(route('portal.home'))->assertSee('New message from the firm');
    }

    public function test_appointments_booking_and_changes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 08:00', 'UTC'));
        $this->seed(\Database\Seeders\ConsultationTypeSeeder::class);
        $type = ConsultationType::firstOrFail();
        [$matter, $contact] = $this->openMatter();
        [, $stranger] = $this->openMatter();
        $slot = Carbon::parse('2026-10-05 10:30', 'Africa/Lagos')->utc()->toIso8601String();

        $this->actingAs($contact)->get(route('portal.appointments'))->assertOk()->assertSee('Request a Consultation')->assertSee($slot, false);

        $this->actingAs($contact)->post(route('portal.appointments.store'), [
            'client_id' => $matter->client_id, 'type_id' => $type->id, 'slot' => $slot, 'matter_id' => $matter->id, 'agenda' => 'Share structure',
        ])->assertRedirect(route('portal.appointments'))->assertSessionHasNoErrors();
        $booking = Consultation::firstOrFail();
        $this->assertSame($matter->id, $booking->matter_id);

        // Another client cannot book for this client or touch the booking.
        $this->actingAs($stranger)->post(route('portal.appointments.store'), [
            'client_id' => $matter->client_id, 'type_id' => $type->id, 'slot' => $slot,
        ])->assertSessionHasErrors('slot');
        $this->actingAs($stranger)->post(route('portal.appointments.cancel', $booking))->assertForbidden();
        $this->actingAs($stranger)->get(route('portal.appointments'))->assertDontSee('Share structure');

        $this->actingAs($contact)->post(route('portal.appointments.cancel', $booking))->assertRedirect(route('portal.appointments'));
        $this->assertSame('cancelled', $booking->refresh()->status);
    }

    public function test_recap_preference_is_per_client_record(): void
    {
        [$matter, $contact] = $this->openMatter();
        $this->actingAs($contact)->get(route('portal.profile'))->assertSee('Daily Message Recap');

        $this->actingAs($contact)->put(route('portal.profile.recaps'), ['recaps' => [$matter->client_id => 'summary']])->assertSessionHasNoErrors();
        $this->assertSame('summary', $contact->clients()->first()->pivot->digest_mode);
    }

    public function test_live_chat_widget_is_never_loaded_in_the_portal(): void
    {
        [$matter, $contact] = $this->openMatter();
        Settings::set([
            'integrations.tawk_enabled' => true,
            'integrations.tawk_property_id' => '64f1a2b3c4d5e6f7a8b9c0d1',
            'integrations.tawk_widget_id' => '1h9abcdef',
        ]);

        foreach ([route('portal.home'), route('portal.messages'), route('portal.appointments'), route('portal.matters.show', $matter)] as $url) {
            $this->actingAs($contact)->get($url)->assertOk()->assertDontSee('embed.tawk.to', false);
        }
    }
}
