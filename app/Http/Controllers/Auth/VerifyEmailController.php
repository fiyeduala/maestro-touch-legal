<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Operations\Audit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The signed link itself proves control of the mailbox, so verification works without being signed in
 * (registration does not sign the user in).
 */
class VerifyEmailController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('portal.home')
            : view('auth.verify-email');
    }

    public function verify(string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            Audit::record('auth.email_verified', 'Email address verified', $user, actor: $user);
        }

        return auth()->check()
            ? redirect()->route('portal.home')->with('status', 'Thank you. Your email address is verified.')
            : redirect()->route('login')->with('status', 'Thank you. Your email address is verified. You can now sign in.');
    }

    public function send(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', 'A new verification link has been sent to your email address.');
    }
}
