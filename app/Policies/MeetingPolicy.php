<?php

namespace App\Policies;

use App\Domain\Identity\Role;
use App\Models\Meeting;
use App\Models\User;

/**
 * Video meetings (D50). Full administrators schedule with anyone and manage every meeting. Lawyers and
 * case officers schedule with colleagues and with client contacts of matters they are on, and manage
 * the meetings they organise. Any staff member can see and join the meetings they are invited to.
 * Joining is for invited people only; seeing a meeting in the list does not let anyone into the call.
 */
class MeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->isStaff();
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return $this->viewAny($user) && Meeting::visibleTo($user)->whereKey($meeting->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->isActive() && ($user->isFullAdministrator() || $user->hasRole(Role::Lawyer, Role::CaseOfficer));
    }

    /** Reschedule, change who is invited, cancel. */
    public function update(User $user, Meeting $meeting): bool
    {
        return $this->create($user) && ($user->isFullAdministrator() || $meeting->organiser_id === $user->id);
    }

    public function join(User $user, Meeting $meeting): bool
    {
        return $user->isActive() && $meeting->hasParticipant($user);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return false;
    }
}
