<?php

namespace App\Policies;

use App\Domain\Identity\Role;
use App\Models\Client;
use App\Models\User;

/** Full administrators: all clients. Lawyers/case officers: clients reachable through their matters or owned enquiries. */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isFullAdministrator() || ($user->isActive() && $user->hasRole(Role::Lawyer, Role::CaseOfficer));
    }

    public function view(User $user, Client $client): bool
    {
        return Client::query()->visibleTo($user)->whereKey($client->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->isFullAdministrator();
    }

    public function update(User $user, Client $client): bool
    {
        return $user->isFullAdministrator();
    }

    /** Portal contacts are added by invitation only; never with a password set by staff. */
    public function manageContacts(User $user, Client $client): bool
    {
        return $user->isFullAdministrator();
    }

    public function delete(User $user, Client $client): bool
    {
        return false;
    }
}
