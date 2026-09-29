<?php

namespace App\Domain\Billing;

use App\Domain\Identity\Role;
use App\Domain\Operations\StaffNotifier;
use App\Models\User;
use App\Notifications\StaffAlert;

/** Alerts for work only finance can do (verifying transfers, reviewing mismatches). */
final class FinanceAlerts
{
    public static function send(string $subject, string $line, string $path): void
    {
        $alert = new StaffAlert($subject, $line, $path);
        StaffNotifier::administrators($alert, 'Finance');

        $finance = User::query()->active()->withActiveRole(Role::FinanceOfficer)->get()->reject(fn (User $u) => $u->isFullAdministrator());
        StaffNotifier::users($finance, $alert);
    }
}
