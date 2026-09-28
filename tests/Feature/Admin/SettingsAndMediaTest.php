<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\Role;
use App\Domain\Operations\Settings;
use App\Filament\Pages\SiteSettings;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Filament\Resources\Media\Pages\CreateMedia;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsAndMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_save_and_are_audited(): void
    {
        $this->actingAsStaff($this->userWithRoles(Role::FirmPrincipal));

        Livewire::test(SiteSettings::class)
            ->fillForm([
                'contact.email' => 'hello@example.com',
                'integrations.tawk_enabled' => true,
                'integrations.tawk_property_id' => '64f1a2b3c4d5e6f7a8b9c0d1',
                'integrations.tawk_widget_id' => '1h9abcdef',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        Settings::flush();
        $this->assertSame('hello@example.com', Settings::get('contact.email'));
        $this->assertTrue(Settings::get('integrations.tawk_enabled'));
        $this->assertDatabaseHas('audit_events', ['action' => 'settings.updated']);
    }

    public function test_tawk_accepts_ids_only(): void
    {
        $this->actingAsStaff($this->userWithRoles(Role::FirmPrincipal));

        Livewire::test(SiteSettings::class)
            ->fillForm([
                'integrations.tawk_enabled' => true,
                'integrations.tawk_property_id' => '"></script><script>alert(1)</script>',
                'integrations.tawk_widget_id' => 'default',
            ])
            ->call('save')
            ->assertHasFormErrors(['integrations.tawk_property_id']);
    }

    public function test_upload_records_file_facts_and_refuses_duplicates(): void
    {
        Storage::fake('media');
        $this->actingAsStaff($this->userWithRoles(Role::ContentEditor));
        $file = UploadedFile::fake()->image('team.png', 40, 30);

        Livewire::test(CreateMedia::class)
            ->fillForm(['path' => $file, 'alt_text' => 'Our team'])
            ->call('create')
            ->assertHasNoFormErrors();

        $media = Media::sole();
        $this->assertSame('image/png', $media->mime_type);
        $this->assertSame(40, $media->width);
        $this->assertSame('team.png', $media->original_name);
        $this->assertSame(64, strlen($media->sha256));

        Livewire::test(CreateMedia::class)
            ->fillForm(['path' => UploadedFile::fake()->createWithContent('copy.png', Storage::disk('media')->get($media->path))])
            ->call('create')
            ->assertHasFormErrors(['path']);
        $this->assertSame(1, Media::count());
    }

    public function test_legacy_media_cannot_be_deleted(): void
    {
        $legacy = Media::create(['disk' => 'legacy', 'path' => '/wp-content/uploads/2025/08/a.png', 'original_name' => 'a.png',
            'mime_type' => 'image/png', 'size' => 1, 'sha256' => str_repeat('a', 64)]);

        $this->assertFalse($this->userWithRoles(Role::FirmPrincipal)->can('delete', $legacy));
    }

    public function test_comment_moderation_is_audited(): void
    {
        $post = Post::create(['title' => 'T', 'slug' => 't', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);
        $comment = Comment::create(['post_id' => $post->id, 'author_name' => 'Ada', 'body' => 'Hello', 'status' => 'pending', 'posted_at' => now()]);
        $this->actingAsStaff($this->userWithRoles(Role::ContentEditor));

        Livewire::test(ListComments::class)->callTableAction('approved', $comment);

        $this->assertSame('approved', $comment->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'comment.moderated']);
    }
}
