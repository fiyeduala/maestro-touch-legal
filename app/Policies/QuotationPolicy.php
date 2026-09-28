<?php

namespace App\Policies;

use App\Domain\Engagement\OfferStatus;
use App\Domain\Identity\Role;
use App\Models\Quotation;
use App\Models\User;

/**
 * Full administrators and finance: all quotations. Lawyers and case officers: quotations for an enquiry
 * they own or a matter they are on. Clients see sent versions of their own quotations in the portal.
 */
class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isFirmWide($user) || $this->isCaseStaff($user);
    }

    public function view(User $user, Quotation $quotation): bool
    {
        if ($this->isFirmWide($user)) {
            return true;
        }

        return $this->isCaseStaff($user) && (
            ($quotation->enquiry && $quotation->enquiry->owner_id === $user->id)
            || ($quotation->matter && $quotation->matter->isOnTeam($user))
        );
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $quotation->status->isEditable() && $this->view($user, $quotation);
    }

    public function viewAsClient(User $user, Quotation $quotation): bool
    {
        return $user->isActive() && $user->hasRole(Role::Client) && $quotation->current_version_id !== null
            && $quotation->status !== OfferStatus::Draft
            && $user->clients()->whereKey($quotation->client_id)->exists();
    }

    public function respondAsClient(User $user, Quotation $quotation): bool
    {
        return ! $user->isFullAdministrator() && $this->viewAsClient($user, $quotation)
            && $quotation->status === OfferStatus::Sent;
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        return false;
    }

    private function isFirmWide(User $user): bool
    {
        return $user->isFullAdministrator() || ($user->isActive() && $user->hasRole(Role::FinanceOfficer));
    }

    private function isCaseStaff(User $user): bool
    {
        return $user->isActive() && $user->hasRole(Role::Lawyer, Role::CaseOfficer);
    }
}
