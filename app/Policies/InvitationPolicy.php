<?php

namespace App\Policies;

use App\Models\User;

class InvitationPolicy extends AdministratorOnlyPolicy
{
    public function create(User $user): bool
    {
        return $user->isFullAdministrator();
    }
}
