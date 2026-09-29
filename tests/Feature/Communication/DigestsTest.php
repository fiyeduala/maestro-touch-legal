<?php

namespace Tests\Feature\Communication;

use App\Domain\Clients\ClientContacts;
use App\Domain\Communication\Conversations;
use App\Domain\Communication\Digests;
use App\Domain\Operations\Settings;
use App\Domain\RuleViolation;
use App\Jobs\SendMessageNotices;
use App\Mail\ConversationDigest;
use App\Models\AuditEvent;
use App\Models\Digest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

class DigestsTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        Bus::fake([SendMessageNotices::class]);
        Storage::fake('confidential');
        $this->setUpPractice();
    }

    private function digests(): Digests
    {
        return app(Digests::class);
    }

    private function say(...$args): void
    {
        app(Conversations::class)->send(...$args);
    }

    /** @return array<string, string> recipient email => rendered HTML + text */
    private function sentMail(): array
    {
        $out = [];
        Mail::assertSent(ConversationDigest::class, function (ConversationDigest $mail) use (&$out) {
            $this->assertCount(1, $mail->to);
            $out[$mail->to[0]['address']] = ($out[$mail->to[0]['address']] ?? '').$mail->render().$mail->envelope()->subject;

            return true;
        });

        return $out;
    }

    public function test_cutoff_is_six_pm_lagos_time(): void
    {
        // 18:00 WAT is 17:00 UTC.
        $this->assertEquals(Carbon::parse('2026-09-30 17:00', 'UTC'), $this->digests()->dueCutoff(Carbon::parse('2026-10-01 16:59', 'UTC')));
        $this->assertEquals(Carbon::parse('2026-10-01 17:00', 'UTC'), $this->digests()->dueCutoff(Carbon::parse('2026-10-01 17:00', 'UTC')));
        $this->assertEquals(Carbon::parse('2026-10-01 17:00', 'UTC'), $this->digests()->dueCutoff(Carbon::parse('2026-10-02 03:00', 'UTC')));
    }

    public function test_each_client_gets_only_their_own_conversation_and_never_internal_notes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 09:00', 'UTC'));
        [$matterA, $contactA] = $this->openMatter();
        [$matterB, $contactB] = $this->openMatter();
        $secondContactA = $this->clientContact($matterA->client, 'Bola Ade');
        Settings::set(['digest.firm_recipients' => [$this->admin->email]]);
        $otherAdmin = $this->userWithRoles(\App\Domain\Identity\Role::FirmPrincipal);

        $this->say($matterA, $contactA, 'client', 'ALPHA question');
        $this->say($matterA, $this->lawyer, 'client', 'ALPHA answer');
        $this->say($matterA, $this->lawyer, 'internal', 'INTERNAL-ONLY remark');
        $this->say($matterB, $this->lawyer, 'client', 'BRAVO update');

        $this->travelTo(Carbon::parse('2026-10-01 17:05', 'UTC'));
        $result = $this->digests()->run();
        $this->assertSame(4, $result['sent']); // two contacts of A, one of B, one firm recipient

        $mail = $this->sentMail();
        $this->assertEqualsCanonicalizing([$contactA->email, $secondContactA->email, $contactB->email, $this->admin->email], array_keys($mail));
        $this->assertArrayNotHasKey($otherAdmin->email, $mail);
        $this->assertStringContainsString('ALPHA answer', $mail[$contactA->email]);
        $this->assertStringNotContainsString('BRAVO', $mail[$contactA->email]);
        $this->assertStringNotContainsString('ALPHA', $mail[$contactB->email]);
        $this->assertStringContainsString('BRAVO', $mail[$this->admin->email]);
        $this->assertStringContainsString('ALPHA', $mail[$this->admin->email]);
        foreach ($mail as $body) {
            $this->assertStringNotContainsString('INTERNAL-ONLY', $body);
        }

        // Running again sends nothing new.
        $this->assertSame(0, $this->digests()->run()['sent']);
        Mail::assertSentCount(4);
    }

    public function test_windows_catch_up_and_later_messages_wait(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 09:00', 'UTC'));
        [$matter, $contact] = $this->openMatter();
        $this->say($matter, $this->lawyer, 'client', 'DAY-ONE message');

        $this->travelTo(Carbon::parse('2026-10-01 17:10', 'UTC'));
        $this->digests()->run();
        $this->say($matter, $this->lawyer, 'client', 'AFTER-CUTOFF message');

        // The scheduler was down for two days: the next run covers everything since the last cutoff, once.
        $this->travelTo(Carbon::parse('2026-10-02 12:00', 'UTC'));
        $this->say($matter, $this->lawyer, 'client', 'DAY-TWO message');
        $this->travelTo(Carbon::parse('2026-10-03 18:00', 'UTC'));
        $this->digests()->run();
        $this->digests()->run();

        $digests = Digest::where('kind', 'client')->orderBy('id')->get();
        $this->assertCount(2, $digests);
        $bodies = [];
        Mail::assertSent(ConversationDigest::class, function (ConversationDigest $mail) use (&$bodies) {
            $bodies[] = $mail->render();

            return true;
        });
        $this->assertStringContainsString('DAY-ONE', $bodies[0]);
        $this->assertStringNotContainsString('AFTER-CUTOFF', $bodies[0]);
        $this->assertStringContainsString('AFTER-CUTOFF', $bodies[1]);
        $this->assertStringContainsString('DAY-TWO', $bodies[1]);
        $this->assertStringNotContainsString('DAY-ONE', $bodies[1]);
    }

    public function test_no_activity_means_no_email_and_disabled_means_nothing(): void
    {
        $this->openMatter();
        Settings::set(['digest.firm_recipients' => [$this->admin->email]]);
        $this->digests()->run();
        Mail::assertNothingSent();

        Settings::set(['digest.enabled' => false]);
        $this->assertSame(0, $this->digests()->run()['planned']);
    }

    public function test_access_is_rechecked_when_sending(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 09:00', 'UTC'));
        [$matter, $contact] = $this->openMatter();
        $leaver = $this->clientContact($matter->client, 'Chidi Eze');
        $this->say($matter, $this->lawyer, 'client', 'Update for the client');

        $this->travelTo(Carbon::parse('2026-10-01 17:05', 'UTC'));
        $this->digests()->plan($this->digests()->dueCutoff(now()));
        app(ClientContacts::class)->revoke($matter->client, $leaver, $this->admin, 'Left the company');
        $contact->forceFill(['email' => 'changed@example.test'])->save();

        $result = $this->digests()->send(40, 60);
        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 2], $result);
        Mail::assertNothingSent();
        $this->assertSame(2, Digest::where('status', 'skipped')->whereNotNull('skipped_reason')->count());
    }

    public function test_summary_mode_for_restricted_matters_and_by_preference(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 09:00', 'UTC'));
        [$matter, $contact] = $this->openMatter();
        $matter->forceFill(['confidentiality' => 'restricted'])->save();
        $this->say($matter, $this->lawyer, 'client', 'SENSITIVE detail');

        $this->travelTo(Carbon::parse('2026-10-01 17:05', 'UTC'));
        $this->digests()->run();
        $mail = $this->sentMail();
        $this->assertStringNotContainsString('SENSITIVE', $mail[$contact->email]);
        $this->assertStringContainsString('1 new message', $mail[$contact->email]);
    }

    public function test_stuck_sends_become_uncertain_and_only_admins_retry(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 09:00', 'UTC'));
        [$matter, $contact] = $this->openMatter();
        $this->say($matter, $this->lawyer, 'client', 'Hello');
        $this->travelTo(Carbon::parse('2026-10-01 17:05', 'UTC'));
        $this->digests()->plan($this->digests()->dueCutoff(now()));
        $digest = Digest::firstOrFail();
        $digest->update(['status' => 'sending', 'claimed_at' => now(), 'attempts' => 1]);

        $this->travelTo(Carbon::parse('2026-10-01 17:40', 'UTC'));
        $this->assertSame(0, $this->digests()->run()['sent']); // never re-sent automatically
        $this->assertSame('uncertain', $digest->refresh()->status);

        $this->assertThrows(fn () => $this->digests()->retry($digest, $this->lawyer), RuleViolation::class);
        $this->digests()->retry($digest, $this->admin);
        $this->assertTrue(AuditEvent::where('action', 'digest.retried')->exists());
        $this->assertSame(1, $this->digests()->send(40, 60)['sent']);
        $this->assertSame('sent', $digest->refresh()->status);
    }

    public function test_firm_digest_requires_a_listed_active_full_administrator(): void
    {
        Settings::set(['digest.firm_recipients' => [$this->lawyer->email, 'nobody@example.test']]);
        $this->assertCount(0, $this->digests()->firmRecipients());

        Settings::set(['digest.firm_recipients' => [strtoupper($this->admin->email)]]);
        $this->assertSame([$this->admin->id], $this->digests()->firmRecipients()->pluck('id')->all());

        $this->admin->forceFill(['suspended_at' => now()])->save();
        $this->assertCount(0, $this->digests()->firmRecipients());
    }
}
