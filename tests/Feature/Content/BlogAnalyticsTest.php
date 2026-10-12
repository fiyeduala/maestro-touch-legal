<?php

namespace Tests\Feature\Content;

use App\Domain\Content\PostViews;
use App\Domain\Identity\Role;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Widgets\BlogOverview;
use App\Filament\Widgets\TopBlogPosts;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Blog analytics (D51). */
class BlogAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
    }

    private function makePost(array $attributes = []): Post
    {
        return Post::create($attributes + [
            'title' => 'Buying land in Lagos',
            'slug' => 'buying-land-in-lagos',
            'body' => '<p>Body</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    private function beacon(Post $post, string $event = 'view', array $extra = [], string $agent = self::BROWSER)
    {
        return $this->withHeader('User-Agent', $agent)
            ->post('/blog-beacon', ['post' => $post->id, 'event' => $event] + $extra);
    }

    public function test_the_post_page_carries_the_beacon_but_previews_do_not(): void
    {
        $post = $this->makePost();

        $this->get($this->site('/'.$post->slug))->assertOk()->assertSee('data-post-beacon', false);
        $this->actingAsStaff($this->userWithRoles(Role::FirmPrincipal))
            ->get(route('preview.post', $post))->assertOk()->assertDontSee('data-post-beacon', false);
    }

    public function test_a_visitor_counts_once_a_day_and_a_read_marks_the_same_visit(): void
    {
        $post = $this->makePost();

        $this->beacon($post, extra: ['referrer' => 'https://www.linkedin.com/feed/'])->assertNoContent();
        $this->beacon($post)->assertNoContent();
        $this->assertSame(1, PostView::count());
        $this->assertNull(PostView::first()->read_at);
        $this->assertSame('linkedin.com', PostView::first()->referrer_host);

        $this->beacon($post, 'read')->assertNoContent();
        $this->assertSame(1, PostView::count());
        $this->assertNotNull(PostView::first()->read_at);

        $this->travel(1)->days();
        $this->beacon($post);
        $this->assertSame(2, PostView::count());
    }

    public function test_no_address_is_stored(): void
    {
        $this->makePost();
        $this->beacon(Post::first());

        $row = PostView::first()->getAttributes();
        $this->assertNotContains('127.0.0.1', $row);
        $this->assertSame(64, strlen($row['visitor_hash']));
    }

    public function test_staff_robots_and_hidden_posts_are_not_counted(): void
    {
        $post = $this->makePost();
        $draft = $this->makePost(['slug' => 'draft-post', 'status' => 'draft']);

        $this->beacon($post, agent: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')->assertNoContent();
        $this->beacon($draft)->assertNoContent();
        $this->actingAs($this->userWithRoles(Role::Lawyer));
        $this->beacon($post)->assertNoContent();

        $this->assertSame(0, PostView::count());
    }

    public function test_a_signed_in_client_is_recorded_by_name(): void
    {
        $post = $this->makePost();
        $client = $this->userWithRoles(Role::Client);
        $this->actingAs($client);
        $this->beacon($post);
        $this->beacon($post, 'read');

        $this->assertSame($client->id, PostView::sole()->user_id);

        Comment::create(['post_id' => $post->id, 'author_name' => 'Ada Obi', 'author_email' => 'ada@example.test', 'body' => 'Helpful', 'status' => 'pending', 'posted_at' => now()]);

        $this->actingAsStaff($this->userWithRoles(Role::ContentEditor))
            ->get("/admin/posts/{$post->id}/analytics")
            ->assertOk()
            ->assertSee($client->name)
            ->assertSee($client->email)
            ->assertSee('Ada Obi')
            ->assertSee('ada@example.test');
    }

    public function test_only_content_managers_open_the_analytics(): void
    {
        $post = $this->makePost();

        $this->actingAsStaff($this->userWithRoles(Role::Lawyer))
            ->get("/admin/posts/{$post->id}/analytics")->assertForbidden();
    }

    public function test_the_posts_list_and_dashboard_show_the_counts(): void
    {
        $post = $this->makePost();
        $this->beacon($post);
        $this->beacon($post, 'read');

        $this->actingAsStaff($this->userWithRoles(Role::ContentEditor));

        Livewire::test(ListPosts::class)->assertOk()->assertTableColumnStateSet('views_count', 1, $post)
            ->assertTableColumnStateSet('reads_count', 1, $post);
        Livewire::test(TopBlogPosts::class)->assertOk()->assertCanSeeTableRecords([$post]);
        Livewire::test(BlogOverview::class)->assertOk()->assertSee('Blog, last 30 days');
        $this->get('/admin')->assertOk()->assertSeeLivewire(BlogOverview::class)->assertSeeLivewire(TopBlogPosts::class);
    }

    public function test_dashboard_blog_widgets_are_hidden_from_other_staff(): void
    {
        $this->actingAsStaff($this->userWithRoles(Role::Lawyer));

        $this->assertFalse(BlogOverview::canView());
        $this->assertFalse(TopBlogPosts::canView());
    }

    public function test_past_visits_import_from_an_access_log_once(): void
    {
        $post = $this->makePost();
        $log = tempnam(sys_get_temp_dir(), 'log');
        file_put_contents($log, implode("\n", [
            '102.89.1.10 - - [01/Oct/2026:10:00:00 +0000] "GET /buying-land-in-lagos/ HTTP/1.1" 200 5120 "https://www.facebook.com/" "'.self::BROWSER.'"',
            '102.89.1.10 - - [01/Oct/2026:10:05:00 +0000] "GET /buying-land-in-lagos/ HTTP/1.1" 200 5120 "-" "'.self::BROWSER.'"',
            '66.249.66.1 - - [01/Oct/2026:11:00:00 +0000] "GET /buying-land-in-lagos/ HTTP/1.1" 200 5120 "-" "Googlebot/2.1"',
            '102.89.1.11 - - [02/Oct/2026:09:00:00 +0000] "GET /buying-land-in-lagos/?utm_source=x HTTP/1.1" 200 5120 "-" "'.self::BROWSER.'"',
            '102.89.1.12 - - [02/Oct/2026:09:00:00 +0000] "GET /about/ HTTP/1.1" 200 5120 "-" "'.self::BROWSER.'"',
            '102.89.1.13 - - [02/Oct/2026:09:00:00 +0000] "GET /buying-land-in-lagos/ HTTP/1.1" 404 512 "-" "'.self::BROWSER.'"',
        ]));

        $this->artisan('mtl:import-post-views', ['files' => [$log]])->assertSuccessful();
        $this->artisan('mtl:import-post-views', ['files' => [$log]])->assertSuccessful();
        @unlink($log);

        $this->assertSame(2, $post->views()->count());
        $this->assertSame(0, $post->views()->whereNotNull('read_at')->count());
        $this->assertSame(['access_log'], $post->views()->distinct()->pluck('source')->all());
        $this->assertSame('facebook.com', $post->views()->orderBy('viewed_on')->first()->referrer_host);
    }

    public function test_robot_detection(): void
    {
        $this->assertTrue(PostViews::isBot(null));
        $this->assertTrue(PostViews::isBot('facebookexternalhit/1.1'));
        $this->assertFalse(PostViews::isBot(self::BROWSER));
    }
}
