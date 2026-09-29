<?php

namespace App\Domain\Billing;

use App\Domain\Clients\ClientContacts;
use App\Domain\Documents\Documents;
use App\Domain\Documents\UploadGuard;
use App\Domain\Operations\Audit;
use App\Domain\Operations\BankInstructions;
use App\Domain\RuleViolation;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Money;
use App\Support\References;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Payments in one currency, allocated to invoices of that currency (spec §10). Money is only treated as
 * received when a provider verification or a finance officer confirms it; an uploaded transfer
 * screenshot is a claim, never proof. Corrections are new allocation rows, never edits.
 */
class Payments
{
    public function __construct(private ClientContacts $contacts) {}

    /** A client says they paid by bank transfer. Nothing is credited until finance verifies it. */
    public function submitTransfer(Invoice $invoice, UploadedFile $evidence, int $amountMinor, string $paidOn, ?string $bankReference, ?string $note, User $client): Payment
    {
        Gate::forUser($client)->authorize('payAsClient', $invoice);
        if (! BankInstructions::available($invoice->currency)) {
            throw new RuleViolation("Bank transfer is not available for {$invoice->currency} invoices yet. Please contact the firm.");
        }
        if ($amountMinor <= 0) {
            throw new RuleViolation('Enter the amount you transferred.');
        }
        if ($paidOn > now()->toDateString()) {
            throw new RuleViolation('The transfer date cannot be in the future.');
        }
        $checked = UploadGuard::check($evidence);
        $path = Storage::disk(Documents::DISK)->putFileAs('payments/'.now()->format('Y/m'), $evidence, Str::uuid()->toString().'.'.$checked['extension']);
        if (! $path) {
            throw new RuleViolation('The file could not be stored. Please try again.');
        }
        $bankReference = filled($bankReference) ? mb_substr(trim($bankReference), 0, 100) : null;

        try {
            $payment = DB::transaction(function () use ($invoice, $checked, $path, $amountMinor, $paidOn, $bankReference, $note, $client) {
                $flags = [];
                $sameFile = Payment::where('evidence_sha256', $checked['sha256'])->value('reference');
                if ($sameFile) {
                    $flags[] = "same evidence file as {$sameFile}";
                }
                if ($bankReference && ($sameRef = Payment::where('bank_reference', $bankReference)->whereNotIn('status', [PaymentStatus::Rejected->value])->value('reference'))) {
                    $flags[] = "same bank reference as {$sameRef}";
                }

                $payment = Payment::create([
                    'reference' => References::next('payments', 'PAY'),
                    'client_id' => $invoice->client_id,
                    'invoice_id' => $invoice->id,
                    'currency' => $invoice->currency,
                    'amount_minor' => $amountMinor,
                    'method' => 'bank_transfer',
                    'status' => PaymentStatus::PendingVerification,
                    'bank_reference' => $bankReference,
                    'received_on' => $paidOn,
                    'review_reason' => $flags ? 'Possible duplicate: '.implode('; ', $flags) : null,
                    'evidence_path' => $path,
                    'evidence_name' => $checked['original_name'],
                    'evidence_mime' => $checked['mime'],
                    'evidence_size' => $checked['size'],
                    'evidence_sha256' => $checked['sha256'],
                    'client_note' => filled($note) ? trim($note) : null,
                    'submitted_by' => $client->id,
                ]);
                Audit::record('payment.transfer_submitted', "Client reported a transfer of ".Money::format($amountMinor, $invoice->currency)." for {$invoice->reference} ({$payment->reference})", $payment,
                    context: ['sha256' => $checked['sha256'], 'flags' => $flags ?: null], actor: $client);

                return $payment;
            });
        } catch (\Throwable $e) {
            Storage::disk(Documents::DISK)->delete($path);
            throw $e;
        }

        FinanceAlerts::send("Bank transfer to verify: {$invoice->reference}",
            "A client reported a bank transfer of ".Money::format($amountMinor, $invoice->currency).'. Check the bank account before verifying it.'
            .($payment->review_reason ? ' '.$payment->review_reason.'.' : ''), '/admin/payments/'.$payment->id);

        return $payment;
    }

    /** Finance confirms the money is in the firm's account, with the amount actually received. */
    public function verifyTransfer(Payment $payment, int $receivedMinor, string $receivedOn, string $bankReference, ?string $note, User $actor): void
    {
        Gate::forUser($actor)->authorize('verify', $payment);
        $bankReference = trim($bankReference);
        if ($bankReference === '') {
            throw new RuleViolation('Enter the bank reference you found on the statement.');
        }
        if ($receivedMinor <= 0) {
            throw new RuleViolation('Enter the amount received.');
        }

        DB::transaction(function () use ($payment, $receivedMinor, $receivedOn, $bankReference, $note, $actor) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::PendingVerification) {
                throw new RuleViolation('This transfer has already been dealt with.');
            }
            $this->assertBankReferenceUnused($bankReference, $payment->id);
            $payment->forceFill([
                'bank_reference' => mb_substr($bankReference, 0, 100),
                'received_on' => $receivedOn,
                'verified_at' => now(),
                'verified_by' => $actor->id,
                'staff_note' => filled($note) ? trim($note) : null,
            ]);
            $this->settle($payment, $receivedMinor, $payment->currency, $actor);
            Audit::record('payment.transfer_verified', "Transfer {$payment->reference} verified: ".Money::format($receivedMinor, $payment->currency)." received", $payment,
                ['after' => ['received_minor' => $receivedMinor, 'bank_reference' => $payment->bank_reference]], actor: $actor);
        });

        $this->notifyReceived($payment->refresh());
    }

    public function rejectTransfer(Payment $payment, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('verify', $payment);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuleViolation('Explain why the transfer could not be verified; the client will see this.');
        }

        DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::PendingVerification) {
                throw new RuleViolation('This transfer has already been dealt with.');
            }
            $payment->forceFill(['status' => PaymentStatus::Rejected, 'rejection_reason' => $reason, 'verified_at' => now(), 'verified_by' => $actor->id])->save();
            Audit::record('payment.transfer_rejected', "Transfer {$payment->reference} not verified: {$reason}", $payment, actor: $actor);
        });

        $this->contacts->notify($payment->client()->firstOrFail(), 'We could not verify your bank transfer',
            'We could not match your reported bank transfer to our account. Please see the reason in your portal or contact us.', '/portal/invoices/'.$payment->invoice_id);
    }

    /**
     * Money received outside the portal (cash, a transfer seen on the statement, a cheque). Recorded by
     * finance as already received. Without an invoice it is held as client credit in that currency.
     *
     * @param  array{currency: string, amount_minor: int, method: string, received_on: string, bank_reference?: ?string, note?: ?string}  $data
     */
    public function recordReceived(Client $client, ?Invoice $invoice, array $data, User $actor): Payment
    {
        Gate::forUser($actor)->authorize('create', Payment::class);
        $currency = Money::assertCurrency($data['currency']);
        if ($invoice && ($invoice->client_id !== $client->id || $invoice->currency !== $currency)) {
            throw new RuleViolation('The invoice must belong to this client and be in the same currency.');
        }
        if ($invoice && ! $invoice->status->isIssued()) {
            throw new RuleViolation('Issue the invoice before recording a payment against it.');
        }
        if (! in_array($data['method'], ['bank_transfer', 'cash', 'other'], true)) {
            throw new RuleViolation('Online payments are recorded by Paystack, not by hand.');
        }
        if ((int) $data['amount_minor'] <= 0) {
            throw new RuleViolation('Enter the amount received.');
        }
        $bankReference = filled($data['bank_reference'] ?? null) ? mb_substr(trim($data['bank_reference']), 0, 100) : null;

        $payment = DB::transaction(function () use ($client, $invoice, $data, $currency, $bankReference, $actor) {
            if ($bankReference) {
                $this->assertBankReferenceUnused($bankReference, null);
            }
            $payment = new Payment([
                'reference' => References::next('payments', 'PAY'),
                'client_id' => $client->id,
                'invoice_id' => $invoice?->id,
                'currency' => $currency,
                'amount_minor' => (int) $data['amount_minor'],
                'method' => $data['method'],
                'bank_reference' => $bankReference,
                'received_on' => $data['received_on'],
                'staff_note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'recorded_by' => $actor->id,
                'verified_at' => now(),
                'verified_by' => $actor->id,
            ]);
            $payment->status = PaymentStatus::Pending;
            $payment->save();
            $this->settle($payment, (int) $data['amount_minor'], $currency, $actor);
            Audit::record('payment.recorded', 'Payment '.Money::format($payment->received_minor, $currency)." ({$payment->methodLabel()}) recorded as {$payment->reference}", $payment, actor: $actor);

            return $payment;
        });

        $this->notifyReceived($payment->refresh());

        return $payment;
    }

    /**
     * Marks money as received and allocates it to the payment's invoice up to the unpaid balance.
     * Anything over the balance stays unapplied (client credit) and is flagged for a person to look at.
     * Call inside a transaction with the payment locked.
     */
    public function settle(Payment $payment, int $receivedMinor, string $receivedCurrency, ?User $actor): void
    {
        if ($receivedCurrency !== $payment->currency) {
            throw new RuleViolation('A payment in a different currency is never converted; it must be reviewed.');
        }
        $payment->forceFill([
            'status' => PaymentStatus::Succeeded,
            'received_minor' => $receivedMinor,
            'received_currency' => $receivedCurrency,
            'unapplied_minor' => $receivedMinor,
            'paid_at' => $payment->paid_at ?? now(),
        ])->save();

        if ($payment->invoice_id) {
            $invoice = Invoice::lockForUpdate()->find($payment->invoice_id);
            $applied = $invoice ? $this->allocate($payment, $invoice, $receivedMinor, 'payment', $actor) : 0;
            if ($receivedMinor > $applied) {
                $extra = Money::format($receivedMinor - $applied, $payment->currency);
                $payment->review_reason = trim(($payment->review_reason ? $payment->review_reason.'; ' : '')."{$extra} more than the invoice balance; held as client credit");
                $payment->save();
            }
        }
    }

    /** Uses unapplied money from a payment (client credit) against another invoice in the same currency. */
    public function applyCredit(Payment $payment, Invoice $invoice, int $amountMinor, User $actor): void
    {
        Gate::forUser($actor)->authorize('verify', $payment);

        DB::transaction(function () use ($payment, $invoice, $amountMinor, $actor) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->client_id !== $payment->client_id || $invoice->currency !== $payment->currency) {
                throw new RuleViolation('Credit can only be used on the same client\'s invoices in the same currency.');
            }
            if (! $invoice->status->isOpen()) {
                throw new RuleViolation('The invoice has no unpaid balance.');
            }
            if ($amountMinor <= 0 || $amountMinor > $payment->availableCreditMinor() || $amountMinor > $invoice->balanceMinor()) {
                throw new RuleViolation('The amount must be more than zero and no more than both the available credit ('
                    .Money::format($payment->availableCreditMinor(), $payment->currency).') and the invoice balance ('.Money::format($invoice->balanceMinor(), $invoice->currency).').');
            }
            $this->allocate($payment, $invoice, $amountMinor, 'credit', $actor);
            Audit::record('payment.credit_applied', Money::format($amountMinor, $invoice->currency)." of credit from {$payment->reference} applied to {$invoice->reference}", $payment, actor: $actor);
        });
    }

    /**
     * Undoes a payment that should never have counted (a bounced cheque, a transfer recorded in error,
     * a chargeback). Allocations are reversed with negative rows; nothing is deleted.
     */
    public function reverse(Payment $payment, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('verify', $payment);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuleViolation('Record why the payment is reversed.');
        }

        DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::Succeeded) {
                throw new RuleViolation('Only a received payment can be reversed.');
            }
            if ($payment->refunded_minor > 0 || $payment->refund_pending_minor > 0) {
                throw new RuleViolation('This payment has a refund; it cannot also be reversed.');
            }
            $this->unallocateAll($payment, 'reversal', "Reversed: {$reason}", $actor);
            $payment->forceFill(['status' => PaymentStatus::Reversed, 'unapplied_minor' => 0, 'staff_note' => trim(($payment->staff_note ? $payment->staff_note."\n" : '')."Reversed: {$reason}")])->save();
            Audit::record('payment.reversed', "Payment {$payment->reference} reversed: {$reason}", $payment, actor: $actor);
        });
    }

    /**
     * A person has looked at a payment that did not match (amount, currency, duplicate, dispute).
     * "accept" treats the received amount as received (same currency only); "reject" leaves it uncounted
     * (for example because it will be refunded outside the system).
     */
    public function resolveReview(Payment $payment, string $decision, string $note, User $actor): void
    {
        Gate::forUser($actor)->authorize('verify', $payment);
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new RuleViolation('Record what you checked and decided.');
        }

        $received = DB::transaction(function () use ($payment, $decision, $note, $actor) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $received = false;
            if ($payment->status === PaymentStatus::NeedsReview) {
                if ($decision === 'accept') {
                    if ($payment->received_currency !== $payment->currency || ! $payment->received_minor) {
                        throw new RuleViolation('Money in a different currency is never converted. Refund it and ask the client to pay in the invoice currency.');
                    }
                    $this->settle($payment, $payment->received_minor, $payment->received_currency, $actor);
                    $received = true;
                } else {
                    $payment->forceFill(['status' => PaymentStatus::Rejected, 'rejection_reason' => $note])->save();
                }
            } elseif ($decision !== 'accept') {
                throw new RuleViolation('Only a payment that needs review can be set aside here. Use reverse or refund for received money.');
            }
            $payment->forceFill(['reviewed_at' => now(), 'reviewed_by' => $actor->id, 'review_reason' => null,
                'staff_note' => trim(($payment->staff_note ? $payment->staff_note."\n" : '')."Reviewed: {$note}")])->save();
            Audit::record('payment.reviewed', "Payment {$payment->reference} reviewed ({$decision}): {$note}", $payment, actor: $actor);

            return $received;
        });

        if ($received) {
            $this->notifyReceived($payment->refresh());
        }
    }

    /**
     * A refund made outside Paystack (for example a bank transfer back to the client), recorded with its
     * bank reference as evidence. Paystack refunds go through PaystackPayments and wait for confirmation.
     */
    public function recordManualRefund(Payment $payment, int $amountMinor, string $refundedOn, string $bankReference, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('verify', $payment);
        if (trim($bankReference) === '' || mb_strlen(trim($reason)) < 5) {
            throw new RuleViolation('Record the bank reference of the refund and the reason.');
        }

        DB::transaction(function () use ($payment, $amountMinor, $refundedOn, $bankReference, $reason, $actor) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->method === 'paystack') {
                throw new RuleViolation('Refund online payments through Paystack so the refund is confirmed by the provider.');
            }
            $this->assertRefundable($payment, $amountMinor);
            $this->applyRefund($payment, $amountMinor, "Refunded {$refundedOn} (bank ref ".trim($bankReference).'): '.trim($reason), $actor);
            Audit::record('payment.refunded', Money::format($amountMinor, $payment->currency)." refunded from {$payment->reference} (bank reference ".trim($bankReference).')', $payment, actor: $actor);
        });
    }

    public function assertRefundable(Payment $payment, int $amountMinor): void
    {
        if ($payment->status !== PaymentStatus::Succeeded) {
            throw new RuleViolation('Only a received payment can be refunded.');
        }
        $refundable = $payment->received_minor - $payment->refunded_minor - $payment->refund_pending_minor;
        if ($amountMinor <= 0 || $amountMinor > $refundable) {
            throw new RuleViolation('The refund must be more than zero and no more than '.Money::format(max(0, $refundable), $payment->currency).'.');
        }
    }

    /**
     * A confirmed refund: takes the money first from unapplied credit, then back off the invoice
     * (a negative allocation, so the invoice shows as unpaid again). Call inside a transaction.
     */
    public function applyRefund(Payment $payment, int $amountMinor, string $note, ?User $actor): void
    {
        $fromCredit = min($payment->unapplied_minor, $amountMinor);
        $payment->unapplied_minor -= $fromCredit;
        $remaining = $amountMinor - $fromCredit;

        if ($remaining > 0) {
            foreach ($this->netAllocations($payment) as $invoiceId => $net) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min($net, $remaining);
                $invoice = Invoice::lockForUpdate()->findOrFail($invoiceId);
                PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $invoiceId, 'amount_minor' => -$take, 'kind' => 'refund', 'note' => mb_substr($note, 0, 255), 'created_by' => $actor?->id]);
                Invoices::recalculate($invoice);
                $remaining -= $take;
            }
        }
        $payment->refunded_minor += $amountMinor;
        $payment->save();
    }

    private function allocate(Payment $payment, Invoice $invoice, int $amountMinor, string $kind, ?User $actor): int
    {
        if (! $invoice->status->isOpen() || $invoice->currency !== $payment->currency) {
            return 0;
        }
        $amount = min($amountMinor, $invoice->balanceMinor(), $payment->unapplied_minor);
        if ($amount <= 0) {
            return 0;
        }
        PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_minor' => $amount, 'kind' => $kind, 'created_by' => $actor?->id]);
        $payment->unapplied_minor -= $amount;
        $payment->save();
        Invoices::recalculate($invoice);

        return $amount;
    }

    private function unallocateAll(Payment $payment, string $kind, string $note, ?User $actor): void
    {
        foreach ($this->netAllocations($payment) as $invoiceId => $net) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoiceId);
            PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $invoiceId, 'amount_minor' => -$net, 'kind' => $kind, 'note' => mb_substr($note, 0, 255), 'created_by' => $actor?->id]);
            Invoices::recalculate($invoice);
        }
    }

    /** @return array<int, int> invoice id => net amount still allocated from this payment */
    private function netAllocations(Payment $payment): array
    {
        return PaymentAllocation::where('payment_id', $payment->id)
            ->selectRaw('invoice_id, SUM(amount_minor) as net')->groupBy('invoice_id')->orderBy('invoice_id')
            ->pluck('net', 'invoice_id')->map(fn ($n) => (int) $n)->filter(fn (int $n) => $n > 0)->all();
    }

    private function assertBankReferenceUnused(string $bankReference, ?int $exceptId): void
    {
        $existing = Payment::where('bank_reference', $bankReference)->where('status', PaymentStatus::Succeeded->value)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->value('reference');
        if ($existing) {
            throw new RuleViolation("Bank reference {$bankReference} is already recorded on received payment {$existing}. Check for a duplicate.");
        }
    }

    private function notifyReceived(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Succeeded) {
            return;
        }
        $this->contacts->notify($payment->client()->firstOrFail(), 'Payment received – thank you',
            'We have received your payment of '.Money::format((int) $payment->received_minor, $payment->currency).'. Your receipt is in your portal.', '/portal/payments/'.$payment->id);
    }
}
