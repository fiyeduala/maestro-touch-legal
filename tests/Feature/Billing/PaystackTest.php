<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\PaystackPayments;
use App\Domain\RuleViolation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

/** Paystack behaviour against faked HTTP responses. Nothing here talks to Paystack. */
class PaystackTest extends TestCase
{
    use BillingFixtures;
    use PracticeFixtures;
    use RefreshDatabase;

    private const SECRET = 'sk_test_fake_secret_for_tests_only';

    /** @var array<string, array> reference => transaction data returned by the fake verify endpoint */
    private array $transactions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('confidential');
        config(['services.paystack.secret_key' => self::SECRET, 'services.paystack.currencies' => ['NGN'], 'services.paystack.ca_bundle' => null]);
        $this->setUpPractice();
        $this->setUpBilling();

        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/transaction/initialize')) {
                return Http::response(['status' => true, 'message' => 'ok', 'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/abc123', 'access_code' => 'abc123', 'reference' => $request['reference'],
                ]]);
            }
            if (preg_match('#/transaction/verify/(.+)$#', $request->url(), $m)) {
                $ref = rawurldecode($m[1]);

                return Http::response(['status' => true, 'message' => 'ok', 'data' => $this->transactions[$ref] ?? ['reference' => $ref, 'status' => 'abandoned']]);
            }
            if (str_ends_with($request->url(), '/refund')) {
                return Http::response(['status' => true, 'message' => 'Refund queued', 'data' => ['status' => 'pending']]);
            }

            return Http::response(['status' => false, 'message' => 'unexpected'], 404);
        });
    }

    private function paystack(): PaystackPayments
    {
        return app(PaystackPayments::class);
    }

    /** @return array{0: Invoice, 1: User, 2: Payment} */
    private function checkout(string $amount = '1000.00'): array
    {
        [$matter, $contact] = $this->openMatter();
        $invoice = $this->issuedInvoice($matter, $amount);
        $url = $this->paystack()->startCheckout($invoice, $contact, 'http://localhost/portal/payments/callback');
        $this->assertSame('https://checkout.paystack.com/abc123', $url);

        return [$invoice, $contact, Payment::latest('id')->firstOrFail()];
    }

    private function succeed(Payment $payment, array $overrides = []): void
    {
        $this->transactions[$payment->provider_reference] = $overrides + [
            'id' => 555, 'reference' => $payment->provider_reference, 'status' => 'success',
            'amount' => $payment->amount_minor, 'currency' => $payment->currency, 'channel' => 'card', 'gateway_response' => 'Approved', 'paid_at' => now()->toIso8601String(),
        ];
    }

    private function webhook(array $body, ?string $signature = null)
    {
        $raw = json_encode($body);

        return $this->call('POST', '/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $raw, self::SECRET),
        ], $raw);
    }

    public function test_checkout_uses_the_stored_balance_and_currency(): void
    {
        [$invoice, , $payment] = $this->checkout('1234.56');

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(123456, $payment->amount_minor);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize')
            && $r['amount'] === 123456 && $r['currency'] === 'NGN' && $r['reference'] === $payment->provider_reference
            && $r->hasHeader('Authorization', 'Bearer '.self::SECRET));
        $this->assertSame(InvoiceStatus::Issued, $invoice->refresh()->status); // nothing credited yet

        // USD is not enabled on the account: no online option, no silent NGN charge.
        $usd = $this->issuedInvoice($invoice->matter, '50.00', 'USD');
        $this->assertThrows(fn () => $this->paystack()->startCheckout($usd, User::find($payment->submitted_by), 'http://localhost/cb'), RuleViolation::class);
    }

    public function test_invalid_signature_is_refused_and_records_nothing(): void
    {
        [$invoice, , $payment] = $this->checkout();
        $this->succeed($payment);

        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $payment->provider_reference, 'amount' => 100000, 'currency' => 'NGN', 'status' => 'success']], 'bad-signature')
            ->assertStatus(401);
        $this->assertSame(0, PaymentEvent::count());
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(0, $invoice->refresh()->paid_minor);
    }

    public function test_webhook_is_verified_server_side_and_duplicates_do_not_double_credit(): void
    {
        [$invoice, $contact, $payment] = $this->checkout();
        $this->succeed($payment);
        $body = ['event' => 'charge.success', 'data' => ['id' => 555, 'reference' => $payment->provider_reference, 'status' => 'success', 'amount' => 100000, 'currency' => 'NGN']];

        $this->webhook($body)->assertOk();
        $this->webhook($body)->assertOk(); // Paystack retries the same delivery
        $this->paystack()->confirm($payment->refresh(), 'callback'); // the browser returns late

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(100000, $invoice->refresh()->paid_minor);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(1, $payment->allocations()->count());
        $this->assertSame(1, PaymentEvent::where('event', 'charge.success')->count()); // the retry was recorded once
        Notification::assertSentTo($contact, \App\Notifications\PortalUpdate::class, fn ($n) => str_contains($n->subject, 'Payment received'));
    }

    public function test_a_forged_success_body_is_not_trusted_without_provider_verification(): void
    {
        [$invoice, , $payment] = $this->checkout();
        // Correctly signed (e.g. a replay) but Paystack's own record says it was not paid.
        $this->webhook(['event' => 'charge.success', 'data' => ['id' => 1, 'reference' => $payment->provider_reference, 'status' => 'success', 'amount' => 100000, 'currency' => 'NGN']])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(0, $invoice->refresh()->paid_minor);
    }

    public function test_wrong_amount_or_currency_waits_for_review(): void
    {
        [$invoice, , $payment] = $this->checkout();
        $this->succeed($payment, ['amount' => 50000]);
        $this->assertSame('needs_review', $this->paystack()->confirm($payment, 'callback'));
        $this->assertSame(PaymentStatus::NeedsReview, $payment->refresh()->status);
        $this->assertSame(0, $invoice->refresh()->paid_minor);

        [$invoice2, , $payment2] = $this->checkout();
        $this->succeed($payment2, ['currency' => 'USD']);
        $this->paystack()->confirm($payment2, 'callback');
        $this->assertSame(PaymentStatus::NeedsReview, $payment2->refresh()->status);
        // Money in another currency is never accepted as payment of an NGN invoice.
        $this->assertThrows(fn () => $this->payments()->resolveReview($payment2, 'accept', 'Checked the dashboard', $this->finance), RuleViolation::class);

        // The same-currency short payment can be accepted after a person checks it.
        $this->payments()->resolveReview($payment, 'accept', 'Client paid part; agreed by phone', $this->finance);
        $this->assertSame(50000, $invoice->refresh()->paid_minor);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
    }

    public function test_unknown_references_and_other_events_are_ignored(): void
    {
        [$invoice] = $this->checkout();
        $this->webhook(['event' => 'charge.success', 'data' => ['id' => 9, 'reference' => 'NVN-OTHER-SYSTEM', 'status' => 'success', 'amount' => 100000, 'currency' => 'NGN']])->assertOk();
        $this->webhook(['event' => 'transfer.success', 'data' => ['id' => 10, 'reference' => 'x']])->assertOk();

        $this->assertSame(['unknown_reference', 'unknown_reference'], PaymentEvent::orderBy('id')->pluck('outcome')->all());
        $this->assertSame(0, $invoice->refresh()->paid_minor);
    }

    public function test_missed_callbacks_are_reconciled_and_old_checkouts_abandoned(): void
    {
        [$invoice, , $paid] = $this->checkout();
        $this->succeed($paid);
        [, , $never] = $this->checkout();

        $this->travel(20)->minutes();
        $this->assertSame(2, $this->paystack()->reconcile());
        $this->assertSame(PaymentStatus::Succeeded, $paid->refresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(PaymentStatus::Pending, $never->refresh()->status); // the client may still pay

        $this->travel(49)->hours();
        $this->paystack()->reconcile();
        $this->assertSame(PaymentStatus::Abandoned, $never->refresh()->status);

        // A success that arrives after abandonment is not applied automatically.
        $this->succeed($never);
        $this->assertSame('already_processed', $this->paystack()->confirm($never, 'webhook'));
    }

    public function test_refunds_complete_only_when_paystack_confirms(): void
    {
        [$invoice, , $payment] = $this->checkout();
        $this->succeed($payment);
        $this->paystack()->confirm($payment, 'callback');

        $this->paystack()->requestRefund($payment->refresh(), 40000, 'Client overpaid for filing', $this->finance);
        $payment->refresh();
        $this->assertSame(40000, $payment->refund_pending_minor);
        $this->assertSame(0, $payment->refunded_minor);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        // Nothing more than what is left can be requested.
        $this->assertThrows(fn () => $this->paystack()->requestRefund($payment, 60001, 'Too much', $this->finance), RuleViolation::class);

        $this->webhook(['event' => 'refund.processed', 'data' => ['id' => 77, 'transaction_reference' => $payment->provider_reference, 'amount' => 40000, 'currency' => 'NGN', 'status' => 'processed']])->assertOk();
        $payment->refresh();
        $this->assertSame(40000, $payment->refunded_minor);
        $this->assertSame(0, $payment->refund_pending_minor);
        $this->assertSame('processed', $payment->refund_status);
        $this->assertSame(60000, $invoice->refresh()->paid_minor);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
    }

    public function test_disputes_are_flagged_without_moving_money(): void
    {
        [$invoice, , $payment] = $this->checkout();
        $this->succeed($payment);
        $this->paystack()->confirm($payment, 'callback');

        $this->webhook(['event' => 'charge.dispute.create', 'data' => ['id' => 88, 'status' => 'awaiting-merchant-feedback', 'transaction' => ['reference' => $payment->provider_reference]]])->assertOk();
        $payment->refresh();
        $this->assertSame('awaiting-merchant-feedback', $payment->dispute_status);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(100000, $invoice->refresh()->paid_minor);
    }

    public function test_the_secret_key_never_reaches_logs_audits_or_pages(): void
    {
        [$invoice, $contact, $payment] = $this->checkout();
        $this->succeed($payment);
        $this->paystack()->confirm($payment, 'callback');

        $this->assertDatabaseMissing('audit_events', ['summary' => self::SECRET]);
        foreach (\App\Models\AuditEvent::all() as $event) {
            $this->assertStringNotContainsString(self::SECRET, json_encode($event->toArray()));
        }
        $this->actingAs($contact)->get(route('portal.invoices.show', $invoice))->assertOk()->assertDontSee(self::SECRET);
        $this->actingAsStaff($this->admin)->get('/admin/operations')->assertOk()->assertDontSee(self::SECRET)->assertSee('Test mode');
    }
}
