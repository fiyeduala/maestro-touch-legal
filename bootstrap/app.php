<?php

use App\Http\Middleware\ApplyRedirects;
use App\Http\Middleware\ProtectNonProduction;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Cloudflare;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Visitor address and https come from Cloudflare's headers, trusted only from Cloudflare's own ranges.
        $middleware->trustProxies(at: Cloudflare::PROXIES, headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        // Global, not in the web group: group middleware only runs for matched routes, and most old WordPress URLs match none.
        $middleware->prepend(ApplyRedirects::class);
        $middleware->prepend(ProtectNonProduction::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->validateCsrfTokens(except: ['webhooks/paystack']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('portal.home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Uploaded files and tokens never appear in logs or error pages.
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token']);
    })->create();

// When the web files are served from another folder (public_html, docs/DEPLOYMENT.md layout B), public-path.php in
// the app folder names it, so Terminal commands (backups, imports, Filament assets) use the same folder as the website.
if (is_file($publicPath = dirname(__DIR__).'/public-path.php')) {
    $app->usePublicPath(rtrim((string) require $publicPath, '/\\'));
}

return $app;
