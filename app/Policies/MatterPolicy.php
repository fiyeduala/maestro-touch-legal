<?php

namespace App\Policies;

use App\Domain\Identity\Role;
use App\Models\Matter;
use App\Models\User;

/**
 * Staff access to matters. Client access goes through the *AsClient abilities and the portal;
 * finance and content roles have no matter access in Phase 3 (billing metadata arrives in Phase 5).
 */
class MatterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isFullAdministrator() || $this->isCaseStaff($user);
    }

    public function view(User $user, Matter $matter): bool
    {
        return $user->isFullAdministrator() || ($this->isCaseStaff($user) && $matter->isOnTeam($user));
    }

    /** Matters open only through engagement approval (Engagements::approve). */
    public function create(User $user): bool
    {
        return false;
    }

    /** Stage, next action, summaries, parties, deadlines. Closed matters must be reopened first. */
    public function update(User $user, Matter $matter): bool
    {
        return ! $matter->isClosed() && $this->view($user, $matter);
    }

    public function manageTeam(User $user, Matter $matter): bool
    {
        return $user->isFullAdministrator();
    }

    public function manageTasks(User $user, Matter $matter): bool
    {
        return $this->update($user, $matter);
    }

    public function manageDocuments(User $user, Matter $matter): bool
    {
        return $this->update($user, $matter);
    }

    /** Final approval of a deliverable: full administrators or a lawyer on the team; never a case officer alone. */
    public function approveDeliverables(User $user, Matter $matter): bool
    {
        return ! $matter->isClosed() && ($user->isFullAdministrator()
            || ($user->isActive() && $user->hasRole(Role::Lawyer) && $matter->isOnTeam($user)));
    }

    public function close(User $user, Matter $matter): bool
    {
        if ($matter->isClosed()) {
            return false;
        }

        return $user->isFullAdministrator()
            || ($user->isActive() && $user->hasRole(Role::Lawyer) && $matter->responsible?->user_id === $user->id);
    }

    public function reopen(User $user, Matter $matter): bool
    {
        return $matter->isClosed() && $user->isFullAdministrator();
    }

    public function viewAsClient(User $user, Matter $matter): bool
    {
        return $user->isActive() && $matter->isClientContact($user);
    }

    /**
     * Client decisions (accepting terms, approving drafts, uploading requested files). Only a real client
     * contact acting for themselves; full administrators can never act as a client (spec §4).
     */
    public function actAsClient(User $user, Matter $matter): bool
    {
        return ! $user->isFullAdministrator() && $this->viewAsClient($user, $matter);
    }

    public function delete(User $user, Matter $matter): bool
    {
        return false;
    }

    private function isCaseStaff(User $user): bool
    {
        return $user->isActive() && $user->hasRole(Role::Lawyer, Role::CaseOfficer);
    }
}
