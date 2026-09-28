<?php

namespace App\Policies;

use App\Models\User;

class PagePolicy extends ContentPolicy
{
    /** Mirrored and legally required pages cannot be deleted, only unpublished (except the home page). */
    public function delete(User $user, mixed $record): bool
    {
        return $this->manages($user) && ! $record->is_system;
    }
}
