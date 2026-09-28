<?php

namespace App\Policies;

use App\Models\User;

/** Services, intake forms and engagement templates: configured by full administrators only. No hard deletes. */
abstract class FirmConfigurationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isFullAdministrator();
    }

    public function view(User $user, mixed $record): bool
    {
        return $user->isFullAdministrator();
    }

    public function create(User $user): bool
    {
        return $user->isFullAdministrator();
    }

    public function update(User $user, mixed $record): bool
    {
        return $user->isFullAdministrator();
    }

    public function delete(User $user, mixed $record): bool
    {
        return false;
    }
}
