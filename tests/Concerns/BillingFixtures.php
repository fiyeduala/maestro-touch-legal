<?php

namespace Tests\Concerns;

use App\Domain\Billing\Invoices;
use App\Domain\Billing\Payments;
use App\Domain\Identity\Role;
use App\Domain\Operations\Settings;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\User;

/** Invoices on an opened matter, built through the real billing services. Use with PracticeFixtures. */
trait BillingFixtures
{
    protected User $finance;

    protected function setUpBilling(): void
    {
        $this->finance = $this->userWithRoles(Role::FinanceOfficer);
    }

    protected function invoices(): Invoices
    {
        return app(Invoices::class);
    }

    protected function payments(): Payments
    {
        return app(Payments::class);
    }

    /** An issued invoice for the matter's client with one fee line of the given major-unit amount. */
    protected function issuedInvoice(Matter $matter, string $amount = '100000.00', string $currency = 'NGN'): Invoice
    {
        $invoice = $this->invoices()->createDraft($matter->client, [
            'title' => 'Professional fees', 'currency' => $currency, 'matter_id' => $matter->id,
            'due_date' => now()->addDays(14)->toDateString(),
            'lines' => [['kind' => 'fee', 'description' => 'Company registration', 'quantity' => 1, 'unit' => $amount]],
        ], $this->finance);
        $this->invoices()->issue($invoice, $this->finance);

        return $invoice->refresh();
    }

    protected function configureBank(string $currency = 'NGN'): void
    {
        $c = strtolower($currency);
        Settings::set(array_filter([
            "bank.{$c}_bank_name" => 'Example Bank',
            "bank.{$c}_account_name" => 'Maestro Touch Legal',
            "bank.{$c}_account_number" => '0123456789',
            "bank.{$c}_swift" => $currency === 'USD' ? 'EXAMPLEX' : null,
        ]));
    }
}
