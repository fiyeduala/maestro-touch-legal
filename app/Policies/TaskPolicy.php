<?php

namespace App\Policies;

use App\Domain\Identity\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Tasks are matter work: access follows the matter. */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isFullAdministrator() || ($user->isActive() && $user->hasRole(Role::Lawyer, Role::CaseOfficer));
    }

    public function view(User $user, Task $task): bool
    {
        return Gate::forUser($user)->allows('view', $task->matter);
    }

    public function update(User $user, Task $task): bool
    {
        return Gate::forUser($user)->allows('manageTasks', $task->matter);
    }

    public function delete(User $user, Task $task): bool
    {
        return false;
    }
}
