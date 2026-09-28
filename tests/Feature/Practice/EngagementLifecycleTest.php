<?php

namespace Tests\Feature\Practice;

use App\Domain\Clients\ClientContacts;
use App\Domain\Engagement\Engagements;
use App\Domain\Engagement\OfferStatus;
use App\Domain\Identity\Role;
use App\Domain\Intake\ConflictChecks;
use App\Domain\Intake\Enquiries;
use App\Domain\Intake\EnquiryStatus;
use App\Domain\RuleViolation;
use App\Models\Acceptance;
use App\Models\Matter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

class EngagementLifecycleTest extends TestCase
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

    public function test_full_chain_opens_a_matter_only_after_internal_approval(): void
    {
        $enquiry = $this->readyEnquiry();
        $contact = $this->clientContact($enquiry->client);
        $engagements = app(Engagements::class);

        $engagement = $engagements->create($enquiry, $this->template(), 'Company registration', null, $this->lawyer);
        $body = $engagement->currentVersion->body;
        $this->assertStringContainsString($enquiry->client->reference, $body);
        $this->assertStringNotContainsString('<script', $body);

        $engagements->send($engagement, $this->lawyer);
        $this->assertSame(EnquiryStatus::EngagementPending, $enquiry->refresh()->status);

        $engagement->refresh();
        $engagements->respond($engagement, $engagement->current_version_id, 'accepted', 'Ada Obi', null, $contact, '127.0.0.1', 'test');
        $engagement->refresh();
        $this->assertSame(OfferStatus::Accepted, $engagement->status);
        $this->assertSame(0, Matter::count(), 'Acceptance alone must not open a matter.');

        // The responsible lawyer cannot give the internal approval; only a full administrator can.
        try {
            $engagements->approve($engagement, $this->lawyer, null, $this->lawyer);
            $this->fail('Lawyer approved an engagement.');
        } catch (AuthorizationException) {
        }

        $matter = $engagements->approve($engagement, $this->lawyer, 'Approved', $this->admin);
        $this->assertNotNull($matter->representation_started_at);
        $this->assertSame($this->lawyer->id, $matter->responsible->user_id);
        $this->assertSame(EnquiryStatus::Converted, $enquiry->refresh()->status);
        $this->assertSame($matter->id, $enquiry->matter_id);
        $this->assertTrue($enquiry->parties()->where('matter_id', $matter->id)->exists());
        $this->assertTrue(Matter::visibleTo($contact)->whereKey($matter->id)->exists());
    }

    public function test_terms_cannot_be_sent_until_the_conflict_check_is_cleared(): void
    {
        $enquiry = $this->enquiry();
        app(Enquiries::class)->assign($enquiry, $this->lawyer, $this->admin);
        app(Enquiries::class)->createClient($enquiry->refresh(), $this->lawyer);
        $engagement = app(Engagements::class)->create($enquiry->refresh(), $this->template(), 'Terms', null, $this->lawyer);

        $this->expectException(RuleViolation::class);
        app(Engagements::class)->send($engagement, $this->lawyer);
    }

    public function test_adding_a_party_resets_the_conflict_check_and_blocks_approval(): void
    {
        $enquiry = $this->readyEnquiry();
        $contact = $this->clientContact($enquiry->client);
        $engagements = app(Engagements::class);
        $engagement = $engagements->create($enquiry, $this->template(), 'Terms', null, $this->lawyer);
        $engagements->send($engagement, $this->lawyer);
        $engagement->refresh();
        $engagements->respond($engagement, $engagement->current_version_id, 'accepted', 'Ada Obi', null, $contact, null, null);

        app(Enquiries::class)->addParty($enquiry->refresh(), 'Opposing Ventures Ltd', 'opposing', null, $this->lawyer);
        $this->assertSame('pending', $enquiry->refresh()->conflict_status);

        $this->expectException(RuleViolation::class);
        $engagements->approve($engagement->refresh(), $this->lawyer, null, $this->admin);
    }

    public function test_conflict_check_needs_a_reason_and_never_clears_itself(): void
    {
        $enquiry = $this->enquiry();
        $this->assertSame('pending', $enquiry->conflict_status);

        $this->expectException(RuleViolation::class);
        app(ConflictChecks::class)->decide($enquiry, 'cleared', 'ok', $this->admin);
    }

    public function test_conflict_suggestions_find_earlier_parties_without_matter_details(): void
    {
        [$matter] = $this->openMatter();
        $second = $this->enquiry(['contact_name' => 'Ada Obi']);

        $suggestions = app(ConflictChecks::class)->suggestions($second, $this->admin);
        $match = collect($suggestions)->firstWhere('reference', $matter->reference);
        $this->assertNotNull($match);
        $this->assertSame(['name', 'role', 'reference', 'source', 'match'], array_keys($match));
    }

    public function test_a_full_administrator_cannot_accept_as_the_client(): void
    {
        $enquiry = $this->readyEnquiry();
        $engagements = app(Engagements::class);
        $engagement = $engagements->create($enquiry, $this->template(), 'Terms', null, $this->lawyer);
        $engagements->send($engagement, $this->lawyer);
        $engagement->refresh();

        $this->expectException(AuthorizationException::class);
        $engagements->respond($engagement, $engagement->current_version_id, 'accepted', 'Admin', null, $this->admin, null, null);
    }

    public function test_acceptance_is_bound_to_the_version_viewed(): void
    {
        $enquiry = $this->readyEnquiry();
        $contact = $this->clientContact($enquiry->client);
        $engagements = app(Engagements::class);
        $engagement = $engagements->create($enquiry, $this->template(), 'Terms', null, $this->lawyer);
        $engagements->send($engagement, $this->lawyer);
        $old = $engagement->refresh()->current_version_id;

        // Staff revise after sending: a new draft version, and the old one can no longer be accepted.
        $engagements->revise($engagement, 'Terms', '<p>Revised terms.</p>', $this->lawyer);
        $engagements->send($engagement->refresh(), $this->lawyer);

        try {
            $engagements->respond($engagement->refresh(), $old, 'accepted', 'Ada Obi', null, $contact, null, null);
            $this->fail('Accepted a superseded version.');
        } catch (RuleViolation) {
        }
        $this->assertSame(0, Acceptance::count());
    }

    public function test_offline_acceptance_is_recorded_by_staff_with_evidence(): void
    {
        $enquiry = $this->readyEnquiry();
        $engagements = app(Engagements::class);
        $engagement = $engagements->create($enquiry, $this->template(), 'Terms', null, $this->lawyer);
        $engagements->send($engagement, $this->lawyer);
        $engagement->refresh();

        $acceptance = $engagements->recordOfflineAcceptance($engagement, $engagement->current_version_id, $this->pdf('signed.pdf'),
            'Ada Obi', now()->subDay()->toDateString(), null, $this->lawyer);

        $this->assertSame('offline_signed', $acceptance->method);
        $this->assertNull($acceptance->user_id);
        $this->assertSame($this->lawyer->id, $acceptance->recorded_by);
        $this->assertNotNull($acceptance->evidenceDocument);
        Storage::disk('confidential')->assertExists($acceptance->evidenceDocument->currentVersion->path);
    }

    public function test_acceptance_records_are_append_only(): void
    {
        [, , , $engagement] = $this->openMatter();
        $acceptance = Acceptance::where('acceptable_id', $engagement->accepted_version_id)->firstOrFail();

        $acceptance->comment = 'changed';
        $this->assertFalse($acceptance->save());
        $this->assertFalse($acceptance->delete());
        $this->assertSame(1, Acceptance::count());
    }

    public function test_quotation_accept_does_not_start_representation(): void
    {
        $enquiry = $this->readyEnquiry();
        $contact = $this->clientContact($enquiry->client);
        $q = $this->quotations()->create($enquiry->client, $enquiry, null, $this->quotationData(), $this->lawyer);
        $this->assertSame(17500050, $q->currentVersion->total_minor);

        $this->quotations()->send($q, $this->lawyer);
        $q->refresh();
        $this->quotations()->respond($q, $q->current_version_id, 'accepted', $contact, null, null, null);

        $this->assertSame(OfferStatus::Accepted, $q->refresh()->status);
        $this->assertSame(0, Matter::count());
    }

    public function test_quotation_payment_stages_must_match_the_total(): void
    {
        $enquiry = $this->readyEnquiry();

        $this->expectException(RuleViolation::class);
        $this->quotations()->create($enquiry->client, $enquiry, null, $this->quotationData([
            'payment_stages' => [['label' => 'Deposit', 'amount' => '100000', 'due' => 'On signing']],
        ]), $this->lawyer);
    }

    public function test_client_contacts_are_invitation_only_and_revocable(): void
    {
        $enquiry = $this->readyEnquiry();
        $contact = $this->clientContact($enquiry->client);
        $this->assertTrue($contact->hasRole(Role::Client));
        $this->assertSame('owner', $enquiry->client->users()->first()->pivot->relationship);

        try {
            app(ClientContacts::class)->invite($enquiry->client, 'x@example.test', 'X', $this->lawyer);
            $this->fail('Lawyer invited a client contact.');
        } catch (AuthorizationException) {
        }

        app(ClientContacts::class)->revoke($enquiry->client, $contact, $this->admin, 'Left the company');
        $this->assertFalse($contact->clients()->whereKey($enquiry->client_id)->exists());
    }
}
