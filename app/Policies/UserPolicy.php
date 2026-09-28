<?php

namespace App\Policies;

use App\Models\User;

/** Accounts are created only by invitation or registration, and are offboarded rather than deleted. */
class UserPolicy extends AdministratorOnlyPolicy
{
    /** Role changes, suspension, offboarding and session revocation (each also re-checked in AccountAdministration). */
    public function manage(User $user, User $account): bool
    {
        return $user->isFullAdministrator();
    }
}
