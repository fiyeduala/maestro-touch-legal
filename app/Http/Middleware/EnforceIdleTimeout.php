<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a user out after a period without requests (D54). Staff sessions that came through the staff
 * login (password + 2-step) may stay idle for session.staff_idle_minutes (24 hours by default); every
 * other signed-in session, such as a client's, keeps session.client_idle_minutes (SESSION_LIFETIME).
 * The session itself lives for the longer of the two, so this check is what ends client sessions.
 */
class EnforceIdleTimeout
{
    public const SESSION_KEY = 'auth.last_activity';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $user = Auth::user();
        $now = now()->getTimestamp();

        if ($user) {
            $last = (int) $session->get(self::SESSION_KEY, $now);
            $minutes = EnsureStaffSessionVerified::verified($request, $user)
                ? (int) config('session.staff_idle_minutes')
                : (int) config('session.client_idle_minutes');

            if ($now - $last > $minutes * 60) {
                Auth::logout();
                $session->invalidate();
                $session->regenerateToken();
            }
        }

        $session->put(self::SESSION_KEY, $now);

        return $next($request);
    }
}
