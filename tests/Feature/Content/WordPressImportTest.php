<?php

namespace Tests\Feature\Content;

use App\Domain\Content\WordPressImporter;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Runs against the REST capture of the live site in database/wordpress-capture/rest. */
class WordPressImportTest extends TestCase
{
    use RefreshDatabase;

    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->data = WordPressImporter::readDirectory(base_path('database/wordpress-capture/rest'));
    }

    private function import(array $overrides = []): array
    {
        $run = app(WordPressImporter::class)->import($overrides + [
            'data' => $this->data,
            'html_path' => base_path('database/wordpress-capture/html'),
            'draft_slugs' => ['hello-world'],
        ]);

        $this->assertSame('completed', $run->status);

        return $run->stats ?? [];
    }

    public function test_import_creates_posts_and_keeps_hello_world_as_a_draft(): void
    {
        $this->import();

        $this->assertSame(count($this->data['posts']), Post::count());
        $this->assertSame('draft', Post::where('slug', 'hello-world')->value('status'));
        $this->assertGreaterThan(0, Comment::count());
        $this->assertDatabaseHas('audit_events', ['action' => 'content.wordpress_imported']);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->import();
        $before = Post::orderBy('id')->get(['id', 'updated_at'])->toArray();

        $stats = $this->import();

        $this->assertSame($before, Post::orderBy('id')->get(['id', 'updated_at'])->toArray());
        $this->assertSame(count($this->data['posts']), $stats['post.unchanged'] ?? 0);
        $this->assertArrayNotHasKey('post.created', $stats);
    }

    public function test_dry_run_saves_nothing(): void
    {
        $stats = $this->import(['dry_run' => true]);

        $this->assertNotEmpty($stats);
        $this->assertSame(0, Post::count());
        $this->assertDatabaseMissing('audit_events', ['action' => 'content.wordpress_imported']);
    }

    public function test_local_edits_win_over_later_wordpress_changes(): void
    {
        $this->import();
        $source = $this->data['posts'][0];
        $post = Post::where('slug', $source['slug'])->sole();

        $this->travel(1)->minutes();
        $post->update(['title' => 'Edited here']);

        $this->data['posts'][0]['title']['rendered'] = 'Changed in WordPress';
        $stats = $this->import();

        $this->assertSame(1, $stats['post.conflict'] ?? 0);
        $this->assertSame('Edited here', $post->fresh()->title);
    }
}
