<?php

namespace App\Policies;

use App\Models\StaffApplication;
use App\Models\User;

/** Applications are submitted publicly and changed only through review actions (StaffApplications). */
class StaffApplicationPolicy extends AdministratorOnlyPolicy
{
    public function review(User $user, StaffApplication $application): bool
    {
        return $user->isFullAdministrator();
    }
}
