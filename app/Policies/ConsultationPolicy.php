<?php

namespace App\Policies;

use App\Domain\Identity\Role;
use App\Models\Consultation;
use App\Models\User;

/**
 * Staff: full administrators see every consultation; lawyers and case officers see those they host,
 * those for enquiries they own and those linked to matters they are on the team of.
 * Clients: consultations for a client record they are a current contact of.
 */
class ConsultationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isFullAdministrator() || $this->isCaseStaff($user);
    }

    public function view(User $user, Consultation $consultation): bool
    {
        return Consultation::visibleTo($user)->whereKey($consultation->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /** Confirm, reschedule, cancel and record the outcome. */
    public function update(User $user, Consultation $consultation): bool
    {
        return $this->view($user, $consultation);
    }

    /** Choosing who hosts, and handling unassigned requests, is for full administrators. */
    public function assignHost(User $user, Consultation $consultation): bool
    {
        return $user->isFullAdministrator() || ($this->isCaseStaff($user) && $consultation->host_id === $user->id);
    }

    public function delete(User $user, Consultation $consultation): bool
    {
        return false;
    }

    public function viewAsClient(User $user, Consultation $consultation): bool
    {
        return $user->isActive() && $consultation->client_id !== null && $user->hasRole(Role::Client)
            && $user->clients()->whereKey($consultation->client_id)->exists();
    }

    /** Rescheduling or cancelling from the portal. Full administrators never act as a client. */
    public function actAsClient(User $user, Consultation $consultation): bool
    {
        return ! $user->isFullAdministrator() && $this->viewAsClient($user, $consultation);
    }

    private function isCaseStaff(User $user): bool
    {
        return $user->isActive() && $user->hasRole(Role::Lawyer, Role::CaseOfficer);
    }
}
