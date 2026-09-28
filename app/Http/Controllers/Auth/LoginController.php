<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Operations\Audit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Client portal sign-in at /log-in/. Staff accounts must use /admin/login, which enforces
 * 2-step verification; this form refuses them so the second factor cannot be skipped.
 */
class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:500'],
        ]);

        $email = Str::lower($data['email']);
        $key = 'login:'.$email.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Please try again in '.max(1, (int) ceil(RateLimiter::availableIn($key) / 60)).' minute(s).',
            ]);
        }

        $user = User::where('email', $email)->first();

        // A hash check runs even for unknown emails so response time does not reveal which accounts exist.
        $hash = $user?->password ?? Cache::rememberForever('auth.dummy_hash', fn () => Hash::make(Str::random(40)));
        if (! Hash::check($data['password'], $hash) || ! $user) {
            RateLimiter::hit($key, 300);
            Audit::record('auth.login_failed', 'Failed portal sign-in', context: ['email_hash' => hash('sha256', $email)]);

            throw ValidationException::withMessages(['email' => 'The email address or password is incorrect.']);
        }

        if ($user->isStaff()) {
            throw ValidationException::withMessages([
                'email' => 'Staff accounts sign in through the staff portal, which requires 2-step verification.',
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account cannot sign in at the moment. Please contact the firm.']);
        }

        RateLimiter::clear($key);
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        Audit::record('auth.login', 'Signed in to the client portal', $user, actor: $user);

        return redirect()->intended(route('portal.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            Audit::record('auth.logout', 'Signed out', $user, actor: $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(url('/'));
    }
}
