<?php

namespace App\Policies;

use App\Models\User;

/** Comments come from readers (or the WordPress import); staff moderate them but never write them. */
class CommentPolicy extends ContentPolicy
{
    public function create(User $user): bool
    {
        return false;
    }
}
