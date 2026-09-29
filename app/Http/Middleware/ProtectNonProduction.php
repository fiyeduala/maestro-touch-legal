<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps non-production copies private (DECISIONS D37). Every response outside production is marked noindex.
 * With staging protection on, every request needs the staging username and password (HTTP Basic, over HTTPS),
 * except the signed Paystack webhook and the health check. With protection on but no credentials set, the
 * site stays locked rather than open.
 */
class ProtectNonProduction
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction()) {
            return $next($request);
        }

        if (config('staging.protect') && ! $request->is(...config('staging.open_paths'))) {
            if ($refusal = $this->refuse($request)) {
                return $this->noindex($refusal);
            }
        }

        return $this->noindex($next($request));
    }

    /** Staging never emails clients: everything goes to one tester address, or is only written to the log. */
    public static function configureMail(): void
    {
        if (! app()->environment('staging')) {
            return;
        }
        if (filled(config('staging.mail_to'))) {
            Mail::alwaysTo((string) config('staging.mail_to'));
        } else {
            config(['mail.default' => 'log']);
        }
    }

    private function refuse(Request $request): ?Response
    {
        $user = (string) config('staging.user');
        $hash = (string) config('staging.password_hash');
        if ($user === '' || $hash === '') {
            return response('This staging site is locked until STAGING_USER and STAGING_PASSWORD_HASH are set.', 503)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $key = 'staging-auth:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response('Too many attempts. Try again in a minute.', 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        $given = (string) $request->getUser();
        $password = (string) $request->getPassword();
        if ($given !== '' && hash_equals($user, $given) && Hash::check($password, $hash)) {
            return null;
        }
        if ($given !== '' || $password !== '') {
            RateLimiter::hit($key, 60);
        }

        return response('Staging site: sign in required.', 401)
            ->header('WWW-Authenticate', 'Basic realm="Maestro Touch Legal staging", charset="UTF-8"')
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function noindex(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
