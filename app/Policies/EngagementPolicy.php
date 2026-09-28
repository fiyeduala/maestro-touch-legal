<?php

namespace App\Policies;

use App\Domain\Engagement\OfferStatus;
use App\Domain\Identity\Role;
use App\Models\Engagement;
use App\Models\User;

/**
 * Engagement terms: prepared by full administrators or the lawyer who owns the enquiry; the internal
 * approval that opens a matter and starts representation is reserved to full administrators (D16).
 */
class EngagementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isFullAdministrator() || $this->isLawyer($user);
    }

    public function view(User $user, Engagement $engagement): bool
    {
        if ($user->isFullAdministrator()) {
            return true;
        }

        return $this->isLawyer($user) && (
            ($engagement->enquiry && $engagement->enquiry->owner_id === $user->id)
            || ($engagement->matter && $engagement->matter->isOnTeam($user))
        );
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Engagement $engagement): bool
    {
        return $engagement->status->isEditable() && $this->view($user, $engagement);
    }

    public function approve(User $user, Engagement $engagement): bool
    {
        return $user->isFullAdministrator() && $engagement->status === OfferStatus::Accepted;
    }

    /** Recording a signed paper copy returned by the client: staff record it with evidence; never "as" the client. */
    public function recordOfflineAcceptance(User $user, Engagement $engagement): bool
    {
        return $engagement->status === OfferStatus::Sent && $this->view($user, $engagement);
    }

    public function viewAsClient(User $user, Engagement $engagement): bool
    {
        return $user->isActive() && $user->hasRole(Role::Client) && $engagement->status !== OfferStatus::Draft
            && $user->clients()->whereKey($engagement->client_id)->exists();
    }

    public function respondAsClient(User $user, Engagement $engagement): bool
    {
        return ! $user->isFullAdministrator() && $this->viewAsClient($user, $engagement)
            && $engagement->status === OfferStatus::Sent;
    }

    public function delete(User $user, Engagement $engagement): bool
    {
        return false;
    }

    private function isLawyer(User $user): bool
    {
        return $user->isActive() && $user->hasRole(Role::Lawyer);
    }
}
