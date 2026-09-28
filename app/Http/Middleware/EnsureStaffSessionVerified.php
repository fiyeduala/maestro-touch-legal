<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff panel requests need a session that came through the Filament login (password + 2-step).
 * Any other authenticated session is signed out and sent to /admin/login.
 */
class EnsureStaffSessionVerified
{
    public const SESSION_KEY = 'staff_login_verified';

    public static function mark(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->getKey(),
            'at' => now()->getTimestamp(),
        ]);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();
        $mark = $request->session()->get(self::SESSION_KEY);

        if ($user && (! is_array($mark) || ($mark['user_id'] ?? null) !== $user->getKey())) {
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(Filament::getLoginUrl());
        }

        return $next($request);
    }
}
