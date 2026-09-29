<?php

namespace App\Domain\Billing;

use App\Domain\Clients\ClientContacts;
use App\Domain\Documents\Documents;
use App\Domain\Documents\UploadGuard;
use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Models\Client;
use App\Models\ClientFundEntry;
use App\Models\ClientFundReconciliation;
use App\Models\Matter;
use App\Models\User;
use App\Support\Money;
use App\Support\References;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Money held on behalf of clients, such as recovered funds (spec §10). Kept apart from firm revenue:
 * - every entry is in one currency, and balances are per client per currency;
 * - money going out (fee deductions, remittances) needs a recorded authorisation and can never take
 *   the balance below zero;
 * - nothing is deducted or paid out automatically; a person records each movement after it happens;
 * - entries are never edited or deleted; a mistake is corrected with a reversal entry.
 */
class ClientFunds
{
    public const OUTFLOWS = ['fee_deduction', 'remittance'];

    public function __construct(private ClientContacts $contacts) {}

    /**
     * @param  array{currency: string, type: string, amount_minor: int, occurred_on: string, description: string, counterparty?: ?string, bank_reference?: ?string, authorisation?: ?string}  $data
     */
    public function record(Client $client, ?Matter $matter, array $data, ?UploadedFile $evidence, User $actor): ClientFundEntry
    {
        Gate::forUser($actor)->authorize('create', ClientFundEntry::class);
        if ($matter && $matter->client_id !== $client->id) {
            throw new RuleViolation('The matter does not belong to this client.');
        }
        $currency = Money::assertCurrency($data['currency']);
        $type = $data['type'];
        if (! in_array($type, ['receipt', ...self::OUTFLOWS], true)) {
            throw new RuleViolation('Choose money received, a fee deduction or a remittance.');
        }
        $amount = (int) $data['amount_minor'];
        if ($amount <= 0) {
            throw new RuleViolation('Enter an amount greater than zero.');
        }
        $description = trim((string) $data['description']);
        if ($description === '') {
            throw new RuleViolation('Describe the movement.');
        }
        if ($data['occurred_on'] > now()->toDateString()) {
            throw new RuleViolation('The date cannot be in the future.');
        }
        $authorisation = trim((string) ($data['authorisation'] ?? ''));
        if (in_array($type, self::OUTFLOWS, true) && mb_strlen($authorisation) < 10) {
            throw new RuleViolation('Money going out needs a recorded authorisation: who approved it, when and how (for example "Client email of 2 Oct 2026 approving the 10% fee").');
        }

        $stored = $evidence ? $this->storeEvidence($evidence) : null;

        try {
            $entry = DB::transaction(function () use ($client, $matter, $data, $currency, $type, $amount, $description, $authorisation, $stored, $actor) {
                Client::lockForUpdate()->findOrFail($client->id); // serialises movements for this client
                $signed = in_array($type, self::OUTFLOWS, true) ? -$amount : $amount;
                $balance = $this->balance($client->id, $currency);
                if ($balance + $signed < 0) {
                    throw new RuleViolation('This would take the client\'s '.$currency.' balance below zero (held: '.Money::format($balance, $currency).').');
                }

                $entry = ClientFundEntry::create([
                    'reference' => References::next('client_fund_entries', 'CFE'),
                    'client_id' => $client->id,
                    'matter_id' => $matter?->id,
                    'currency' => $currency,
                    'type' => $type,
                    'amount_minor' => $signed,
                    'occurred_on' => $data['occurred_on'],
                    'description' => mb_substr($description, 0, 500),
                    'counterparty' => filled($data['counterparty'] ?? null) ? mb_substr(trim($data['counterparty']), 0, 255) : null,
                    'bank_reference' => filled($data['bank_reference'] ?? null) ? mb_substr(trim($data['bank_reference']), 0, 100) : null,
                    'authorisation' => $authorisation !== '' ? $authorisation : null,
                    'created_by' => $actor->id,
                ] + ($stored ?? []));
                Audit::record('client_funds.'.$type, "Client funds {$entry->reference}: {$entry->typeLabel()} ".Money::format($amount, $currency)." for {$client->reference}", $entry,
                    ['after' => ['amount_minor' => $signed, 'balance_after_minor' => $balance + $signed]], actor: $actor);

                return $entry;
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk(Documents::DISK)->delete($stored['evidence_path']);
            }
            throw $e;
        }

        $this->contacts->notify($client, 'Your client-funds statement has been updated',
            'A new entry has been recorded on the funds we hold for you. You can see your statement in your portal.', '/portal/funds');

        return $entry;
    }

    public function reverse(ClientFundEntry $entry, string $reason, User $actor): ClientFundEntry
    {
        Gate::forUser($actor)->authorize('reverse', $entry);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuleViolation('Record why the entry is reversed.');
        }

        return DB::transaction(function () use ($entry, $reason, $actor) {
            Client::lockForUpdate()->findOrFail($entry->client_id);
            $entry = ClientFundEntry::lockForUpdate()->findOrFail($entry->id);
            if ($entry->type === 'reversal' || $entry->reversedBy()->exists()) {
                throw new RuleViolation('This entry is a reversal or has already been reversed.');
            }
            $balance = $this->balance($entry->client_id, $entry->currency);
            if ($balance - $entry->amount_minor < 0) {
                throw new RuleViolation('Reversing this entry would take the balance below zero; money has already gone out against it.');
            }
            $reversal = ClientFundEntry::create([
                'reference' => References::next('client_fund_entries', 'CFE'),
                'client_id' => $entry->client_id,
                'matter_id' => $entry->matter_id,
                'currency' => $entry->currency,
                'type' => 'reversal',
                'amount_minor' => -$entry->amount_minor,
                'occurred_on' => now()->toDateString(),
                'description' => mb_substr("Reversal of {$entry->reference}: {$reason}", 0, 500),
                'reverses_entry_id' => $entry->id,
                'created_by' => $actor->id,
            ]);
            Audit::record('client_funds.reversed', "Client funds {$entry->reference} reversed by {$reversal->reference}: {$reason}", $entry, actor: $actor);

            return $reversal;
        });
    }

    /** Balance for one client in one currency, optionally as at the end of a date. */
    public function balance(int $clientId, string $currency, ?string $asAt = null): int
    {
        return (int) ClientFundEntry::where('client_id', $clientId)->where('currency', $currency)
            ->when($asAt, fn ($q) => $q->where('occurred_on', '<=', $asAt))->sum('amount_minor');
    }

    /** Entries in date order with the running balance after each. */
    public function statement(int $clientId, string $currency): Collection
    {
        $running = 0;

        return ClientFundEntry::where('client_id', $clientId)->where('currency', $currency)
            ->orderBy('occurred_on')->orderBy('id')->get()
            ->map(function (ClientFundEntry $e) use (&$running) {
                $running += $e->amount_minor;
                $e->setAttribute('running_balance_minor', $running);

                return $e;
            });
    }

    /** @return list<string> currencies this client has any client-funds entries in */
    public function currenciesFor(int $clientId): array
    {
        return ClientFundEntry::where('client_id', $clientId)->distinct()->orderBy('currency')->pluck('currency')->all();
    }

    /** Records a check of the client-funds bank account against the ledger (all clients) for one currency. */
    public function reconcile(string $currency, string $statementDate, int $statementBalanceMinor, ?string $note, User $actor): ClientFundReconciliation
    {
        Gate::forUser($actor)->authorize('create', ClientFundEntry::class);
        $currency = Money::assertCurrency($currency);
        $ledger = (int) ClientFundEntry::where('currency', $currency)->where('occurred_on', '<=', $statementDate)->sum('amount_minor');

        $record = ClientFundReconciliation::create([
            'currency' => $currency,
            'statement_date' => $statementDate,
            'statement_balance_minor' => $statementBalanceMinor,
            'ledger_balance_minor' => $ledger,
            'note' => filled($note) ? trim($note) : null,
            'created_by' => $actor->id,
        ]);
        $difference = $record->differenceMinor();
        Audit::record('client_funds.reconciled', "Client funds {$currency} reconciled at {$statementDate}: ".($difference === 0 ? 'balances agree' : 'difference '.Money::format($difference, $currency)), $record, actor: $actor);

        return $record;
    }

    /** @return array<string, mixed> evidence columns */
    private function storeEvidence(UploadedFile $file): array
    {
        $checked = UploadGuard::check($file);
        $path = Storage::disk(Documents::DISK)->putFileAs('client-funds/'.now()->format('Y/m'), $file, Str::uuid()->toString().'.'.$checked['extension']);
        if (! $path) {
            throw new RuleViolation('The file could not be stored. Please try again.');
        }

        return ['evidence_path' => $path, 'evidence_name' => $checked['original_name'], 'evidence_mime' => $checked['mime'], 'evidence_size' => $checked['size'], 'evidence_sha256' => $checked['sha256']];
    }
}
