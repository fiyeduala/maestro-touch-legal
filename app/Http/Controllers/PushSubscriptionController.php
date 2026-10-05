<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Browser push opt-in and opt-out (D49). A subscription belongs to one browser on one device; the
 * endpoint is unique, so a shared computer that changes hands moves the subscription to whoever
 * turned alerts on last.
 */
class PushSubscriptionController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:500'],
            'p256dh' => ['required', 'string', 'max:255'],
            'auth' => ['required', 'string', 'max:255'],
        ]);

        PushSubscription::updateOrCreate(['endpoint' => $data['endpoint']], [
            'user_id' => $request->user()->id,
            'p256dh' => $data['p256dh'],
            'auth' => $data['auth'],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);
        PushSubscription::where('endpoint', $data['endpoint'])->where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true]);
    }
}
