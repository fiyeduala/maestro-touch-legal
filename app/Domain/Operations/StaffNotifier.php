<?php

namespace App\Domain\Operations;

use App\Domain\Identity\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Sends internal alerts. Recipients are real active users, plus addresses an
 * administrator configured in notifications.admin_recipients; none are invented.
 */
final class StaffNotifier
{
    /** Active full administrators plus configured admin addresses. */
    public static function administrators(object $notification, string $context): void
    {
        $admins = User::query()->active()->withActiveRole(...Role::fullAdministratorRoles())->get();
        Notification::send($admins, $notification);

        $extra = array_filter((array) Settings::get('notifications.admin_recipients'),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! $admins->contains('email', strtolower($e)));
        foreach ($extra as $address) {
            Notification::route('mail', $address)->notify($notification);
        }

        if ($admins->isEmpty() && $extra === []) {
            Log::warning("{$context} notification had no recipients: no active administrators or configured admin addresses.");
        }
    }

    /** Specific staff (for example a matter team), skipping anyone no longer active. */
    public static function users(Collection|array $users, object $notification, ?User $except = null): void
    {
        $recipients = collect($users)->filter(fn (?User $u) => $u && $u->isActive() && $u->id !== $except?->id)->unique('id');
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }
}
