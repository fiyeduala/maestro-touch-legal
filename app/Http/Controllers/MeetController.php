<?php

namespace App\Http\Controllers;

use App\Domain\Meetings\VideoRooms;
use App\Http\Middleware\EnsureStaffSessionVerified;
use App\Models\Consultation;
use App\Models\Meeting;
use App\Support\Video\DailyVideo;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The video call page (D50). Staff need a session that came through the staff login (password + 2-step);
 * clients need an active account with a verified email. Everything about who may join, and when, is
 * decided in VideoRooms; this only signs people in and shows the result.
 */
class MeetController extends Controller
{
    public function __construct(private VideoRooms $rooms, private DailyVideo $daily) {}

    public function meeting(Request $request, Meeting $meeting): Response
    {
        return $this->signedIn($request) ?? $this->show($this->rooms->joinMeeting($meeting, $request->user()), $request);
    }

    public function consultation(Request $request, Consultation $consultation): Response
    {
        return $this->signedIn($request) ?? $this->show($this->rooms->joinConsultation($consultation, $request->user()), $request);
    }

    /** The signed link emailed to a consultation contact without a portal account. */
    public function consultationGuest(Request $request, Consultation $consultation): Response
    {
        return $this->show($this->rooms->joinConsultationAsGuest($consultation), $request);
    }

    /** A redirect when the person must sign in (again) first, otherwise null. */
    private function signedIn(Request $request): ?Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->guest(route('login'));
        }
        if (! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account cannot sign in at the moment. Please contact the firm.']);
        }
        if ($user->isStaff() && ! EnsureStaffSessionVerified::verified($request, $user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->to(Filament::getPanel('admin')->getLoginUrl());
        }
        if (! $user->isStaff() && ! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return null;
    }

    private function show(array $result, Request $request): Response
    {
        $response = response()->view('meet.room', [
            'result' => $result,
            'jsUrl' => config('video.daily.js_url'),
            'back' => $this->back($request),
        ]);

        // Camera, microphone and screen sharing for this page and the Daily call frame only.
        $domain = $this->daily->domain();
        $allow = $domain !== '' ? '(self "https://'.$domain.'")' : '(self)';
        $response->headers->set('Permissions-Policy', "camera={$allow}, microphone={$allow}, display-capture={$allow}, fullscreen={$allow}, autoplay={$allow}, geolocation=(), payment=()");

        return $response;
    }

    private function back(Request $request): string
    {
        $user = $request->user();
        if (! $user) {
            return url('/');
        }

        return $user->isStaff() ? url('/admin/meetings') : route('portal.appointments');
    }
}
