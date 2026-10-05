<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers, set by the application so they apply even where Apache's mod_headers is off.
 * (public/.htaccess sets the same ones for static files.) Pages shown to a signed-in user are never stored
 * by the browser or a proxy, so the back button on a shared computer cannot reveal a client's matter.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        if (! $headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        // The video call page (D50) sets its own policy allowing the camera and microphone for the call frame only.
        if (! $headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(self)');
        }
        if ($request->isSecure() && app()->isProduction()) {
            // No includeSubDomains: other subdomains (e.g. the notary service, webmail) are managed separately.
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        if ($request->user() && ! str_contains((string) $headers->get('Cache-Control'), 'public')) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
