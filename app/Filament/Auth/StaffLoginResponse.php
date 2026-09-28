<?php

namespace App\Filament\Auth;

use App\Domain\Operations\Audit;
use App\Http\Middleware\EnsureStaffSessionVerified;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Filament only returns a login response after the password and (when enabled) the 2-step
 * challenge have both passed. Marking the session here lets the panel refuse sessions created
 * any other way (public login, remember-me cookie, invitation acceptance).
 */
class StaffLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = Filament::auth()->user();

        EnsureStaffSessionVerified::mark($request, $user);
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        Audit::record('auth.staff_login', 'Signed in to the staff portal', $user, actor: $user);

        return redirect()->intended(Filament::getUrl());
    }
}
