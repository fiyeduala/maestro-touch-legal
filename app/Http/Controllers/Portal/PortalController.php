<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Operations\Audit;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/** Client portal shell: landing page and profile/security. Matters, documents and billing arrive in Phase 3+. */
class PortalController extends Controller
{
    public function home(Request $request): View
    {
        return view('portal.home', [
            'user' => $request->user(),
            'clients' => $request->user()->clients()->get(),
        ]);
    }

    public function profile(Request $request): View
    {
        $sessions = DB::table('sessions')->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($s) => (object) [
                'current' => $s->id === $request->session()->getId(),
                'ip' => $s->ip_address,
                'agent' => mb_substr((string) $s->user_agent, 0, 120),
                'last_active' => Carbon::createFromTimestamp($s->last_activity),
            ]);

        return view('portal.profile', ['user' => $request->user(), 'sessions' => $sessions]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s-]{7,40}$/'],
        ]);

        $user = $request->user()->fill($data);
        $changes = Audit::diff($user, ['name', 'phone']);
        $user->save();
        if ($changes['after']) {
            Audit::record('user.profile_updated', 'Updated own profile', $user, $changes);
        }

        return back()->with('status', 'Your details have been saved.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => Hash::make($request->input('password'))])->save();
        $this->deleteOtherSessions($request);
        Audit::record('auth.password_changed', 'Changed own password (other sessions signed out)', $user);

        return back()->with('status', 'Your password has been changed and your other sessions were signed out.');
    }

    public function logoutOtherSessions(Request $request): RedirectResponse
    {
        $request->validateWithBag('sessions', ['current_password' => ['required', 'current_password']]);

        $count = $this->deleteOtherSessions($request);
        Audit::record('auth.sessions_revoked', "Signed out {$count} other session(s)", $request->user());

        return back()->with('status', 'Your other sessions have been signed out.');
    }

    private function deleteOtherSessions(Request $request): int
    {
        $user = $request->user();
        // Rotating the remember token stops "remember me" cookies on other devices.
        $user->setRememberToken(\Illuminate\Support\Str::random(60));
        $user->save();

        return DB::table('sessions')->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())->delete();
    }
}
