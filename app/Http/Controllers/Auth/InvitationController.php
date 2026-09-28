<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\AccountAdministrationException;
use App\Domain\Identity\Invitations;
use App\Domain\Identity\Role;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * One-time invitation links (/invitation/{token}). Staff invitees are sent to /admin/login afterwards,
 * where they sign in and must set up 2-step verification before anything else.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $invitation = Invitation::findByToken($token);

        if (! $invitation || ! $invitation->isUsable()) {
            return view('auth.invitation-invalid');
        }

        $existing = User::where('email', $invitation->email)->exists();
        $user = $request->user();

        return view('auth.invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'accountExists' => $existing,
            'signedInAsInvitee' => $user && Str::lower($user->email) === $invitation->email,
            'signedInAsOther' => $user && Str::lower($user->email) !== $invitation->email,
            'roleLabels' => collect($invitation->roles)->map(fn ($r) => Role::tryFrom($r)?->label())->filter()->values(),
        ]);
    }

    public function accept(Request $request, string $token, Invitations $invitations): RedirectResponse|View
    {
        $invitation = Invitation::findByToken($token);
        if (! $invitation || ! $invitation->isUsable()) {
            return view('auth.invitation-invalid');
        }

        try {
            $user = $request->user();
            if ($user) {
                $user = $invitations->acceptExisting($invitation, $user);
            } else {
                $data = $request->validate([
                    'name' => ['required', 'string', 'max:160'],
                    'password' => ['required', 'confirmed', Password::defaults()],
                ]);
                $user = $invitations->acceptNew($invitation, $data['name'], $data['password']);
            }
        } catch (AccountAdministrationException $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        $user->flushRoleCache();

        if ($user->isStaff()) {
            // Staff never keep a session that did not pass the 2-step check.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to(url('/admin/login'))
                ->with('status', 'Your account is ready. Sign in, then set up 2-step verification to continue.');
        }

        if (! $request->user()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return redirect()->route('portal.home')->with('status', 'Welcome. Your account is ready.');
    }
}
