<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\ClientFunds;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentStatus;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

class PortalBillingTest extends TestCase
{
    use BillingFixtures;
    use PracticeFixtures;
    use RefreshDatabase;

    private string $verifyStatus = 'success';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('confidential');
        config(['services.paystack.secret_key' => 'sk_test_fake', 'services.paystack.currencies' => ['NGN'], 'services.paystack.ca_bundle' => null]);
        $this->setUpPractice();
        $this->setUpBilling();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/transaction/initialize')) {
                return Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz', 'reference' => $request['reference']]]);
            }
            $payment = Payment::latest('id')->first();

            return Http::response(['status' => true, 'data' => ['id' => 1, 'reference' => $payment->provider_reference, 'status' => $this->verifyStatus, 'amount' => $payment->amount_minor, 'currency' => 'NGN', 'channel' => 'card']]);
        });
    }

    public function test_client_sees_issued_invoices_with_honest_payment_options(): void
    {
        [$matter, $contact] = $this->openMatter();
        $ngn = $this->issuedInvoice($matter, '2500.00');
        $usd = $this->issuedInvoice($matter, '100.00', 'USD');
        $draft = $this->invoices()->createDraft($matter->client, ['title' => 'Draft', 'currency' => 'NGN', 'lines' => [['kind' => 'fee', 'description' => 'x', 'quantity' => 1, 'unit' => '1.00']]], $this->finance);

        $this->actingAs($contact)->get(route('portal.invoices'))->assertOk()
            ->assertSee($ngn->reference)->assertSee($usd->reference)->assertDontSee($draft->reference)->assertSee('are not added together');
        $this->actingAs($contact)->get(route('portal.invoices.show', $draft))->assertForbidden();
        $this->actingAs($contact)->get(route('portal.home'))->assertSee('Invoice '.$ngn->reference);

        // NGN: online yes; no bank details entered yet, so no transfer form.
        $this->actingAs($contact)->get(route('portal.invoices.show', $ngn))->assertOk()
            ->assertSee('Pay Online')->assertDontSee('Send Transfer Details')->assertSee('₦2,500.00', false);
        // USD: not enabled on Paystack and no bank details: say so instead of charging in naira.
        $this->actingAs($contact)->get(route('portal.invoices.show', $usd))->assertOk()
            ->assertDontSee('Pay Online')->assertSee('Payment options for USD invoices are not set up yet');
        $this->actingAs($contact)->post(route('portal.invoices.pay', $usd))->assertSessionHasErrors('pay');
        $this->assertSame(0, Payment::count());

        $this->configureBank('USD');
        $this->actingAs($contact)->get(route('portal.invoices.show', $usd))->assertSee('Send Transfer Details')->assertSee('EXAMPLEX')
            ->assertSee('Online card payment is not available for USD invoices');
    }

    public function test_other_clients_and_full_admins_cannot_pay(): void
    {
        [$matter, $contact] = $this->openMatter();
        $invoice = $this->issuedInvoice($matter);
        [, $stranger] = $this->openMatter();

        $this->actingAs($stranger)->get(route('portal.invoices.show', $invoice))->assertForbidden();
        $this->actingAs($stranger)->post(route('portal.invoices.pay', $invoice))->assertForbidden();
        $this->actingAs($this->admin)->post(route('portal.invoices.pay', $invoice))->assertRedirect(); // not a client portal user
        $this->assertSame(0, Payment::count());
    }

    public function test_online_payment_is_confirmed_by_server_verification_on_return(): void
    {
        [$matter, $contact] = $this->openMatter();
        $invoice = $this->issuedInvoice($matter, '2500.00');

        $this->actingAs($contact)->post(route('portal.invoices.pay', $invoice))->assertRedirect('https://checkout.paystack.com/xyz');
        $payment = Payment::latest('id')->firstOrFail();

        // A stranger's browser carrying the reference learns nothing and changes nothing.
        [, $stranger] = $this->openMatter();
        $this->actingAs($stranger)->get(route('portal.payments.callback', ['reference' => $payment->provider_reference]))->assertRedirect(route('portal.invoices'));
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $this->actingAs($contact)->get(route('portal.payments.callback', ['reference' => $payment->provider_reference, 'trxref' => $payment->provider_reference]))
            ->assertRedirect(route('portal.payments.show', $payment));
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->actingAs($contact)->get(route('portal.payments.show', $payment))->assertOk()->assertSee('Payment receipt')->assertSee('₦2,500.00', false);
        $this->actingAs($stranger)->get(route('portal.payments.show', $payment))->assertForbidden();
    }

    public function test_abandoned_checkout_on_return_leaves_the_invoice_unpaid(): void
    {
        [$matter, $contact] = $this->openMatter();
        $invoice = $this->issuedInvoice($matter);
        $this->verifyStatus = 'abandoned';
        $this->actingAs($contact)->post(route('portal.invoices.pay', $invoice));
        $payment = Payment::latest('id')->firstOrFail();

        $this->actingAs($contact)->get(route('portal.payments.callback', ['reference' => $payment->provider_reference]))
            ->assertRedirect(route('portal.invoices.show', $invoice))->assertSessionHas('status');
        $this->assertSame(InvoiceStatus::Issued, $invoice->refresh()->status);
        $this->actingAs($contact)->get(route('portal.payments.show', $payment))->assertForbidden(); // no receipt for unpaid
    }

    public function test_transfer_slip_is_not_proof_of_payment(): void
    {
        [$matter, $contact] = $this->openMatter();
        $invoice = $this->issuedInvoice($matter, '2500.00');
        $this->configureBank('NGN');

        $this->actingAs($contact)->from(route('portal.invoices.show', $invoice))->post(route('portal.invoices.transfer', $invoice), [
            'evidence' => $this->pdf('slip.pdf'),
            'amount' => '2500', 'paid_on' => now()->toDateString(), 'bank_reference' => 'FT-998',
        ])->assertRedirect(route('portal.invoices.show', $invoice))->assertSessionHas('status');

        $payment = Payment::latest('id')->firstOrFail();
        $this->assertSame(PaymentStatus::PendingVerification, $payment->status);
        $this->assertSame(InvoiceStatus::Issued, $invoice->refresh()->status);
        $this->actingAs($contact)->get(route('portal.invoices.show', $invoice))->assertSee('Being checked by our finance team');
        $this->actingAs($contact)->get(route('portal.payments.show', $payment))->assertForbidden();

        // Rubbish amount is refused before anything is stored.
        $this->actingAs($contact)->post(route('portal.invoices.transfer', $invoice), [
            'evidence' => $this->pdf('slip.pdf'), 'amount' => '2,500', 'paid_on' => now()->toDateString(),
        ])->assertSessionHasErrors('amount');
        $this->assertSame(1, Payment::count());
    }

    public function test_funds_statement_shows_only_the_clients_own_entries(): void
    {
        [$matter, $contact] = $this->openMatter();
        [$other, $stranger] = $this->openMatter();
        app(ClientFunds::class)->record($matter->client, $matter, ['currency' => 'NGN', 'type' => 'receipt', 'amount_minor' => 750_000, 'occurred_on' => now()->toDateString(), 'description' => 'Recovered from debtor'], null, $this->finance);

        $this->actingAs($contact)->get(route('portal.funds'))->assertOk()->assertSee('Recovered from debtor')->assertSee('₦7,500.00', false);
        $this->actingAs($stranger)->get(route('portal.funds'))->assertOk()->assertDontSee('Recovered from debtor')->assertSee('not holding any funds');
    }
}
