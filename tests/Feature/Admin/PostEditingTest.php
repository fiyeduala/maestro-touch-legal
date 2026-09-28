<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\Role;
use App\Domain\Operations\Settings;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Post;
use App\Models\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PostEditingTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(array $attributes = []): Post
    {
        return Post::create($attributes + [
            'title' => 'Land title in Lagos',
            'slug' => 'land-title-in-lagos',
            'body' => '<p>Body</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    public function test_content_editor_can_submit_a_post_for_review_but_not_publish(): void
    {
        $editor = $this->userWithRoles(Role::ContentEditor);
        $this->actingAsStaff($editor);

        Livewire::test(CreatePost::class)
            ->fillForm(['title' => 'Draft', 'slug' => 'draft-article', 'body' => '<p>Hi<script>alert(1)</script></p>', 'status' => 'published'])
            ->call('create')
            ->assertHasFormErrors(['status']);

        Livewire::test(CreatePost::class)
            ->fillForm(['title' => 'Draft', 'slug' => 'draft-article', 'body' => '<p>Hi<script>alert(1)</script></p>', 'status' => 'review'])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = Post::where('slug', 'draft-article')->sole();
        $this->assertSame('review', $post->status);
        $this->assertSame($editor->id, $post->submitted_by);
        $this->assertNull($post->published_by);
        $this->assertStringNotContainsString('<script', $post->body);
        $this->assertSame(1, $post->revisions()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'post.created']);
    }

    public function test_content_editor_cannot_edit_a_live_post_without_publish_rights(): void
    {
        $post = $this->makePost();

        $this->actingAsStaff($this->userWithRoles(Role::ContentEditor))
            ->get("/admin/posts/{$post->id}/edit")->assertForbidden();

        Settings::set(['content.editors_can_publish' => true]);
        $this->actingAsStaff($this->userWithRoles(Role::ContentEditor))
            ->get("/admin/posts/{$post->id}/edit")->assertOk();
    }

    public function test_reserved_and_page_slugs_are_refused(): void
    {
        $this->actingAsStaff($this->userWithRoles(Role::FirmPrincipal));

        foreach (['admin', 'blog', 'careers'] as $slug) {
            Livewire::test(CreatePost::class)
                ->fillForm(['title' => 'X', 'slug' => $slug, 'body' => '<p>x</p>', 'status' => 'draft'])
                ->call('create')
                ->assertHasFormErrors(['slug']);
        }
    }

    public function test_publishing_sets_the_date_and_publisher(): void
    {
        $admin = $this->userWithRoles(Role::FirmPrincipal);
        $this->actingAsStaff($admin);

        Livewire::test(CreatePost::class)
            ->fillForm(['title' => 'Now', 'slug' => 'now-live', 'body' => '<p>x</p>', 'status' => 'published'])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = Post::where('slug', 'now-live')->sole();
        $this->assertNotNull($post->published_at);
        $this->assertSame($admin->id, $post->published_by);
        $this->get('/now-live/')->assertOk()->assertSee('Now');
    }

    public function test_renaming_a_live_post_redirects_the_old_address(): void
    {
        $post = $this->makePost();
        Redirect::create(['from_path' => '/older-name/', 'to_path' => '/land-title-in-lagos/', 'status_code' => 301]);
        $this->actingAsStaff($this->userWithRoles(Role::FirmPrincipal));

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['slug' => 'land-titles-lagos'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('redirects', ['from_path' => '/land-title-in-lagos/', 'to_path' => '/land-titles-lagos/', 'source' => 'system']);
        // Existing redirects are re-pointed so there is never a chain.
        $this->assertDatabaseHas('redirects', ['from_path' => '/older-name/', 'to_path' => '/land-titles-lagos/']);
        $this->get('/land-title-in-lagos/')->assertRedirect($this->site('/land-titles-lagos/'));
        $this->assertSame(1, $post->revisions()->count());
    }

    public function test_renaming_a_draft_adds_no_redirect(): void
    {
        $post = $this->makePost(['status' => 'draft', 'published_at' => null]);
        $this->actingAsStaff($this->userWithRoles(Role::FirmPrincipal));

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['slug' => 'renamed-draft'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('redirects', 0);
    }
}
