<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\AccountAdministration;
use App\Domain\Operations\Audit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/** "Lost your password?" flow at /password-reset/, used by clients and staff alike. */
class PasswordController extends Controller
{
    private const NEUTRAL = 'If an account exists for that address, we have emailed a link to create a new password.';

    public function request(): View
    {
        return view('auth.password-request');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:190']]);

        $status = Password::sendResetLink(['email' => Str::lower($request->input('email'))]);

        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages(['email' => 'Please wait a minute before asking for another link.']);
        }

        // Same message whether or not the account exists.
        return back()->with('status', self::NEUTRAL);
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.password-reset', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function update(Request $request, AccountAdministration $accounts): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            ['email' => Str::lower($request->input('email'))] + $request->only('password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($accounts) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                // Sign out everywhere else: a reset usually means the old password may be known.
                $accounts->revokeSessions($user, null, 'password_reset');
                Audit::record('auth.password_reset', 'Password reset by email link', $user, actor: $user);
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This password reset link is invalid or has expired. Please request a new one.']);
        }

        return redirect()->route('login')->with('status', 'Your password has been changed. You can now sign in.');
    }
}
