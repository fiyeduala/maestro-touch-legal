<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use App\Policies\Concerns\BillingAccess;

class ExpensePolicy
{
    use BillingAccess;

    public function viewAny(User $user): bool
    {
        return $this->isFinance($user) || $this->isCaseStaff($user);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->isFinance($user) || $this->readsMatter($user, $expense->matter);
    }

    public function create(User $user): bool
    {
        return $this->isFinance($user);
    }

    public function update(User $user, Expense $expense): bool
    {
        return false; // recorded expenses are voided with a reason, not edited
    }

    public function void(User $user, Expense $expense): bool
    {
        return $this->isFinance($user);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return false;
    }
}
