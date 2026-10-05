<?php

namespace App\Policies;

use App\Domain\Identity\Role;
use App\Models\Enquiry;
use App\Models\User;

/** Full administrators: every enquiry. Lawyers and case officers: only enquiries they own. Finance/content: none. */
class EnquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isFullAdministrator() || $this->isCaseStaff($user);
    }

    public function view(User $user, Enquiry $enquiry): bool
    {
        return $user->isFullAdministrator() || ($this->isCaseStaff($user) && $enquiry->owner_id === $user->id);
    }

    /** Staff can record enquiries received by phone, email, WhatsApp or in person; they become the owner. */
    public function create(User $user): bool
    {
        return $user->isFullAdministrator() || $this->isCaseStaff($user);
    }

    /** Triage, status changes, parties, notes and follow-up dates. */
    public function update(User $user, Enquiry $enquiry): bool
    {
        return $this->view($user, $enquiry);
    }

    public function assign(User $user, Enquiry $enquiry): bool
    {
        return $user->isFullAdministrator();
    }

    /** Spam only; the service refuses anything that became work (D48). */
    public function delete(User $user, Enquiry $enquiry): bool
    {
        return $user->isFullAdministrator();
    }

    /** Clearing or flagging a conflict check: full administrators or a lawyer who owns the enquiry. Case officers: read only. */
    public function decideConflict(User $user, Enquiry $enquiry): bool
    {
        return $user->isFullAdministrator()
            || ($user->isActive() && $user->hasRole(Role::Lawyer) && $enquiry->owner_id === $user->id);
    }

    private function isCaseStaff(User $user): bool
    {
        return $user->isActive() && $user->hasRole(Role::Lawyer, Role::CaseOfficer);
    }
}
