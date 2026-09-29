<?php

use App\Http\Middleware\ApplyRedirects;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, not in the web group: group middleware only runs for matched routes, and most old WordPress URLs match none.
        $middleware->prepend(ApplyRedirects::class);
        $middleware->validateCsrfTokens(except: ['webhooks/paystack']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('portal.home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Uploaded files and tokens never appear in logs or error pages.
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token']);
    })->create();
