<?php

namespace App\Policies;

use App\Models\User;

/**
 * Base for areas only full administrators (Technical Administrator, Firm Principal) may open.
 * Records here change only through domain actions, never through generic create/edit/delete.
 */
abstract class AdministratorOnlyPolicy
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
        return false;
    }

    public function update(User $user, mixed $record): bool
    {
        return false;
    }

    public function delete(User $user, mixed $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, mixed $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, mixed $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, mixed $record): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
