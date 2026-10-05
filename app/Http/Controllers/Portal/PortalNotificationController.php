<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The client's notifications (D49): the same entries that arrive as browser pushes, kept here so nothing
 * is missed when alerts are off. Opening one marks it read and goes to its page on this site.
 */
class PortalNotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('portal.notifications', [
            'notifications' => $request->user()->notifications()->latest()->paginate(25),
        ]);
    }

    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return redirect()->to(self::target($notification->data['url'] ?? null));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return redirect()->route('portal.notifications')->with('status', 'All notifications marked as read.');
    }

    /** Only pages on this site; anything else goes to the Client Area home. */
    public static function target(mixed $url): string
    {
        if (! is_string($url) || $url === '') {
            return route('portal.home');
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return url($url);
        }

        return parse_url($url, PHP_URL_HOST) === parse_url(config('app.url'), PHP_URL_HOST)
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : route('portal.home');
    }
}
