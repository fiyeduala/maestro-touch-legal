<?php

namespace App\Policies\Concerns;

use App\Domain\Identity\Role;
use App\Models\Client;
use App\Models\Matter;
use App\Models\User;

/**
 * Billing permissions (permission matrix): full administrators and finance officers manage everything;
 * lawyers and case officers read the billing of matters they are on; clients see their own issued documents.
 */
trait BillingAccess
{
    protected function isFinance(User $user): bool
    {
        return $user->isFullAdministrator() || ($user->isActive() && $user->hasRole(Role::FinanceOfficer));
    }

    protected function isCaseStaff(User $user): bool
    {
        return $user->isActive() && $user->hasRole(Role::Lawyer, Role::CaseOfficer);
    }

    protected function readsMatter(User $user, ?Matter $matter): bool
    {
        return $this->isCaseStaff($user) && $matter !== null && $matter->isOnTeam($user);
    }

    protected function isClientOf(User $user, int $clientId): bool
    {
        return $user->isActive() && $user->hasRole(Role::Client) && $user->clients()->whereKey($clientId)->exists();
    }

    /** Clients act for themselves only; a full administrator never acts as a client (spec §4). */
    protected function actsAsClient(User $user, int $clientId): bool
    {
        return ! $user->isFullAdministrator() && $this->isClientOf($user, $clientId);
    }

    protected function clientIdOf(Client|int $client): int
    {
        return $client instanceof Client ? $client->id : $client;
    }
}
