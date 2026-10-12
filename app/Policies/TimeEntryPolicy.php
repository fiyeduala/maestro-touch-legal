<?php

namespace App\Policies;

use App\Models\Matter;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\Concerns\BillingAccess;

/**
 * Time recording (D53). Lawyers and case officers record their own time on matters they are on; full
 * administrators on any matter. Finance reads all entries, corrects unbilled ones and bills them.
 * Entries on an invoice are fixed: take them off the draft, or credit the issued invoice.
 */
class TimeEntryPolicy
{
    use BillingAccess;

    public function viewAny(User $user): bool
    {
        return $this->isFinance($user) || $this->isCaseStaff($user);
    }

    public function view(User $user, TimeEntry $entry): bool
    {
        return $this->isFinance($user) || $this->readsMatter($user, $entry->matter);
    }

    /** Whether the user records time at all; the matter is checked by record(). */
    public function create(User $user): bool
    {
        return ($user->isActive() && $user->isFullAdministrator()) || $this->isCaseStaff($user);
    }

    public function record(User $user, Matter $matter): bool
    {
        return ($user->isActive() && $user->isFullAdministrator()) || $this->readsMatter($user, $matter);
    }

    public function update(User $user, TimeEntry $entry): bool
    {
        if (! $entry->isOpen()) {
            return false;
        }

        return $this->isFinance($user) || ($entry->user_id === $user->id && $this->record($user, $entry->matter));
    }

    public function void(User $user, TimeEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    /** Stopping or discarding a running timer: its owner only. */
    public function stop(User $user, TimeEntry $entry): bool
    {
        return $entry->isRunning() && $entry->user_id === $user->id && $user->isActive();
    }

    public function delete(User $user, TimeEntry $entry): bool
    {
        return false; // finished entries are voided with a reason
    }
}
