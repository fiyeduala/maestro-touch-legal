<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Conversations;
use App\Domain\Identity\Role;
use App\Domain\Matters\MatterStatus;
use App\Domain\RuleViolation;
use App\Jobs\SendMessageNotices;
use App\Models\AuditEvent;
use App\Models\Message;
use App\Notifications\PortalUpdate;
use App\Notifications\StaffAlert;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

class ConversationsTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('confidential');
        $this->setUpPractice();
    }

    private function chat(): Conversations
    {
        return app(Conversations::class);
    }

    public function test_client_and_team_talk_and_internal_notes_stay_internal(): void
    {
        Bus::fake([SendMessageNotices::class]);
        [$matter, $contact] = $this->openMatter();

        $this->chat()->send($matter, $contact, 'client', 'Hello, any update?');
        $reply = $this->chat()->send($matter, $this->lawyer, 'client', 'Yes, filing tomorrow.');
        $this->chat()->send($matter, $this->lawyer, 'internal', 'SECRET strategy note');
        $note = $this->chat()->callNote($matter, $this->lawyer, 'client', 'phone', 'Called the client to confirm details.');

        $client = $this->chat()->thread($matter, 'client');
        $this->assertSame(['Hello, any update?', 'Yes, filing tomorrow.', 'Called the client to confirm details.'], $client->pluck('body')->all());
        $this->assertFalse($reply->sender_is_client);
        $this->assertSame('call_note', $note->kind);
        $this->assertSame(['SECRET strategy note'], $this->chat()->thread($matter, 'internal')->pluck('body')->all());
        $this->assertSame(0, Message::clientVisible()->where('body', 'like', '%SECRET%')->count());

        Bus::assertDispatchedTimes(SendMessageNotices::class, 3); // client messages only, never internal notes
    }

    public function test_posting_rules(): void
    {
        Bus::fake([SendMessageNotices::class]);
        [$matter, $contact] = $this->openMatter();
        [, $otherContact] = $this->openMatter();
        $outsider = $this->userWithRoles(Role::Lawyer);

        $this->assertThrows(fn () => $this->chat()->send($matter, $contact, 'internal', 'Let me in'), AuthorizationException::class);
        $this->assertThrows(fn () => $this->chat()->send($matter, $otherContact, 'client', 'Wrong matter'), AuthorizationException::class);
        $this->assertThrows(fn () => $this->chat()->send($matter, $outsider, 'client', 'Not my matter'), AuthorizationException::class);
        $this->assertThrows(fn () => $this->chat()->send($matter, $contact, 'client', '   '), RuleViolation::class);
        $this->assertThrows(fn () => $this->chat()->send($matter, $contact, 'client', str_repeat('a', Conversations::MAX_LENGTH + 1)), RuleViolation::class);
        $this->assertThrows(fn () => $this->chat()->callNote($matter, $contact, 'client', 'phone', 'Client call note'), AuthorizationException::class);

        // A full administrator always speaks for the firm.
        $this->assertFalse($this->chat()->send($matter, $this->admin, 'client', 'From the principal')->sender_is_client);

        $matter->forceFill(['status' => MatterStatus::Closed])->save();
        $this->assertThrows(fn () => $this->chat()->send($matter->refresh(), $contact, 'client', 'After closing'), RuleViolation::class);
        $this->assertThrows(fn () => $this->chat()->send($matter, $this->lawyer, 'client', 'After closing'), RuleViolation::class);
    }

    public function test_amendments_keep_the_original_and_are_audited(): void
    {
        Bus::fake([SendMessageNotices::class]);
        [$matter, $contact] = $this->openMatter();
        $original = $this->chat()->send($matter, $contact, 'client', 'My company is Acme Ltd');

        $this->assertThrows(fn () => $this->chat()->amend($original, $this->lawyer, 'Changed by staff'), AuthorizationException::class);
        $fix = $this->chat()->amend($original, $contact, 'Sorry, it is Acme Nigeria Ltd');

        $this->assertSame('My company is Acme Ltd', $original->refresh()->body);
        $this->assertSame($original->id, $fix->amends_message_id);
        $this->assertTrue(AuditEvent::where('action', 'message.amended')->exists());
    }

    public function test_unread_counts_and_read_receipts(): void
    {
        Bus::fake([SendMessageNotices::class]);
        [$matter, $contact] = $this->openMatter();
        $officer = $this->teamMember($matter);

        $this->chat()->send($matter, $this->lawyer, 'client', 'Please send your ID');
        $this->chat()->send($matter, $this->lawyer, 'internal', 'Check the ID carefully');

        $this->assertSame([$matter->id => 1], $this->chat()->clientUnread($contact));
        $this->assertSame([$matter->id => ['client' => 1, 'internal' => 1]], $this->chat()->staffUnread($officer));
        $this->assertSame([], $this->chat()->staffUnread($this->lawyer)); // own messages are read

        $thread = $this->chat()->thread($matter, 'client');
        $this->assertSame([], $this->chat()->readByOtherSide($thread));
        $this->chat()->markRead($thread, $officer); // a colleague reading is not a read receipt
        $this->assertSame([], $this->chat()->readByOtherSide($thread));
        $this->assertSame(1, $this->chat()->markRead($thread, $contact));
        $this->assertSame(0, $this->chat()->markRead($thread, $contact));
        $this->assertArrayHasKey($thread->first()->id, $this->chat()->readByOtherSide($thread));
        $this->assertSame([], $this->chat()->clientUnread($contact));
    }

    public function test_new_message_emails_wait_and_are_not_repeated(): void
    {
        Bus::fake([SendMessageNotices::class]);
        [$matter, $contact] = $this->openMatter();
        $officer = $this->teamMember($matter);

        $first = $this->chat()->send($matter, $contact, 'client', 'First question');
        $second = $this->chat()->send($matter, $contact, 'client', 'Second question');
        (new SendMessageNotices($first->id))->handle(app(\App\Domain\Clients\ClientContacts::class));
        (new SendMessageNotices($second->id))->handle(app(\App\Domain\Clients\ClientContacts::class));
        $alerts = fn ($user) => Notification::sent($user, StaffAlert::class)->filter(fn ($n) => str_starts_with($n->subject, 'New client message'))->count();

        // One alert per unread batch for each team member.
        $this->assertSame(1, $alerts($officer));
        $this->assertSame(1, $alerts($this->lawyer));

        // The lawyer reads and the client writes again: the lawyer is told again, the officer (still unread) is not.
        $this->chat()->markRead($this->chat()->thread($matter, 'client'), $this->lawyer);
        $third = $this->chat()->send($matter, $contact, 'client', 'Third question');
        (new SendMessageNotices($third->id))->handle(app(\App\Domain\Clients\ClientContacts::class));
        $this->assertSame(2, $alerts($this->lawyer));
        $this->assertSame(1, $alerts($officer));

        // A firm reply the client has already read in the portal sends no email.
        $reply = $this->chat()->send($matter, $this->lawyer, 'client', 'Answer');
        $this->chat()->markRead($this->chat()->thread($matter, 'client'), $contact);
        (new SendMessageNotices($reply->id))->handle(app(\App\Domain\Clients\ClientContacts::class));
        Notification::assertNotSentTo($contact, PortalUpdate::class, fn ($n) => str_contains($n->subject ?? '', 'new message'));

        $unread = $this->chat()->send($matter, $this->lawyer, 'client', 'Another answer with private details');
        (new SendMessageNotices($unread->id))->handle(app(\App\Domain\Clients\ClientContacts::class));
        Notification::assertSentTo($contact, PortalUpdate::class, function ($n) use ($contact) {
            $mail = $n->toMail($contact);

            return str_contains($mail->subject, 'new message') && ! str_contains(implode(' ', $mail->introLines), 'private details');
        });
    }
}
