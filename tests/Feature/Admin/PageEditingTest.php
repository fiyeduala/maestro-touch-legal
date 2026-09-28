<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\Role;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Page;
use Database\Seeders\PageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PageEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PageSeeder::class);
    }

    private function contact(): Page
    {
        return Page::where('slug', 'contact')->sole();
    }

    public function test_saving_creates_a_draft_and_leaves_the_live_page_alone(): void
    {
        $this->actingAsStaff($this->userWithRoles(Role::ContentEditor));
        $this->get('/contact/')->assertOk()->assertDontSee('Talk to');

        Livewire::test(EditPage::class, ['record' => $this->contact()->getRouteKey()])
            ->fillForm(['content.banner.heading' => 'Talk to *us* today'])
            ->call('save')
            ->assertHasNoFormErrors();

        $page = $this->contact()->fresh();
        $this->assertNotNull($page->draft_revision_id);
        $this->assertNotSame($page->draft_revision_id, $page->published_revision_id);
        $this->get('/contact/')->assertOk()->assertDontSee('Talk to');
        $this->get(route('preview.page', ['page' => $page, 'revision' => $page->draft_revision_id]))
            ->assertOk()->assertSee('Talk to');
    }

    public function test_editor_without_publish_rights_cannot_publish(): void
    {
        $this->actingAsStaff($this->userWithRoles(Role::ContentEditor));

        Livewire::test(EditPage::class, ['record' => $this->contact()->getRouteKey()])
            ->fillForm(['content.banner.heading' => 'Changed'])
            ->call('save')
            ->assertActionHidden('publish');
    }

    public function test_administrator_publishes_then_discards(): void
    {
        $this->actingAsStaff($this->userWithRoles(Role::FirmPrincipal));

        $component = Livewire::test(EditPage::class, ['record' => $this->contact()->getRouteKey()])
            ->fillForm(['content.banner.heading' => 'Brand new heading'])
            ->call('save')
            ->callAction('publish');

        $this->assertNull($this->contact()->fresh()->draft_revision_id);
        $this->get('/contact/')->assertSee('Brand new heading');

        $component->fillForm(['content.banner.heading' => 'Throwaway'])->call('save')->callAction('discard');
        $this->assertNull($this->contact()->fresh()->draft_revision_id);
        $this->get('/contact/')->assertSee('Brand new heading')->assertDontSee('Throwaway');
        $this->assertDatabaseHas('audit_events', ['action' => 'page.draft_discarded']);
    }

    public function test_legal_body_is_sanitised(): void
    {
        $this->actingAsStaff($this->userWithRoles(Role::FirmPrincipal));
        $terms = Page::where('slug', 'terms-and-conditions')->sole();

        Livewire::test(EditPage::class, ['record' => $terms->getRouteKey()])
            ->fillForm(['content.body' => '<p onclick="x()">Terms</p><script>alert(1)</script>'])
            ->call('save')
            ->assertHasNoFormErrors();

        $body = $terms->fresh()->draftRevision->content['body'];
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('onclick', $body);
    }

    public function test_system_pages_cannot_be_created_or_deleted(): void
    {
        $user = $this->userWithRoles(Role::FirmPrincipal);
        $this->assertFalse($user->can('create', Page::class));
        $this->assertFalse($user->can('delete', $this->contact()));
    }
}
