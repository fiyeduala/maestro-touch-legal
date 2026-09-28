<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Portal pages: an active account holding the client role. Staff are sent to the staff portal. */
class EnsureActiveClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account cannot sign in at the moment. Please contact the firm.']);
        }

        if (! $user->isClient()) {
            return $user->isStaff() ? redirect()->to(url('/admin')) : abort(403);
        }

        return $next($request);
    }
}
