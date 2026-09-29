<?php

namespace App\Policies;

use App\Domain\Billing\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\BillingAccess;

/** Verifying transfers, refunds and reversals: full administrators and finance officers only. */
class PaymentPolicy
{
    use BillingAccess;

    public function viewAny(User $user): bool
    {
        return $this->isFinance($user) || $this->isCaseStaff($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->isFinance($user) || $this->readsMatter($user, $payment->invoice?->matter);
    }

    public function create(User $user): bool
    {
        return $this->isFinance($user);
    }

    public function verify(User $user, Payment $payment): bool
    {
        return $this->isFinance($user);
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    /** A receipt exists only for money that was actually received. */
    public function viewReceiptAsClient(User $user, Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Succeeded && $this->isClientOf($user, $payment->client_id);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
