<?php

namespace App\Policies;

use App\Domain\Billing\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\BillingAccess;

class InvoicePolicy
{
    use BillingAccess;

    public function viewAny(User $user): bool
    {
        return $this->isFinance($user) || $this->isCaseStaff($user);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->isFinance($user) || $this->readsMatter($user, $invoice->matter);
    }

    public function create(User $user): bool
    {
        return $this->isFinance($user);
    }

    /** Editing lines is possible only while the invoice is a draft. */
    public function update(User $user, Invoice $invoice): bool
    {
        return $this->isFinance($user) && $invoice->status === InvoiceStatus::Draft;
    }

    /** Issue, credit, record payments against it. */
    public function manage(User $user, Invoice $invoice): bool
    {
        return $this->isFinance($user);
    }

    public function viewAsClient(User $user, Invoice $invoice): bool
    {
        return $invoice->status->isIssued() && $this->isClientOf($user, $invoice->client_id);
    }

    public function payAsClient(User $user, Invoice $invoice): bool
    {
        return $invoice->status->isOpen() && $invoice->balanceMinor() > 0 && $this->actsAsClient($user, $invoice->client_id);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}
