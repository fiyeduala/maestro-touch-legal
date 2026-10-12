<?php

namespace App\Policies;

use App\Models\BillingRate;
use App\Models\User;
use App\Policies\Concerns\BillingAccess;

/** Hourly rates (D53): set by finance officers and full administrators only. */
class BillingRatePolicy
{
    use BillingAccess;

    public function viewAny(User $user): bool
    {
        return $this->isFinance($user);
    }

    public function view(User $user, BillingRate $rate): bool
    {
        return $this->isFinance($user);
    }

    public function create(User $user): bool
    {
        return $this->isFinance($user);
    }

    public function update(User $user, BillingRate $rate): bool
    {
        return $this->isFinance($user);
    }

    public function delete(User $user, BillingRate $rate): bool
    {
        return $this->isFinance($user);
    }
}
