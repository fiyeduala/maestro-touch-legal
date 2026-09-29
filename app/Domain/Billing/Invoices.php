<?php

namespace App\Domain\Billing;

use App\Domain\Clients\ClientContacts;
use App\Domain\Engagement\OfferStatus;
use App\Domain\Operations\Audit;
use App\Domain\Operations\Settings;
use App\Domain\RuleViolation;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Quotation;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Money;
use App\Support\References;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Invoices (spec §10). One currency each, integer minor units. Lines can change only while the
 * invoice is a draft; once issued, the only corrections are credit notes (and payment reversals).
 * Invoicing and payment never start representation (that is the engagement approval).
 */
class Invoices
{
    public const LINE_KINDS = ['fee' => 'Professional fee', 'expense' => 'Expense / disbursement', 'tax' => 'Tax'];

    public function __construct(private ClientContacts $contacts) {}

    /**
     * Lines hold major-unit decimal strings: {kind, description, quantity, unit}.
     *
     * @param  array{title: string, currency: string, matter_id?: ?int, quotation_id?: ?int, due_date?: ?string, notes?: ?string, lines?: list<array>, expense_ids?: list<int>}  $data
     */
    public function createDraft(Client $client, array $data, User $actor): Invoice
    {
        Gate::forUser($actor)->authorize('create', Invoice::class);
        $currency = Money::assertCurrency($data['currency']);
        $matter = $this->matterFor($client, $data['matter_id'] ?? null);
        $quotation = $this->quotationFor($client, $currency, $data['quotation_id'] ?? null);

        return DB::transaction(function () use ($client, $data, $currency, $matter, $quotation, $actor) {
            $invoice = Invoice::create([
                'reference' => References::next('invoices', 'INV'),
                'client_id' => $client->id,
                'matter_id' => $matter?->id,
                'quotation_id' => $quotation?->id,
                'currency' => $currency,
                'title' => $this->title($data['title'] ?? ''),
                'status' => InvoiceStatus::Draft,
                'due_date' => $data['due_date'] ?? null,
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'created_by' => $actor->id,
            ]);
            $this->writeLines($invoice, $data['lines'] ?? [], $data['expense_ids'] ?? []);
            Audit::record('invoice.drafted', "Invoice {$invoice->reference} drafted ({$currency} ".Money::toDecimal($invoice->total_minor).')', $invoice, actor: $actor);

            return $invoice;
        });
    }

    /**
     * A draft pre-filled from the accepted version of a quotation: either all its lines, or one of its
     * agreed payment stages as a single line. Staff review the draft before issuing it.
     */
    public function draftFromQuotation(Quotation $quotation, ?int $stage, User $actor): Invoice
    {
        $quotation->loadMissing(['acceptedVersion', 'client']);
        $version = $quotation->acceptedVersion;
        if ($quotation->status !== OfferStatus::Accepted || ! $version) {
            throw new RuleViolation('Only an accepted quotation can be turned into an invoice.');
        }

        if ($stage !== null) {
            $stages = $version->payment_stages ?? [];
            if (! isset($stages[$stage])) {
                throw new RuleViolation('That payment stage does not exist on the accepted quotation.');
            }
            $lines = [['kind' => 'fee', 'description' => "{$quotation->title}: ".($stages[$stage]['label'] ?: 'Stage '.($stage + 1)), 'quantity' => 1,
                'unit' => Money::input($stages[$stage]['amount_minor'])]];
        } else {
            $lines = array_map(fn (array $l) => [
                'kind' => $l['kind'], 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => Money::input($l['unit_minor']),
            ], $version->lines ?? []);
        }

        return $this->createDraft($quotation->client, [
            'title' => $quotation->title,
            'currency' => $quotation->currency,
            'matter_id' => $quotation->matter_id,
            'quotation_id' => $quotation->id,
            'lines' => $lines,
        ], $actor);
    }

    public function updateDraft(Invoice $invoice, array $data, User $actor): Invoice
    {
        Gate::forUser($actor)->authorize('update', $invoice);

        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new RuleViolation('Only a draft invoice can be edited. Use a credit note to correct an issued invoice.');
            }
            $invoice->fill([
                'title' => $this->title($data['title'] ?? $invoice->title),
                'matter_id' => $this->matterFor($invoice->client, $data['matter_id'] ?? $invoice->matter_id)?->id,
                'due_date' => $data['due_date'] ?? null,
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            ])->save();
            $this->writeLines($invoice, $data['lines'] ?? [], $data['expense_ids'] ?? []);
            Audit::record('invoice.draft_updated', "Invoice {$invoice->reference} draft edited", $invoice, actor: $actor);

            return $invoice;
        });
    }

    /** Freezes the invoice, fixes its dates and tells the client's portal contacts. */
    public function issue(Invoice $invoice, User $actor): void
    {
        Gate::forUser($actor)->authorize('manage', $invoice);

        DB::transaction(function () use ($invoice, $actor) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new RuleViolation('This invoice has already been issued or cancelled.');
            }
            if ($invoice->total_minor <= 0 || ! $invoice->lines()->exists()) {
                throw new RuleViolation('Add at least one priced line before issuing.');
            }
            $today = now(Settings::get('firm.timezone'))->toDateString();
            if ($invoice->due_date && $invoice->due_date->toDateString() < $today) {
                throw new RuleViolation('The due date is in the past.');
            }

            $invoice->forceFill([
                'status' => InvoiceStatus::Issued,
                'issue_date' => $today,
                'issued_at' => now(),
                'issued_by' => $actor->id,
            ]);
            $invoice->content_hash = self::hash($invoice);
            $invoice->save();

            Audit::record('invoice.issued', "Invoice {$invoice->reference} issued ({$invoice->currency} ".Money::toDecimal($invoice->total_minor).')', $invoice,
                ['after' => ['total_minor' => $invoice->total_minor, 'currency' => $invoice->currency, 'content_hash' => $invoice->content_hash]], actor: $actor);
        });

        $this->contacts->notify($invoice->client()->firstOrFail(), 'A new invoice from Maestro Touch Legal',
            'An invoice has been issued to you. You can view it and see the payment options in your portal.', '/portal/invoices/'.$invoice->id);
    }

    /** Discards a draft. Issued invoices are never cancelled or deleted; they are credited. */
    public function discardDraft(Invoice $invoice, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('manage', $invoice);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuleViolation('Record why the draft is discarded.');
        }

        DB::transaction(function () use ($invoice, $reason, $actor) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new RuleViolation('Only a draft can be discarded. Correct an issued invoice with a credit note.');
            }
            Expense::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            $invoice->forceFill(['status' => InvoiceStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $reason])->save();
            Audit::record('invoice.discarded', "Draft invoice {$invoice->reference} discarded: {$reason}", $invoice, actor: $actor);
        });
    }

    /** Reduces what the client owes, up to the unpaid balance. The invoice itself is not changed. */
    public function credit(Invoice $invoice, int $amountMinor, string $reason, User $actor): CreditNote
    {
        Gate::forUser($actor)->authorize('manage', $invoice);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuleViolation('Record the reason for the credit note.');
        }
        if ($amountMinor <= 0) {
            throw new RuleViolation('The credit amount must be more than zero.');
        }

        return DB::transaction(function () use ($invoice, $amountMinor, $reason, $actor) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if (! $invoice->status->isOpen()) {
                throw new RuleViolation('Only an issued invoice with an unpaid balance can be credited.');
            }
            if ($amountMinor > $invoice->balanceMinor()) {
                throw new RuleViolation('A credit note cannot exceed the unpaid balance of '.Money::format($invoice->balanceMinor(), $invoice->currency).'. To return money already paid, refund the payment.');
            }
            $note = CreditNote::create([
                'reference' => References::next('credit_notes', 'CRN'),
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'currency' => $invoice->currency,
                'amount_minor' => $amountMinor,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);
            $invoice->credited_minor += $amountMinor;
            self::refreshStatus($invoice);
            $invoice->save();

            Audit::record('invoice.credited', "Credit note {$note->reference} for ".Money::format($amountMinor, $invoice->currency)." on {$invoice->reference}: {$reason}", $invoice,
                ['after' => ['credit_note' => $note->reference, 'amount_minor' => $amountMinor]], actor: $actor);

            return $note;
        });
    }

    /** Recomputes paid_minor from the allocation rows and the status from the amounts. Call on a locked invoice. */
    public static function recalculate(Invoice $invoice): void
    {
        $invoice->paid_minor = (int) $invoice->allocations()->sum('amount_minor');
        self::refreshStatus($invoice);
        $invoice->save();
    }

    private static function refreshStatus(Invoice $invoice): void
    {
        if (! $invoice->status->isIssued()) {
            return;
        }
        $settled = $invoice->paid_minor + $invoice->credited_minor;
        $invoice->status = match (true) {
            $settled >= $invoice->total_minor && $invoice->paid_minor <= 0 => InvoiceStatus::Credited,
            $settled >= $invoice->total_minor => InvoiceStatus::Paid,
            $invoice->paid_minor > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Issued,
        };
    }

    public static function hash(Invoice $invoice): string
    {
        return CanonicalJson::hash([
            'reference' => $invoice->reference,
            'client_id' => $invoice->client_id,
            'currency' => $invoice->currency,
            'issue_date' => $invoice->issue_date?->toDateString() ?? (string) $invoice->issue_date,
            'due_date' => $invoice->due_date?->toDateString(),
            'lines' => $invoice->lines()->get(['kind', 'description', 'quantity', 'unit_minor', 'amount_minor'])->toArray(),
            'total_minor' => $invoice->total_minor,
        ]);
    }

    /** @return array<int, string> unbilled expenses that may go on an invoice for this client and currency */
    public static function billableExpenseOptions(int $clientId, string $currency, ?int $invoiceId = null): array
    {
        return Expense::query()->where('client_id', $clientId)->where('currency', $currency)
            ->where('billable', true)->whereNull('voided_at')
            ->where(fn ($q) => $q->whereNull('invoice_id')->when($invoiceId, fn ($q) => $q->orWhere('invoice_id', $invoiceId)))
            ->orderBy('incurred_on')->get()
            ->mapWithKeys(fn (Expense $e) => [$e->id => "{$e->reference} · {$e->description} · ".Money::format($e->amount_minor, $e->currency)])->all();
    }

    /** Converts a draft back into form input. */
    public static function toForm(Invoice $invoice): array
    {
        return [
            'title' => $invoice->title,
            'matter_id' => $invoice->matter_id,
            'due_date' => $invoice->due_date?->toDateString(),
            'notes' => $invoice->notes,
            'lines' => $invoice->lines()->whereNull('expense_id')->get()->map(fn ($l) => [
                'kind' => $l->kind, 'description' => $l->description, 'quantity' => $l->quantity, 'unit' => Money::input($l->unit_minor),
            ])->all(),
            'expense_ids' => $invoice->lines()->whereNotNull('expense_id')->pluck('expense_id')->map(fn ($id) => (string) $id)->all(),
        ];
    }

    private function writeLines(Invoice $invoice, array $lines, array $expenseIds): void
    {
        $invoice->lines()->delete();
        Expense::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);

        $rows = [];
        foreach ($lines as $line) {
            $quantity = (int) ($line['quantity'] ?? 1);
            if ($quantity < 1 || $quantity > 10000) {
                throw new RuleViolation('Each line needs a quantity between 1 and 10,000.');
            }
            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') {
                throw new RuleViolation('Each line needs a description.');
            }
            $unit = Money::parse((string) ($line['unit'] ?? ''));
            $kind = array_key_exists($line['kind'] ?? '', self::LINE_KINDS) ? $line['kind'] : 'fee';
            $rows[] = ['kind' => $kind, 'description' => mb_substr($description, 0, 300), 'quantity' => $quantity, 'unit_minor' => $unit, 'amount_minor' => $unit * $quantity, 'expense_id' => null];
        }

        $expenseIds = array_values(array_unique(array_map('intval', $expenseIds)));
        if ($expenseIds !== []) {
            $expenses = Expense::whereIn('id', $expenseIds)->lockForUpdate()->get();
            foreach ($expenses as $expense) {
                if ($expense->client_id !== $invoice->client_id || $expense->currency !== $invoice->currency
                    || ! $expense->billable || $expense->voided_at || ($expense->invoice_id && $expense->invoice_id !== $invoice->id)) {
                    throw new RuleViolation("Expense {$expense->reference} cannot go on this invoice (different client or currency, not billable, voided or already billed).");
                }
                $rows[] = ['kind' => 'expense', 'description' => mb_substr($expense->description, 0, 300), 'quantity' => 1, 'unit_minor' => $expense->amount_minor, 'amount_minor' => $expense->amount_minor, 'expense_id' => $expense->id];
            }
            if ($expenses->count() !== count($expenseIds)) {
                throw new RuleViolation('One of the selected expenses no longer exists.');
            }
            Expense::whereIn('id', $expenseIds)->update(['invoice_id' => $invoice->id]);
        }

        foreach ($rows as $i => $row) {
            $invoice->lines()->create($row + ['sort' => $i]);
        }
        $sum = fn (string $kind) => array_sum(array_column(array_filter($rows, fn ($r) => $r['kind'] === $kind), 'amount_minor'));
        $invoice->forceFill([
            'fees_minor' => $sum('fee'),
            'expenses_minor' => $sum('expense'),
            'tax_minor' => $sum('tax'),
            'total_minor' => array_sum(array_column($rows, 'amount_minor')),
        ])->save();
    }

    private function matterFor(Client $client, mixed $matterId): ?Matter
    {
        if (! $matterId) {
            return null;
        }
        $matter = Matter::find($matterId);
        if (! $matter || $matter->client_id !== $client->id) {
            throw new RuleViolation('The matter does not belong to this client.');
        }

        return $matter;
    }

    private function quotationFor(Client $client, string $currency, mixed $quotationId): ?Quotation
    {
        if (! $quotationId) {
            return null;
        }
        $quotation = Quotation::find($quotationId);
        if (! $quotation || $quotation->client_id !== $client->id || $quotation->currency !== $currency) {
            throw new RuleViolation('The quotation must belong to this client and use the same currency.');
        }

        return $quotation;
    }

    private function title(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new RuleViolation('Give the invoice a title.');
        }

        return mb_substr($title, 0, 255);
    }

    public static function dueDateDefault(): string
    {
        return Carbon::now(Settings::get('firm.timezone'))->addDays(14)->toDateString();
    }
}
