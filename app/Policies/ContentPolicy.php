<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Website content: full administrators and Content Editors. Publishing is a separate ability
 * ("publish-content"), granted to Content Editors only when the firm enables it in Settings.
 */
abstract class ContentPolicy
{
    protected function manages(User $user): bool
    {
        return Gate::forUser($user)->allows('manage-content');
    }

    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, mixed $record): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, mixed $record): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, mixed $record): bool
    {
        return $this->manages($user);
    }

    public function deleteAny(User $user): bool
    {
        return false; // one at a time, so each deletion is deliberate and audited
    }

    public function publish(User $user, mixed $record = null): bool
    {
        return Gate::forUser($user)->allows('publish-content');
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
