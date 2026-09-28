<?php

namespace Tests\Feature\Practice;

use App\Domain\Identity\Role;
use App\Domain\Intake\ServiceCatalogue;
use App\Domain\Matters\Matters;
use App\Domain\Matters\Tasks;
use App\Domain\Matters\TaskStatus;
use App\Filament\Pages\MyWork;
use App\Domain\RuleViolation;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\EngagementTemplates\Pages\EditEngagementTemplate;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\IntakeFormsRelationManager;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

/** Firm setup (services, intake forms, templates) and client records in the staff area. */
class FirmSetupUiTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('confidential');
        Mail::fake();
        Notification::fake();
        $this->setUpPractice();
    }

    public function test_setup_pages_are_for_full_administrators_only(): void
    {
        $service = $this->service();
        $template = $this->template();
        $urls = ['/admin/services', '/admin/services/create', "/admin/services/{$service->id}/edit",
            '/admin/engagement-templates', '/admin/engagement-templates/create', "/admin/engagement-templates/{$template->id}/edit"];

        $this->actingAsStaff($this->admin);
        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
        $this->actingAsStaff($this->lawyer);
        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_service_is_created_with_custom_stages(): void
    {
        $this->actingAsStaff($this->admin);
        Livewire::test(CreateService::class)
            ->fillForm(['name' => 'Trade Marks', 'slug' => '', 'is_public' => true, 'is_active' => true, 'sort' => 3,
                'stages' => [['label' => 'Search', 'key' => ''], ['label' => 'Filed with registry', 'key' => '']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::where('slug', 'trade-marks')->firstOrFail();
        $this->assertSame(['search', 'filed_with_registry'], array_column($service->stages, 'key'));
    }

    public function test_stage_used_by_an_open_matter_cannot_be_removed(): void
    {
        [$matter] = $this->openMatter();
        $this->actingAsStaff($this->admin);

        Livewire::test(EditService::class, ['record' => $matter->service_id])
            ->fillForm(['stages' => [['label' => 'Only stage', 'key' => 'only_stage']]])
            ->call('save')
            ->assertNotified('Not saved');

        $this->assertNull($matter->service->fresh()->stages);
    }

    public function test_intake_form_is_drafted_published_and_then_versioned(): void
    {
        $service = $this->service();
        $service->intakeForms()->delete();
        $this->actingAsStaff($this->admin);
        $rm = fn () => Livewire::test(IntakeFormsRelationManager::class, ['ownerRecord' => $service, 'pageClass' => EditService::class]);

        $rm()->callTableAction('edit', data: ['fields' => [
            ['label' => 'Company name', 'type' => 'text', 'key' => '', 'required' => true],
            ['label' => 'Size', 'type' => 'select', 'key' => '', 'options' => "Small\nLarge"],
        ]])->assertHasNoTableActionErrors();
        $draft = $service->intakeForms()->firstOrFail();
        $this->assertSame(1, $draft->version);
        $this->assertSame(['Small', 'Large'], $draft->fields[1]['options']);

        $rm()->callTableAction('publish', $draft);
        $this->assertTrue($draft->fresh()->isPublished());

        // Editing a published form starts version 2; version 1 stays as asked.
        $rm()->callTableAction('edit', data: ['fields' => [['label' => 'Company name', 'type' => 'text', 'key' => '']]]);
        $this->assertSame(2, $service->intakeForms()->first()->version);
        $this->assertCount(2, $draft->fresh()->fields);
        $rm()->assertTableActionHidden('publish', $draft);
    }

    public function test_invalid_intake_questions_are_refused(): void
    {
        $service = $this->service();
        $catalogue = app(ServiceCatalogue::class);

        foreach ([
            [['label' => 'Pick', 'type' => 'select', 'options' => 'Only one']],
            [['label' => 'A', 'type' => 'text', 'key' => 'same'], ['label' => 'B', 'type' => 'text', 'key' => 'same']],
            [['label' => 'A', 'type' => 'script']],
            [['label' => 'A', 'type' => 'text', 'key' => '1bad']],
        ] as $fields) {
            try {
                $catalogue->saveFormDraft($service, $fields, $this->admin);
                $this->fail('Expected refusal for '.json_encode($fields));
            } catch (RuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_template_body_is_cleaned_and_version_rises_only_when_terms_change(): void
    {
        $template = $this->template();
        $version = $template->version;
        $this->actingAsStaff($this->admin);

        Livewire::test(EditEngagementTemplate::class, ['record' => $template->id])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($version, $template->fresh()->version);

        Livewire::test(EditEngagementTemplate::class, ['record' => $template->id])
            ->fillForm(['body' => '<p>New terms for {{client_name}}</p><script>alert(1)</script>'])
            ->call('save')->assertHasNoFormErrors();
        $fresh = $template->fresh();
        $this->assertSame($version + 1, $fresh->version);
        $this->assertStringNotContainsString('<script', $fresh->body);
        $this->assertStringContainsString('New terms', $fresh->body);
    }

    public function test_my_work_lists_own_tasks_and_visible_deadlines(): void
    {
        [$matter] = $this->openMatter();
        $officer = $this->teamMember($matter);
        $mine = app(Tasks::class)->create($matter, ['title' => 'Draft the petition', 'assignee_id' => $officer->id, 'due_at' => now()->subDay()->toDateTimeString()], $this->admin);
        app(Tasks::class)->create($matter, ['title' => 'Someone else task', 'assignee_id' => $this->lawyer->id], $this->admin);
        app(Matters::class)->addDeadline($matter, ['kind' => 'deadline', 'title' => 'File defence', 'due_at' => now()->addDays(5)->toDateTimeString()], $this->admin);
        app(Matters::class)->addDeadline($matter, ['kind' => 'milestone', 'title' => 'Far future', 'due_at' => now()->addDays(90)->toDateTimeString()], $this->admin);

        $this->actingAsStaff($officer);
        $this->get('/admin/my-work')->assertOk()->assertSee('File defence')->assertDontSee('Far future');
        Livewire::test(MyWork::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCountTableRecords(1)
            ->callTableAction('complete', $mine, ['note' => 'Filed today'])
            ->assertNotified('Task completed');
        $this->assertSame(TaskStatus::Done, $mine->fresh()->status);

        // An outsider sees none of it.
        $this->actingAsStaff($this->userWithRoles(Role::Lawyer));
        $this->get('/admin/my-work')->assertOk()->assertDontSee('File defence');
    }

    public function test_client_pages_are_scoped_and_contacts_managed_by_admins(): void
    {
        [$matter, $contact] = $this->openMatter();
        $client = $matter->client;
        $outsider = $this->userWithRoles(Role::Lawyer);

        $this->actingAsStaff($this->admin);
        $this->get('/admin/clients')->assertOk()->assertSee($client->display_name);
        $this->get("/admin/clients/{$client->id}")->assertOk();

        $this->actingAsStaff($outsider);
        $this->get("/admin/clients/{$client->id}")->assertNotFound();
        $this->get('/admin/clients')->assertOk()->assertDontSee($client->reference);

        // The responsible lawyer sees the client but cannot edit or manage portal access.
        $this->actingAsStaff($this->lawyer);
        Livewire::test(ViewClient::class, ['record' => $client->id])->assertActionHidden('edit');
        Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
            ->assertTableActionHidden('invite')->assertTableActionHidden('revoke', $contact);

        $this->actingAsStaff($this->admin);
        Livewire::test(ViewClient::class, ['record' => $client->id])
            ->callAction('edit', ['display_name' => 'Obi Holdings', 'type' => 'organisation'])
            ->assertHasNoActionErrors();
        $this->assertSame('Obi Holdings', $client->fresh()->display_name);

        $rm = fn () => Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);
        $rm()->callTableAction('invite', data: ['name' => 'Bola Ade', 'email' => 'bola@example.test'])->assertNotified('Invitation sent');
        $rm()->callTableAction('revoke', $contact, ['reason' => 'x'])->assertHasTableActionErrors(['reason']);
        $rm()->callTableAction('revoke', $contact, ['reason' => 'Left the company'])->assertNotified('Access removed');
        $this->assertFalse($client->users()->whereKey($contact->id)->exists());
    }
}
