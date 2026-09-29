<?php

namespace Tests\Feature\Content;

use App\Domain\Content\ImportVerifier;
use App\Domain\Content\WordPressImporter;
use App\Domain\Content\WxrReader;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** WordPress export (WXR) reading and the post-import verification report. */
class WxrImportAndVerifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function fixture(): string
    {
        return base_path('tests/fixtures/wordpress-export.xml');
    }

    public function test_the_export_is_read_into_the_rest_shape(): void
    {
        $data = WxrReader::read($this->fixture());

        $this->assertSame(['property-law'], array_column($data['categories'], 'slug'));
        $this->assertSame(['lagos', 'new-tag'], array_column($data['tags'], 'slug')); // tag only used on a post is added
        $this->assertCount(1, $data['media']);
        $this->assertSame('The office front', $data['media'][0]['alt_text']);
        $this->assertSame('image/jpeg', $data['media'][0]['mime_type']);

        $this->assertSame(['601', '602'], array_column($data['posts'], 'id')); // pages are not blog posts
        [$post, $draft] = $data['posts'];
        $this->assertSame('7', $post['author']);
        $this->assertSame('501', $post['featured_media']);
        $this->assertSame(['31'], $post['categories']);
        $this->assertSame('2024-03-05T09:30:00', $post['date_gmt']);
        $this->assertStringContainsString('<p>First paragraph about <strong>land</strong>.</p>', $post['content']['rendered']);
        $this->assertStringContainsString("<p>Second line one<br>\nsecond line two</p>", $post['content']['rendered']);
        $this->assertStringContainsString("\n<h2>Heading</h2>\n", $post['content']['rendered']);
        $this->assertSame('post-602', $draft['slug']);
        $this->assertNull($draft['date_gmt']);

        $this->assertSame(['9001', '9002'], array_column($data['comments'], 'id')); // pingback left out
        $this->assertSame(['approved', 'hold'], array_column($data['comments'], 'status'));
        $this->assertStringContainsString('shortcodes [gallery]', implode(' ', $data['notes']));
    }

    public function test_bad_files_are_refused(): void
    {
        $this->assertThrows(fn () => WxrReader::read(base_path('tests/fixtures/missing.xml')), \RuntimeException::class, 'not found');
        $bad = tempnam(sys_get_temp_dir(), 'wxr');
        file_put_contents($bad, '<html><body>not an export</body></html>');
        $this->assertThrows(fn () => WxrReader::read($bad), \RuntimeException::class, 'does not look like');
        unlink($bad);
    }

    public function test_the_export_imports_drafts_moderated_comments_and_commenter_emails(): void
    {
        $this->artisan('mtl:import-wordpress', ['--wxr' => $this->fixture()])->assertSuccessful();

        $post = Post::where('slug', 'buying-land')->sole();
        $this->assertSame('Buying land & the Land Use Act', $post->title);
        $this->assertSame('Ada Writer', $post->author_name);
        $this->assertStringContainsString('href="/contact-us/"', $post->body); // made relative
        $this->assertStringContainsString('src="/images/2024/03/about-us.jpg"', $post->body); // old upload folder renamed
        $this->assertStringContainsString('href="https://example.org/wp-content/uploads/x.pdf"', $post->body); // other sites untouched
        $this->assertSame('images/2024/03/about-us.jpg', $post->cover->path);
        $this->assertSame(['lagos', 'new-tag'], $post->tags()->orderBy('slug')->pluck('slug')->all());
        $this->assertNotNull($post->cover_media_id);
        $this->assertSame('draft', Post::where('slug', 'post-602')->value('status'));
        $this->assertTrue(Redirect::where('from_path', '/2024/03/buying-land/')->where('to_path', '/buying-land/')->exists());

        $comments = Comment::orderBy('id')->get();
        $this->assertSame(['approved', 'pending'], $comments->pluck('status')->all());
        $this->assertSame(['chidi@example.test', null], $comments->pluck('author_email')->all());
        $this->assertArrayNotHasKey('author_email', $comments[0]->toArray());

        // Re-running changes nothing.
        $this->artisan('mtl:import-wordpress', ['--wxr' => $this->fixture()])->expectsOutputToContain('post.unchanged')->assertSuccessful();
        $this->assertSame(2, Post::count());
    }

    public function test_the_verification_passes_after_importing_the_capture_and_catches_later_differences(): void
    {
        $this->seed(\Database\Seeders\PageSeeder::class); // posts link to the rebuilt pages, e.g. /contact/
        $data = WordPressImporter::readDirectory(base_path('docs/source-capture/rest'));
        app(WordPressImporter::class)->import(['data' => $data, 'html_path' => base_path('docs/source-capture/html'), 'draft_slugs' => ['hello-world']]);

        $result = app(ImportVerifier::class)->verify($data, ['hello-world']);
        $this->assertTrue($result['ok'], collect($result['findings'])->where('level', 'fail')->pluck('message', 'item')->toJson());
        $this->assertSame(count($data['posts']), $result['counts']['post']['matching']);
        $this->assertSame(count($data['comments']), $result['counts']['comment']['imported']);
        Storage::disk('local')->assertExists($result['report_path']);
        $this->assertStringContainsString('Result: **PASSED**', Storage::disk('local')->get($result['report_path']));

        // Content changed behind the importer's back is reported.
        $slug = $data['posts'][0]['slug'];
        DB::table('posts')->where('slug', $slug)->update(['body' => '<p>Something else entirely</p>']);
        $result = app(ImportVerifier::class)->verify($data, ['hello-world'], write: false);
        $this->assertFalse($result['ok']);
        $this->assertTrue(collect($result['findings'])->contains(fn ($f) => $f['item'] === "post {$slug}" && str_contains($f['message'], 'body text')));
    }

    public function test_old_image_addresses_redirect_to_the_images_folder(): void
    {
        $this->get('/wp-content/uploads/2025/08/tlk.jpg')->assertStatus(301)->assertRedirect('/images/2025/08/tlk.jpg');
        $this->get('/wp-content/uploads/2025/08/a%20b.png')->assertRedirect('/images/2025/08/a%20b.png');
        $this->assertFileExists(public_path('images/2025/08/tlk.jpg'));
    }

    public function test_the_verify_command_reports_missing_content(): void
    {
        $this->artisan('mtl:verify-import', ['--wxr' => $this->fixture()])
            ->expectsOutputToContain('missing locally')
            ->expectsOutputToContain('Differences found.')
            ->assertFailed();
    }
}
