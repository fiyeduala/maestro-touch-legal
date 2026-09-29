<?php

namespace App\Domain\Billing;

use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Matter;
use App\Models\User;
use App\Support\Money;
use App\Support\References;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Disbursements paid for a client (filing fees, courier, travel). Billable ones can be added to a draft invoice. */
class Expenses
{
    /**
     * @param  array{currency: string, amount_minor: int, description: string, incurred_on: string, billable?: bool}  $data
     */
    public function record(Client $client, ?Matter $matter, array $data, User $actor): Expense
    {
        Gate::forUser($actor)->authorize('create', Expense::class);
        if ($matter && $matter->client_id !== $client->id) {
            throw new RuleViolation('The matter does not belong to this client.');
        }
        $currency = Money::assertCurrency($data['currency']);
        $amount = (int) $data['amount_minor'];
        $description = trim((string) $data['description']);
        if ($amount <= 0 || $description === '') {
            throw new RuleViolation('Enter the amount and a description.');
        }
        if ($data['incurred_on'] > now()->toDateString()) {
            throw new RuleViolation('The expense date cannot be in the future.');
        }

        return DB::transaction(function () use ($client, $matter, $data, $currency, $amount, $description, $actor) {
            $expense = Expense::create([
                'reference' => References::next('expenses', 'EXP'),
                'client_id' => $client->id,
                'matter_id' => $matter?->id,
                'currency' => $currency,
                'amount_minor' => $amount,
                'description' => mb_substr($description, 0, 300),
                'incurred_on' => $data['incurred_on'],
                'billable' => (bool) ($data['billable'] ?? true),
                'created_by' => $actor->id,
            ]);
            Audit::record('expense.recorded', "Expense {$expense->reference} recorded (".Money::format($amount, $currency).')', $expense, actor: $actor);

            return $expense;
        });
    }

    public function void(Expense $expense, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('void', $expense);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuleViolation('Record why the expense is voided.');
        }

        DB::transaction(function () use ($expense, $reason, $actor) {
            $expense = Expense::lockForUpdate()->findOrFail($expense->id);
            if ($expense->voided_at) {
                throw new RuleViolation('This expense is already voided.');
            }
            if ($expense->invoice_id) {
                throw new RuleViolation('This expense is on invoice '.$expense->invoice?->reference.'. Remove it from the draft, or credit the issued invoice, first.');
            }
            $expense->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();
            Audit::record('expense.voided', "Expense {$expense->reference} voided: {$reason}", $expense, actor: $actor);
        });
    }
}
