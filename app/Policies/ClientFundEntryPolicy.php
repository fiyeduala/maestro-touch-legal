<?php

namespace App\Policies;

use App\Domain\Identity\Role;
use App\Models\ClientFundEntry;
use App\Models\User;
use App\Policies\Concerns\BillingAccess;

/** Client-funds ledger: administrators and finance record entries; lawyers read their matters' entries. */
class ClientFundEntryPolicy
{
    use BillingAccess;

    public function viewAny(User $user): bool
    {
        return $this->isFinance($user) || ($user->isActive() && $user->hasRole(Role::Lawyer));
    }

    public function view(User $user, ClientFundEntry $entry): bool
    {
        return $this->isFinance($user)
            || ($user->isActive() && $user->hasRole(Role::Lawyer) && $entry->matter && $entry->matter->isOnTeam($user));
    }

    public function create(User $user): bool
    {
        return $this->isFinance($user);
    }

    public function reverse(User $user, ClientFundEntry $entry): bool
    {
        return $this->isFinance($user);
    }

    public function update(User $user, ClientFundEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, ClientFundEntry $entry): bool
    {
        return false;
    }
}
