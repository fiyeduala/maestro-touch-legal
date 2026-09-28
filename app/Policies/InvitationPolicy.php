<?php

namespace App\Policies;

use App\Models\Invitation;
use App\Models\User;

class InvitationPolicy extends AdministratorOnlyPolicy
{
    public function create(User $user): bool
    {
        return $user->isFullAdministrator();
    }

    /** Resend or revoke an open invitation. */
    public function manage(User $user, Invitation $invitation): bool
    {
        return $user->isFullAdministrator() && $invitation->accepted_at === null && $invitation->revoked_at === null;
    }
}
