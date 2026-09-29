<?php

namespace App\Domain\Billing;

use App\Domain\Clients\ClientContacts;
use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Support\Money;
use App\Support\References;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Paystack checkout, verification, webhooks, reconciliation and refunds (spec §10, ARCHITECTURE §6).
 *
 * - A payment counts only after the server verifies it with Paystack (GET /transaction/verify); the
 *   browser redirect and the webhook body are triggers, never proof on their own.
 * - Every webhook is signature-checked and recorded once (event_key); repeated deliveries do nothing.
 * - The amount and currency must equal what was initialised. Anything else waits for a person;
 *   nothing is ever converted between currencies.
 * - A refund is "processed" only when Paystack reports it so.
 * - References from other systems on the same Paystack account are ignored, not guessed at.
 */
class PaystackPayments
{
    /** A checkout still pending after this long is checked, then marked abandoned. */
    public const ABANDON_AFTER_HOURS = 48;

    public function __construct(private PaystackGateway $gateway, private Payments $payments, private ClientContacts $contacts) {}

    /** Starts an online payment of the invoice's unpaid balance and returns Paystack's checkout URL. */
    public function startCheckout(Invoice $invoice, User $client, string $callbackUrl): string
    {
        Gate::forUser($client)->authorize('payAsClient', $invoice);
        if (! $this->gateway->accepts($invoice->currency)) {
            throw new RuleViolation("Online payment is not available for {$invoice->currency} invoices.");
        }

        $payment = DB::transaction(function () use ($invoice, $client) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $balance = $invoice->balanceMinor();
            if (! $invoice->status->isOpen() || $balance <= 0) {
                throw new RuleViolation('This invoice has nothing left to pay.');
            }

            return Payment::create([
                'reference' => References::next('payments', 'PAY'),
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'currency' => $invoice->currency,
                'amount_minor' => $balance,
                'method' => 'paystack',
                'status' => PaymentStatus::Pending,
                'provider_reference' => 'MTL-'.strtoupper((string) Str::ulid()),
                'submitted_by' => $client->id,
            ]);
        });

        try {
            $data = $this->gateway->initialize($client->email, $payment->amount_minor, $payment->currency, $payment->provider_reference, $callbackUrl, [
                'payment_reference' => $payment->reference,
                'invoice_reference' => $invoice->reference,
                'client_reference' => $invoice->client->reference,
            ]);
        } catch (RuleViolation $e) {
            $payment->forceFill(['status' => PaymentStatus::Failed, 'gateway_response' => 'Could not start checkout'])->save();
            throw $e;
        }

        Audit::record('payment.checkout_started', "Online payment {$payment->reference} started for {$invoice->reference} (".Money::format($payment->amount_minor, $payment->currency).')', $payment, actor: $client);

        return $data['authorization_url'];
    }

    /** Asks Paystack for the transaction's real state and applies it. Safe to call repeatedly. */
    public function confirm(Payment $payment, string $source): string
    {
        if ($payment->method !== 'paystack' || ! $payment->provider_reference) {
            return 'not_paystack';
        }
        if ($payment->status !== PaymentStatus::Pending) {
            return 'already_processed';
        }

        $data = $this->gateway->verify($payment->provider_reference);
        $outcome = $this->apply($payment, $data, $source);
        PaymentEvent::create([
            'provider' => 'paystack', 'source' => $source, 'event' => 'transaction.verify', 'reference' => $payment->provider_reference,
            'payload' => $this->minimal($data), 'outcome' => $outcome, 'payment_id' => $payment->id,
        ]);

        return $outcome;
    }

    /**
     * @return string outcome recorded for the event: 'invalid_signature', 'duplicate', 'ignored', 'unknown_reference', …
     */
    public function handleWebhook(string $rawBody, ?string $signature): string
    {
        if (! $this->gateway->validSignature($rawBody, $signature)) {
            Log::warning('Paystack webhook with an invalid or missing signature was refused.');

            return 'invalid_signature';
        }

        $payload = json_decode($rawBody, true);
        $event = is_array($payload) ? (string) ($payload['event'] ?? '') : '';
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = $this->referenceFrom($event, $data);
        $key = hash('sha256', implode('|', [$event, $data['id'] ?? '', $data['status'] ?? '', $reference ?? '', $data['amount'] ?? '']));

        try {
            $record = PaymentEvent::create([
                'provider' => 'paystack', 'source' => 'webhook', 'event' => mb_substr($event ?: 'unknown', 0, 60), 'reference' => $reference ? mb_substr($reference, 0, 100) : null,
                'event_key' => $key, 'signature_valid' => true, 'payload' => $this->minimal($data), 'outcome' => 'processing',
            ]);
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }

        try {
            $payment = $reference ? Payment::where('provider_reference', $reference)->first() : null;
            $outcome = match (true) {
                ! $payment => 'unknown_reference',
                $event === 'charge.success' => $this->confirm($payment, 'webhook'),
                str_starts_with($event, 'refund.') => $this->refundEvent($payment, $event, $data),
                str_starts_with($event, 'charge.dispute.') => $this->disputeEvent($payment, $event, $data),
                default => 'ignored',
            };
        } catch (\Throwable $e) {
            // Forget the delivery so Paystack's retry is processed rather than treated as a duplicate.
            $record->delete();
            throw $e;
        }

        $record->forceFill(['outcome' => $outcome, 'payment_id' => $payment?->id])->save();

        return $outcome;
    }

    /** Scheduled: checks online payments nobody came back from, and closes long-abandoned ones. */
    public function reconcile(int $limit = 50): int
    {
        if (! $this->gateway->configured()) {
            return 0;
        }
        $checked = 0;
        $stale = Payment::where('method', 'paystack')->where('status', PaymentStatus::Pending->value)
            ->where('created_at', '<=', now()->subMinutes(10))
            ->orderByRaw('last_checked_at is not null')->orderBy('last_checked_at')->limit($limit)->get();

        foreach ($stale as $payment) {
            try {
                $this->confirm($payment, 'reconcile');
            } catch (RuleViolation) {
                // Provider unreachable or refused; try again next run.
            }
            $payment->refresh();
            if ($payment->status === PaymentStatus::Pending && $payment->created_at->lte(now()->subHours(self::ABANDON_AFTER_HOURS))) {
                $payment->forceFill(['status' => PaymentStatus::Abandoned])->save();
                Audit::record('payment.abandoned', "Online payment {$payment->reference} was never completed", $payment);
            }
            $payment->forceFill(['last_checked_at' => now()])->save();
            $checked++;
        }

        return $checked;
    }

    /** Asks Paystack to refund. The money only counts as refunded when Paystack confirms it. */
    public function requestRefund(Payment $payment, int $amountMinor, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('verify', $payment);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuleViolation('Record the reason for the refund.');
        }

        DB::transaction(function () use ($payment, $amountMinor) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->method !== 'paystack') {
                throw new RuleViolation('Only online payments are refunded through Paystack. Record other refunds manually with the bank reference.');
            }
            $this->payments->assertRefundable($payment, $amountMinor);
            $payment->forceFill(['refund_pending_minor' => $payment->refund_pending_minor + $amountMinor, 'refund_status' => 'pending'])->save();
        });

        try {
            $this->gateway->refund($payment->provider_reference, $amountMinor);
        } catch (RuleViolation $e) {
            DB::transaction(function () use ($payment, $amountMinor) {
                $payment = Payment::lockForUpdate()->findOrFail($payment->id);
                $payment->forceFill(['refund_pending_minor' => max(0, $payment->refund_pending_minor - $amountMinor), 'refund_status' => $payment->refunded_minor > 0 ? 'processed' : null])->save();
            });
            throw $e;
        }

        Audit::record('payment.refund_requested', 'Refund of '.Money::format($amountMinor, $payment->currency)." requested from Paystack for {$payment->reference}: {$reason}", $payment, actor: $actor);
    }

    /** Applies a verified transaction record. */
    private function apply(Payment $payment, array $data, string $source): string
    {
        $result = DB::transaction(function () use ($payment, $data) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::Pending) {
                return 'already_processed';
            }
            if (($data['reference'] ?? null) !== $payment->provider_reference) {
                return 'reference_mismatch';
            }

            $status = (string) ($data['status'] ?? '');
            $payment->forceFill([
                'provider_transaction_id' => isset($data['id']) ? (string) $data['id'] : $payment->provider_transaction_id,
                'channel' => isset($data['channel']) ? mb_substr((string) $data['channel'], 0, 30) : $payment->channel,
                'gateway_response' => isset($data['gateway_response']) ? mb_substr((string) $data['gateway_response'], 0, 255) : $payment->gateway_response,
            ]);

            if ($status === 'success') {
                $received = (int) ($data['amount'] ?? 0);
                $currency = strtoupper((string) ($data['currency'] ?? ''));
                $payment->paid_at = $this->time($data['paid_at'] ?? $data['paidAt'] ?? null);
                if ($currency !== $payment->currency || $received !== $payment->amount_minor) {
                    $payment->forceFill([
                        'status' => PaymentStatus::NeedsReview,
                        'received_minor' => $received,
                        'received_currency' => mb_substr($currency, 0, 3) ?: null,
                        'review_reason' => "Paystack reported {$currency} ".Money::toDecimal($received).' but '.Money::format($payment->amount_minor, $payment->currency).' was expected',
                    ])->save();

                    return 'needs_review';
                }
                $this->payments->settle($payment, $received, $currency, null);
                Audit::record('payment.received', "Online payment {$payment->reference} confirmed by Paystack (".Money::format($received, $currency).')', $payment);

                return 'succeeded';
            }
            if (in_array($status, ['failed', 'reversed'], true)) {
                $payment->status = PaymentStatus::Failed;
                $payment->save();

                return 'failed';
            }
            $payment->save();

            return 'still_pending'; // abandoned / ongoing / pending / processing / queued: the client may still pay
        });

        $payment->refresh();
        if ($result === 'succeeded') {
            $this->contacts->notify($payment->client()->firstOrFail(), 'Payment received – thank you',
                'We have received your payment of '.Money::format((int) $payment->received_minor, $payment->currency).'. Your receipt is in your portal.', '/portal/payments/'.$payment->id);
        } elseif ($result === 'needs_review') {
            FinanceAlerts::send("Online payment needs review: {$payment->reference}", $payment->review_reason.'. It has not been applied to the invoice.', '/admin/payments/'.$payment->id);
        }

        return $result;
    }

    /**
     * Refund notifications. Paystack's refund payload is documented as carrying the transaction reference,
     * amount (minor units) and status; this is to be confirmed in sandbox testing (BUILD_STATUS).
     */
    private function refundEvent(Payment $payment, string $event, array $data): string
    {
        $amount = (int) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            return 'refund_without_amount';
        }

        return DB::transaction(function () use ($payment, $event, $amount) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($event === 'refund.processed') {
                $payment->refund_pending_minor = max(0, $payment->refund_pending_minor - $amount);
                if ($payment->status !== PaymentStatus::Succeeded || $amount > $payment->received_minor - $payment->refunded_minor) {
                    $payment->review_reason = 'Paystack reported a refund of '.Money::format($amount, $payment->currency).' that does not fit this payment';
                    $payment->save();
                    FinanceAlerts::send("Refund needs review: {$payment->reference}", $payment->review_reason.'.', '/admin/payments/'.$payment->id);

                    return 'refund_needs_review';
                }
                $payment->refund_status = 'processed';
                $this->payments->applyRefund($payment, $amount, 'Refund confirmed by Paystack', null);
                Audit::record('payment.refunded', Money::format($amount, $payment->currency)." refund of {$payment->reference} confirmed by Paystack", $payment);

                return 'refund_processed';
            }
            if ($event === 'refund.failed') {
                $payment->forceFill(['refund_pending_minor' => max(0, $payment->refund_pending_minor - $amount), 'refund_status' => 'failed',
                    'review_reason' => 'Paystack reported a failed refund of '.Money::format($amount, $payment->currency)])->save();
                Audit::record('payment.refund_failed', "Refund of {$payment->reference} failed at Paystack", $payment);
                FinanceAlerts::send("Refund failed: {$payment->reference}", $payment->review_reason.'.', '/admin/payments/'.$payment->id);

                return 'refund_failed';
            }

            return 'refund_update'; // pending / processing: nothing changes until processed or failed
        });
    }

    /** Disputes (chargebacks) are flagged for finance; money is not moved automatically. */
    private function disputeEvent(Payment $payment, string $event, array $data): string
    {
        $status = mb_substr((string) ($data['status'] ?? str_replace('charge.dispute.', '', $event)), 0, 40);
        $payment->forceFill(['dispute_status' => $status, 'review_reason' => "Paystack dispute: {$status}"])->save();
        Audit::record('payment.dispute', "Paystack dispute on {$payment->reference}: {$status}", $payment);
        FinanceAlerts::send("Payment dispute: {$payment->reference}", "The card holder has disputed this payment (status: {$status}). Respond in the Paystack dashboard; reverse the payment here only if the dispute is lost.", '/admin/payments/'.$payment->id);

        return 'dispute_'.$status;
    }

    private function referenceFrom(string $event, array $data): ?string
    {
        $reference = match (true) {
            str_starts_with($event, 'refund.') => $data['transaction_reference'] ?? ($data['transaction']['reference'] ?? null),
            str_starts_with($event, 'charge.dispute.') => $data['transaction']['reference'] ?? null,
            default => $data['reference'] ?? null,
        };

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /** Only what finance needs; no card details or customer data beyond what identifies the transaction. */
    private function minimal(array $data): array
    {
        return array_filter([
            'id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
            'reference' => $data['reference'] ?? ($data['transaction_reference'] ?? null),
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'channel' => $data['channel'] ?? null,
            'gateway_response' => isset($data['gateway_response']) ? mb_substr((string) $data['gateway_response'], 0, 200) : null,
            'paid_at' => $data['paid_at'] ?? ($data['paidAt'] ?? null),
        ], fn ($v) => $v !== null);
    }

    private function time(mixed $value): Carbon
    {
        try {
            return $value ? Carbon::parse((string) $value)->utc() : now();
        } catch (\Throwable) {
            return now();
        }
    }
}
