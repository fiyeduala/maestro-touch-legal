<?php

namespace Tests\Feature\Portal;

use App\Domain\Documents\Documents;
use App\Domain\Engagement\Engagements;
use App\Domain\Engagement\OfferStatus;
use App\Domain\Matters\Matters;
use App\Domain\Matters\Tasks;
use App\Models\Acceptance;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

/** The client's side of Phase 3: matters, documents, requests, quotations and engagement terms. */
class PortalWorkTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('confidential');
        Notification::fake();
        $this->setUpPractice();
    }

    /** A deliverable approved by the lawyer and shared with the client. */
    private function releasedDraft(Matter $matter): Document
    {
        $docs = app(Documents::class);
        $doc = $docs->upload($matter, $this->pdf('memo.pdf'), ['title' => 'Board resolution', 'category' => 'deliverable', 'is_deliverable' => true], $this->lawyer);
        $docs->submitForReview($doc, $this->lawyer);
        $docs->approve($doc->refresh(), null, $this->lawyer);
        $docs->release($doc->refresh(), null, $this->lawyer);

        return $doc->refresh();
    }

    public function test_client_sees_their_matter_but_nothing_internal(): void
    {
        [$matter, $contact] = $this->openMatter();
        app(Matters::class)->update($matter, ['internal_assessment' => 'Weak case on limitation', 'client_summary' => 'Registering your company.'], $this->admin);
        $officer = $this->teamMember($matter);
        app(Tasks::class)->create($matter->refresh(), ['title' => 'Chase the registry secretly', 'assignee_id' => $officer->id], $this->admin);
        app(Documents::class)->upload($matter, $this->pdf('internal.pdf'), ['title' => 'Internal research note', 'category' => 'other'], $this->lawyer);
        app(Documents::class)->requestFromClient($matter, 'Copy of passport', 'Photo page only', null, $this->lawyer);

        $this->actingAs($contact);
        $this->get('/portal/')->assertOk()->assertSee($matter->title)->assertSee('Needs Your Attention')->assertSee('Please upload: Copy of passport');
        $this->get("/portal/matters/{$matter->id}")->assertOk()
            ->assertSee('Registering your company.')
            ->assertSee('The firm now acts for you')
            ->assertSee('Copy of passport')
            ->assertDontSee('Weak case on limitation')
            ->assertDontSee('Chase the registry secretly')
            ->assertDontSee('Internal research note')
            ->assertDontSee("{$officer->name} joined the team");
    }

    public function test_other_clients_and_staff_cannot_open_the_matter(): void
    {
        [$matter] = $this->openMatter();
        [$other, $otherContact] = $this->openMatter();

        $this->actingAs($otherContact);
        $this->get("/portal/matters/{$matter->id}")->assertForbidden();
        $this->post("/portal/matters/{$matter->id}/documents", ['file' => $this->pdf()])->assertForbidden();

        $this->actingAs($this->admin);
        $this->get("/portal/matters/{$matter->id}")->assertRedirect(url('/admin'));
    }

    public function test_client_upload_answers_the_request(): void
    {
        [$matter, $contact] = $this->openMatter();
        $request = app(Documents::class)->requestFromClient($matter, 'Copy of passport', null, null, $this->lawyer);

        $this->actingAs($contact)->from("/portal/matters/{$matter->id}")
            ->post("/portal/matters/{$matter->id}/documents", ['file' => $this->pdf('passport.pdf'), 'request_id' => $request->id])
            ->assertRedirect("/portal/matters/{$matter->id}")->assertSessionHas('status');

        $this->assertSame('fulfilled', $request->fresh()->status);
        $doc = Document::where('uploaded_by_client', true)->sole();
        $this->assertSame('Copy of passport', $doc->title);

        $this->post("/portal/matters/{$matter->id}/documents", ['file' => \Illuminate\Http\UploadedFile::fake()->create('old.doc', 10)])
            ->assertSessionHasErrors('file');
    }

    public function test_client_approves_or_requests_changes_to_a_released_draft(): void
    {
        [$matter, $contact] = $this->openMatter();
        $doc = $this->releasedDraft($matter);

        $this->actingAs($contact);
        $this->get("/portal/matters/{$matter->id}")->assertSee('Board resolution')->assertSee('Approve Version');

        $this->post("/portal/deliverables/{$doc->id}/decision", ['version_id' => $doc->released_version_id, 'decision' => 'changes_requested', 'comment' => ''])
            ->assertSessionHasErrors("decision_{$doc->id}");
        $this->post("/portal/deliverables/{$doc->id}/decision", ['version_id' => $doc->released_version_id + 999, 'decision' => 'approved'])
            ->assertSessionHasErrors("decision_{$doc->id}");

        $this->post("/portal/deliverables/{$doc->id}/decision", ['version_id' => $doc->released_version_id, 'decision' => 'approved'])
            ->assertSessionHas('status');
        $this->assertSame('approved', $doc->fresh()->client_decision);
        $this->assertSame(1, Acceptance::where('decision', 'approved')->where('user_id', $contact->id)->count());
    }

    public function test_client_accepts_a_quotation_for_the_exact_version(): void
    {
        [$matter, $contact] = $this->openMatter();
        $quotation = $this->quotations()->create($matter->client, null, $matter, $this->quotationData(), $this->lawyer);
        $this->quotations()->send($quotation, $this->lawyer);
        $quotation->refresh();

        $this->actingAs($contact);
        $this->get('/portal/')->assertSee("Review quotation {$quotation->reference}");
        $this->get("/portal/quotations/{$quotation->id}")->assertOk()->assertSee('Professional fee')->assertSee('Accept Quotation');

        $this->post("/portal/quotations/{$quotation->id}/respond", ['version_id' => $quotation->current_version_id, 'decision' => 'accepted'])
            ->assertSessionHasErrors('confirm');
        $this->post("/portal/quotations/{$quotation->id}/respond", ['version_id' => $quotation->current_version_id, 'decision' => 'accepted', 'confirm' => '1'])
            ->assertSessionHas('status');
        $this->assertSame(OfferStatus::Accepted, $quotation->fresh()->status);

        // Once decided it cannot be answered again.
        $this->post("/portal/quotations/{$quotation->id}/respond", ['version_id' => $quotation->current_version_id, 'decision' => 'declined'])->assertForbidden();
    }

    public function test_client_signs_engagement_terms(): void
    {
        $enquiry = $this->readyEnquiry();
        $contact = $this->clientContact($enquiry->client);
        $engagements = app(Engagements::class);
        $engagement = $engagements->create($enquiry, $this->template(), 'Company registration', null, $this->lawyer);
        $engagements->send($engagement, $this->lawyer);
        $engagement->refresh();

        $this->actingAs($contact);
        $this->get('/portal/')->assertSee('Review and sign engagement terms');
        $this->get("/portal/engagements/{$engagement->id}")->assertOk()->assertSee('Sign and Accept')->assertDontSee('<script>alert(1)</script>', false);

        $this->post("/portal/engagements/{$engagement->id}/respond", ['version_id' => $engagement->current_version_id, 'decision' => 'accepted', 'confirm' => '1'])
            ->assertSessionHasErrors('signed_name');
        $this->post("/portal/engagements/{$engagement->id}/respond", [
            'version_id' => $engagement->current_version_id, 'decision' => 'accepted', 'signed_name' => 'Ada Obi', 'confirm' => '1',
        ])->assertSessionHas('status');

        $this->assertSame(OfferStatus::Accepted, $engagement->fresh()->status);
        $this->assertSame('Ada Obi', Acceptance::where('user_id', $contact->id)->sole()->signed_name);
        // Accepting does not open the matter: that still needs internal approval.
        $this->assertSame(0, Matter::count());
    }

    public function test_revoked_contact_loses_access(): void
    {
        [$matter, $contact] = $this->openMatter();
        app(\App\Domain\Clients\ClientContacts::class)->revoke($matter->client, $contact, $this->admin, 'No longer authorised');

        $this->actingAs(User::find($contact->id));
        $this->get("/portal/matters/{$matter->id}")->assertForbidden();
    }
}
