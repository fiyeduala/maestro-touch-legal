<?php

namespace Tests\Feature;

use App\Domain\Identity\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/** Phase 6 security checks: headers, route exposure, and the web-server rules shipped with the app. */
class SecurityChecksTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes anyone may reach without signing in. Each is either a public page/form (rate-limited),
     * token-protected (invitation, applicant link), signature-checked (Paystack webhook) or framework plumbing.
     */
    private const PUBLIC_ROUTES = [
        '/', 'blog', 'blog/page/{page}', 'category/{slug}', 'category/{slug}/page/{page}', 'tag/{slug}', 'tag/{slug}/page/{page}',
        '{slug}', '{slug}/comments', 'feed', 'robots.txt', 'sitemap.xml', 'up', 'wp-content/uploads/{path}',
        'contact', 'legal-assistance', 'legal-assistance/thank-you', 'join-our-legal-team', 'join-our-legal-team/thank-you',
        'log-in', 'register', 'password-reset', 'password-reset/{token}', 'logout',
        'invitation/{token}', 'careers/application/{token}', 'careers/application/{token}/respond', 'careers/application/{token}/withdraw',
        'webhooks/paystack',
        'admin/login', 'admin/password-reset/request', 'admin/password-reset/reset',
        // Filament checks the signed-in owner in the controller; Livewire checks a signature on uploads/previews.
        'filament/exports/{export}/download', 'filament/imports/{import}/failed-rows/download',
    ];

    private function isPublic(Route $route): bool
    {
        return in_array($route->uri(), self::PUBLIC_ROUTES, true) || str_starts_with($route->uri(), 'livewire');
    }

    private function isGuarded(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $m) {
            $m = is_string($m) ? $m : '';
            if ($m === 'auth' || str_starts_with($m, 'auth:') || $m === 'signed'
                || in_array($m, [\Illuminate\Auth\Middleware\Authenticate::class, \Filament\Http\Middleware\Authenticate::class, \Illuminate\Routing\Middleware\ValidateSignature::class], true)) {
                return true;
            }
        }

        return false;
    }

    public function test_there_is_no_web_console_installer_or_migration_route(): void
    {
        foreach (Router::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('/artisan|migrat|install|setup|phpinfo|console|telescope|horizon|_ignition|clockwork|adminer|phpmyadmin/i', $route->uri(), "Unexpected route: {$route->uri()}");
        }
    }

    public function test_every_non_public_route_requires_sign_in_or_a_signature(): void
    {
        $unguarded = [];
        foreach (Router::getRoutes() as $route) {
            if (! $this->isPublic($route) && ! $this->isGuarded($route)) {
                $unguarded[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $unguarded, 'Routes reachable without signing in that are not on the public list');
    }

    public function test_guests_get_nothing_from_private_get_routes(): void
    {
        $checked = 0;
        foreach (Router::getRoutes() as $route) {
            if ($this->isPublic($route) || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $status = $this->get($uri)->getStatusCode();
            $this->assertNotSame(200, $status, "Guest got 200 from {$uri}");
            $checked++;
        }
        $this->assertGreaterThan(40, $checked);
    }

    public function test_public_pages_send_the_baseline_headers_and_may_be_cached(): void
    {
        $this->seed(\Database\Seeders\PageSeeder::class);

        $response = $this->get('/')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeaderMissing('Strict-Transport-Security'); // only in production over HTTPS

        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_pages_for_a_signed_in_user_are_never_stored(): void
    {
        $client = $this->userWithRoles(Role::Client);
        $client->forceFill(['email_verified_at' => now()])->save();

        $cache = (string) $this->actingAs($client)->get(route('portal.home'))->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    public function test_hsts_is_sent_in_production_over_https_without_covering_other_subdomains(): void
    {
        $this->seed(\Database\Seeders\PageSeeder::class);
        $this->app['env'] = 'production';

        $hsts = $this->get('https://localhost/')->headers->get('Strict-Transport-Security');
        $this->assertSame('max-age=31536000', $hsts); // no includeSubDomains: the notary service and webmail are separate
    }

    public function test_the_web_server_rules_shipped_with_the_app(): void
    {
        $root = file_get_contents(public_path('.htaccess'));
        $this->assertStringContainsString('-Indexes', $root);
        $this->assertMatchesRegularExpression('/env/', $root); // dotfiles and backups are refused

        foreach (['images', 'media'] as $dir) {
            $rules = file_get_contents(public_path("{$dir}/.htaccess"));
            $this->assertStringContainsString('php[0-9]?|phtml|phar', $rules, "{$dir} must not run scripts");
            $this->assertStringContainsString('-ExecCGI', $rules);
        }

        // Only the front controller is PHP under public/ (apart from anything in ignored upload folders).
        $php = collect(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(public_path(), \FilesystemIterator::SKIP_DOTS)))
            ->filter(fn ($f) => preg_match('/\.(php[0-9]?|phtml|phar)$/i', $f->getFilename()))
            ->map(fn ($f) => str_replace('\\', '/', substr($f->getPathname(), strlen(public_path()) + 1)))
            ->values()->all();
        $this->assertSame(['index.php'], $php);

        $this->assertDirectoryDoesNotExist(public_path('wp-content')); // old images now live in public/images
        $this->assertFileDoesNotExist(public_path('.env'));
        $this->assertFileDoesNotExist(public_path('storage')); // no public symlink to storage: confidential files stay private
    }
}
