<?php

namespace Tests\Feature\Practice;

use App\Domain\Documents\Documents;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Identity\Role;
use App\Domain\Matters\MatterStatus;
use App\Domain\Matters\TaskStatus;
use App\Filament\Resources\Engagements\Pages\ViewEngagement;
use App\Filament\Resources\Enquiries\Pages\ViewEnquiry;
use App\Filament\Resources\Matters\Pages\ViewMatter;
use App\Filament\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Resources\Matters\RelationManagers\DocumentRequestsRelationManager;
use App\Filament\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Matters\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Filament\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Models\Document;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

/** The staff screens for Phase 3: they render, stay scoped, and route every change through the domain rules. */
class PracticeAdminUiTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('confidential');
        $this->setUpPractice();
    }

    private function relation(string $class, Matter $matter)
    {
        return Livewire::test($class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
    }

    public function test_admin_can_open_every_practice_page(): void
    {
        [$matter, , $enquiry, $engagement] = $this->openMatter();
        $quotation = $this->quotations()->create($matter->client, null, $matter, $this->quotationData(), $this->admin);

        $this->actingAsStaff($this->admin);
        foreach ([
            '/admin/enquiries', "/admin/enquiries/{$enquiry->id}",
            '/admin/quotations', "/admin/quotations/{$quotation->id}", "/admin/quotations/create?matter={$matter->id}",
            '/admin/engagements', "/admin/engagements/{$engagement->id}",
            '/admin/matters', "/admin/matters/{$matter->id}",
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_every_matter_section_renders(): void
    {
        [$matter] = $this->openMatter();
        $this->actingAsStaff($this->admin);

        foreach ([TasksRelationManager::class, DocumentsRelationManager::class, DocumentRequestsRelationManager::class,
            DeadlinesRelationManager::class, TeamRelationManager::class, EventsRelationManager::class] as $class) {
            $this->relation($class, $matter)->assertOk();
        }
    }

    public function test_staff_only_reach_matters_they_are_on(): void
    {
        [$matter] = $this->openMatter();
        $outsider = $this->userWithRoles(Role::Lawyer);

        $this->actingAsStaff($this->lawyer)->get("/admin/matters/{$matter->id}")->assertOk();
        $this->actingAsStaff($outsider)->get("/admin/matters/{$matter->id}")->assertNotFound();
        $this->actingAsStaff($outsider)->get('/admin/matters')->assertOk()->assertDontSee($matter->reference);
        $this->actingAsStaff($outsider)->get("/admin/quotations/create?matter={$matter->id}")->assertNotFound();
    }

    public function test_changing_stage_from_the_matter_page_is_shown_to_the_client(): void
    {
        [$matter] = $this->openMatter();
        $stage = array_key_last($matter->stageOptions());
        $this->actingAsStaff($this->lawyer);

        Livewire::test(ViewMatter::class, ['record' => $matter->id])
            ->callAction('stage', ['stage' => $stage, 'note' => 'Filed with the registry.'])
            ->assertHasNoFormErrors()
            ->assertNotified('Stage updated');

        $this->assertSame($stage, $matter->refresh()->stage);
        $this->assertTrue($matter->events()->clientVisible()->where('type', 'stage_changed')->exists());
    }

    public function test_closing_needs_the_whole_checklist_and_no_open_tasks(): void
    {
        [$matter] = $this->openMatter();
        $this->actingAsStaff($this->lawyer);
        $this->relation(TasksRelationManager::class, $matter)
            ->callTableAction('create', data: ['title' => 'Draft resolution', 'assignee_id' => $this->lawyer->id])
            ->assertHasNoTableActionErrors();

        $all = array_fill_keys(array_keys(Matter::CLOSURE_CHECKLIST), true);
        Livewire::test(ViewMatter::class, ['record' => $matter->id])
            ->callAction('close', ['checklist' => ['final_documents' => true], 'note' => 'All done.'])
            ->assertHasActionErrors();
        Livewire::test(ViewMatter::class, ['record' => $matter->id])
            ->callAction('close', ['checklist' => $all, 'note' => 'All done.'])
            ->assertNotified('Not done');
        $this->assertSame(MatterStatus::Open, $matter->refresh()->status);

        $task = Task::firstOrFail();
        $this->relation(TasksRelationManager::class, $matter)
            ->callTableAction('complete', $task, ['note' => 'Signed and filed'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(TaskStatus::Done, $task->refresh()->status);

        Livewire::test(ViewMatter::class, ['record' => $matter->id])
            ->callAction('close', ['checklist' => $all, 'note' => 'All done.'])
            ->assertNotified('Matter closed');
        $this->assertSame(MatterStatus::Closed, $matter->refresh()->status);
    }

    public function test_task_assignees_must_be_on_the_team(): void
    {
        [$matter] = $this->openMatter();
        $outsider = $this->userWithRoles(Role::Lawyer);
        $this->actingAsStaff($this->lawyer);

        $this->relation(TasksRelationManager::class, $matter)
            ->callTableAction('create', data: ['title' => 'Research', 'assignee_id' => $outsider->id])
            ->assertHasTableActionErrors(['assignee_id']);
        $this->assertSame(0, Task::count());
    }

    public function test_deliverable_workflow_through_the_documents_section(): void
    {
        [$matter] = $this->openMatter();
        $officer = $this->teamMember($matter);

        $this->actingAsStaff($officer);
        $this->relation(DocumentsRelationManager::class, $matter)
            ->callTableAction('upload', data: ['file' => $this->pdf('draft.pdf'), 'title' => 'Draft memorandum', 'is_deliverable' => true])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Document uploaded');
        $document = Document::firstOrFail();
        $this->assertSame(DocumentStatus::Draft, $document->status);

        $this->relation(DocumentsRelationManager::class, $matter)
            ->callTableAction('submit', $document)
            ->assertTableActionHidden('approve', $document->refresh())
            ->assertTableActionHidden('release', $document);
        $this->assertSame(DocumentStatus::InReview, $document->status);

        $this->actingAsStaff($this->lawyer);
        $this->relation(DocumentsRelationManager::class, $matter)
            ->callTableAction('approve', $document, ['note' => 'Good to go'])
            ->callTableAction('release', $document->refresh(), ['note' => 'Please review.'])
            ->assertNotified('Shared with the client');

        $document->refresh();
        $this->assertSame(DocumentStatus::Released, $document->status);
        $this->assertSame($document->current_version_id, $document->released_version_id);
    }

    public function test_only_admins_see_team_changes(): void
    {
        [$matter] = $this->openMatter();

        $this->actingAsStaff($this->lawyer);
        $this->relation(TeamRelationManager::class, $matter)->assertTableActionHidden('add');

        $officer = $this->userWithRoles(Role::CaseOfficer);
        $this->actingAsStaff($this->admin);
        $this->relation(TeamRelationManager::class, $matter)
            ->callTableAction('add', data: ['user_id' => $officer->id])
            ->assertNotified('Added to the team');
        $this->assertTrue($matter->isOnTeam($officer));
    }

    public function test_document_requests_and_deadlines(): void
    {
        [$matter] = $this->openMatter();
        $this->actingAsStaff($this->lawyer);

        $this->relation(DocumentRequestsRelationManager::class, $matter)
            ->callTableAction('request', data: ['title' => 'Copy of ID', 'description' => 'Passport or NIN slip'])
            ->assertNotified('Request sent to the client');
        $this->relation(DeadlinesRelationManager::class, $matter)
            ->callTableAction('add', data: ['kind' => 'deadline', 'title' => 'File annual return', 'due_at' => now()->addWeek()->format('Y-m-d H:i:s'), 'client_visible' => true])
            ->assertNotified('Deadline added');

        $this->assertSame(1, $matter->documentRequests()->open()->count());
        $this->assertSame(1, $matter->deadlines()->count());
    }

    public function test_admin_approves_accepted_engagement_from_its_page(): void
    {
        $enquiry = $this->readyEnquiry();
        $contact = $this->clientContact($enquiry->client);
        $engagements = app(\App\Domain\Engagement\Engagements::class);
        $engagement = $engagements->create($enquiry, $this->template(), 'Company registration', null, $this->lawyer);
        $engagements->send($engagement, $this->lawyer);
        $engagements->respond($engagement->refresh(), $engagement->current_version_id, 'accepted', 'Ada Obi', null, $contact, '127.0.0.1', 'test');

        $this->actingAsStaff($this->lawyer);
        Livewire::test(ViewEngagement::class, ['record' => $engagement->id])->assertActionHidden('approve');

        $this->actingAsStaff($this->admin);
        Livewire::test(ViewEngagement::class, ['record' => $engagement->id])
            ->callAction('approve', ['responsible' => $this->lawyer->id, 'note' => 'Conflict clear, terms signed.', 'current_password' => 'password'])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertNotNull($engagement->refresh()->matter_id);
    }

    public function test_conflict_decision_from_the_enquiry_page(): void
    {
        $enquiry = $this->enquiry();
        $this->actingAsStaff($this->admin);

        Livewire::test(ViewEnquiry::class, ['record' => $enquiry->id])
            ->callAction('conflict', ['decision' => 'cleared', 'reason' => 'short'])
            ->assertHasActionErrors(['reason']);
        Livewire::test(ViewEnquiry::class, ['record' => $enquiry->id])
            ->callAction('conflict', ['decision' => 'cleared', 'reason' => 'Searched every party; no matches.'])
            ->assertNotified();

        $this->assertSame('cleared', $enquiry->refresh()->conflict_status);
    }

    public function test_quotation_from_a_matter_uses_its_client(): void
    {
        [$matter] = $this->openMatter();
        $this->actingAsStaff($this->admin);

        Livewire::withQueryParams(['matter' => $matter->id])->test(CreateQuotation::class)
            ->fillForm($this->quotationData())
            ->call('create')
            ->assertHasNoFormErrors();

        $quotation = $matter->quotations()->firstOrFail();
        $this->assertSame($matter->client_id, $quotation->client_id);
    }
}
