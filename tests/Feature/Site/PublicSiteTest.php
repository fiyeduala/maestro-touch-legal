<?php

namespace Tests\Feature\Site;

use App\Domain\Identity\Role;
use App\Domain\Operations\Settings;
use App\Models\Page;
use App\Models\Post;
use App\Models\Redirect;
use Database\Seeders\PageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PageSeeder::class);
    }

    public static function mirroredPages(): array
    {
        return [
            'home' => ['/', 'Legal Solutions'],
            'about' => ['/about/', 'About'],
            'offering' => ['/offering/', 'Offering'],
            'contact' => ['/contact/', 'Contact'],
            'terms' => ['/terms-and-conditions/', 'Terms'],
            'blog' => ['/blog/', 'Blog'],
            'careers' => ['/join-our-legal-team/', 'Join Our Legal Team'],
            'log in' => ['/log-in/', 'Log'],
            'register' => ['/register/', 'Register'],
        ];
    }

    #[DataProvider('mirroredPages')]
    public function test_public_pages_render(string $url, string $text): void
    {
        $this->get($url)->assertOk()->assertSee($text, false)
            ->assertSee('<link rel="canonical" href="'.$this->site($url).'">', false);
    }

    public function test_draft_privacy_policy_stays_unpublished_until_approved(): void
    {
        $this->get('/privacy-policy/')->assertNotFound();
        $this->assertNotNull(Page::where('slug', 'privacy-policy')->value('draft_revision_id'));
    }

    public function test_bare_paths_get_a_trailing_slash(): void
    {
        $this->get('/about')->assertRedirect($this->site('/about/'))->assertStatus(301);
        $this->get('/about?x=1')->assertRedirect($this->site('/about/').'?x=1');
    }

    public function test_internal_links_use_the_trailing_slash_form(): void
    {
        foreach (['/', '/log-in/', '/register/', '/contact/'] as $url) {
            preg_match_all('#href="'.preg_quote(url('/'), '#').'(/[a-z0-9/-]*[a-z0-9-])"#', $this->get($url)->getContent(), $m);
            $public = array_filter($m[1], fn ($path) => $path !== '/admin' && ! str_starts_with($path, '/admin/'));
            $this->assertSame([], array_values($public), "Links without a trailing slash on {$url}");
        }
    }

    public function test_unknown_paths_are_404(): void
    {
        $this->get('/no-such-thing/')->assertNotFound();
    }

    public function test_post_visibility_follows_status_and_date(): void
    {
        Post::create(['title' => 'Live one', 'slug' => 'live-one', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()->subHour()]);
        Post::create(['title' => 'Draft one', 'slug' => 'draft-one', 'body' => '<p>x</p>', 'status' => 'draft']);
        Post::create(['title' => 'Later one', 'slug' => 'later-one', 'body' => '<p>x</p>', 'status' => 'scheduled', 'published_at' => now()->addDay()]);

        $this->get('/live-one/')->assertOk()->assertSee('Live one');
        $this->get('/draft-one/')->assertNotFound();
        $this->get('/later-one/')->assertNotFound();
        $this->get('/blog/')->assertSee('Live one')->assertDontSee('Draft one')->assertDontSee('Later one');
        $this->get('/sitemap.xml')->assertOk()->assertSee('/live-one/', false)->assertDontSee('draft-one');
        $this->get('/feed/')->assertOk()->assertSee('Live one');
    }

    public function test_redirects_apply_and_count_hits(): void
    {
        Redirect::create(['from_path' => '/old-article/', 'to_path' => '/about/', 'status_code' => 301]);
        Redirect::create(['from_path' => '/gone/', 'status_code' => 410]);

        $this->get('/old-article/')->assertRedirect($this->site('/about/'))->assertStatus(301);
        $this->get('/gone/')->assertStatus(410);
        $this->assertSame(1, Redirect::where('from_path', '/old-article/')->value('hits'));
    }

    public function test_redirects_can_never_shadow_the_admin_or_portal(): void
    {
        Redirect::create(['from_path' => '/admin', 'to_path' => '/about/', 'status_code' => 301]);

        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_robots_blocks_everything_outside_production(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
    }

    public function test_previews_need_a_verified_staff_session(): void
    {
        $page = Page::where('slug', 'about')->sole();

        $this->get(route('preview.page', $page))->assertRedirect();
        $this->actingAsStaff($this->userWithRoles(Role::Lawyer))->get(route('preview.page', $page))->assertForbidden();
        $this->actingAsStaff($this->userWithRoles(Role::ContentEditor))->get(route('preview.page', $page))
            ->assertOk()->assertHeader('X-Robots-Tag');
    }

    public function test_tawk_renders_only_when_configured_on_public_pages(): void
    {
        $this->get('/')->assertDontSee('embed.tawk.to', false);

        Settings::set([
            'integrations.tawk_enabled' => true,
            'integrations.tawk_property_id' => '64f1a2b3c4d5e6f7a8b9c0d1',
            'integrations.tawk_widget_id' => '1h9abcdef',
        ]);

        $this->get('/')->assertSee('embed.tawk.to', false);
        $this->get('/log-in/')->assertSee('embed.tawk.to', false);
    }
}
