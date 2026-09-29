<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\ClientFunds;
use App\Domain\Billing\Expenses;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Engagement\Quotations;
use App\Domain\Identity\Role;
use App\Domain\Reporting\Reports as ReportData;
use App\Filament\Pages\Reports;
use App\Filament\Resources\ClientFunds\Pages\ListClientFundEntries;
use App\Filament\Resources\ClientFunds\Pages\ViewClientFundEntry;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Filament\Widgets\PracticeOverview;
use App\Models\AuditEvent;
use App\Models\ClientFundEntry;
use App\Models\ClientFundReconciliation;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

/** The staff billing screens, reports and evidence downloads. */
class BillingAdminUiTest extends TestCase
{
    use BillingFixtures;
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('confidential');
        Mail::fake();
        Notification::fake();
        $this->setUpPractice();
        $this->setUpBilling();
    }

    /** @return array{0: \App\Models\Matter, 1: \App\Models\User, 2: Invoice, 3: Payment, 4: ClientFundEntry} */
    private function billingRecords(): array
    {
        [$matter, $contact] = $this->openMatter();
        $invoice = $this->issuedInvoice($matter, '100000.00');
        $this->configureBank('NGN');
        $payment = $this->payments()->submitTransfer($invoice, $this->pdf('slip.pdf'), 10_000_000, now()->toDateString(), 'TRF-1', null, $contact);
        app(Expenses::class)->record($matter->client, $matter, ['currency' => 'NGN', 'amount_minor' => 500_000, 'description' => 'CAC search fee', 'incurred_on' => now()->toDateString()], $this->finance);
        $entry = app(ClientFunds::class)->record($matter->client, $matter, ['currency' => 'NGN', 'type' => 'receipt', 'amount_minor' => 2_000_000,
            'occurred_on' => now()->toDateString(), 'description' => 'Recovered debt'], $this->pdf('advice.pdf'), $this->finance);

        return [$matter, $contact, $invoice, $payment, $entry];
    }

    public function test_billing_screens_render_and_respect_access(): void
    {
        [$matter, , $invoice, $payment, $entry] = $this->billingRecords();
        $urls = ['/admin/invoices', "/admin/invoices/{$invoice->id}", '/admin/invoices/create', "/admin/invoices/create?matter={$matter->id}",
            '/admin/payments', "/admin/payments/{$payment->id}", '/admin/expenses', '/admin/client-funds', "/admin/client-funds/{$entry->id}", '/admin/reports'];

        $this->actingAsStaff($this->finance);
        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
        // Finance can pick any client or matter to bill, without being able to open the matter itself.
        $client = $matter->client()->firstOrFail();
        $this->assertArrayHasKey($client->id, InvoiceResource::billingClientSearch($client->reference));
        $this->assertArrayHasKey($matter->id, ExpenseResource::matterOptions($client->id));
        $this->get("/admin/matters/{$matter->id}")->assertNotFound();
        $this->actingAsStaff($this->admin);
        $this->get('/admin')->assertOk();
        Livewire::test(PracticeOverview::class)->assertSee('Unpaid NGN invoices')->assertSee('₦100,000.00')->assertSee('Payments to check');
        $this->get('/admin/reports')->assertOk()->assertSee('Practice');

        // A lawyer outside the matter sees none of it; a case officer on the matter cannot read client funds.
        $this->actingAsStaff($this->userWithRoles(Role::Lawyer));
        $this->get("/admin/payments/{$payment->id}")->assertNotFound();
        $this->get("/admin/invoices/{$invoice->id}")->assertNotFound();
        $this->get('/admin/reports')->assertForbidden();
        $this->actingAsStaff($this->teamMember($matter));
        $this->get("/admin/invoices/{$invoice->id}")->assertOk();
        $this->get("/admin/client-funds/{$entry->id}")->assertNotFound();
    }

    public function test_finance_verifies_a_transfer_from_the_payment_screen(): void
    {
        [, , $invoice, $payment] = $this->billingRecords();
        $this->actingAsStaff($this->finance);

        // The form is pre-filled without thousands separators, so it validates as-is.
        Livewire::test(ViewPayment::class, ['record' => $payment->id])
            ->mountAction('verify')
            ->assertActionDataSet(['amount' => '100000.00'])
            ->setActionData(['received_on' => now()->toDateString(), 'bank_reference' => 'FT-778'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Payment verified');

        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    public function test_money_received_without_invoice_becomes_credit_that_can_be_applied(): void
    {
        [$matter] = $this->openMatter();
        $invoice = $this->issuedInvoice($matter, '3000.00');
        $this->actingAsStaff($this->finance);

        Livewire::test(ListPayments::class)
            ->callAction('recordReceived', ['client_id' => $matter->client_id, 'currency' => 'NGN', 'amount' => '5000',
                'received_on' => now()->toDateString(), 'method' => 'bank_transfer', 'bank_reference' => 'RET-1'])
            ->assertHasNoErrors()->assertNotified('Payment recorded');
        $credit = Payment::where('bank_reference', 'RET-1')->firstOrFail();
        $this->assertSame(500_000, $credit->availableCreditMinor());

        Livewire::test(ViewPayment::class, ['record' => $credit->id])
            ->callAction('applyCredit', ['invoice_id' => $invoice->id, 'amount' => '3000'])
            ->assertNotified('Credit applied');
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(200_000, $credit->refresh()->availableCreditMinor());
    }

    public function test_expense_and_client_funds_screens(): void
    {
        [$matter] = $this->openMatter();
        $this->actingAsStaff($this->finance);

        Livewire::test(ListExpenses::class)
            ->callAction('record', ['client_id' => $matter->client_id, 'matter_id' => $matter->id, 'currency' => 'USD', 'amount' => '120.50',
                'incurred_on' => now()->toDateString(), 'billable' => true, 'description' => 'Courier to London'])
            ->assertHasNoErrors()->assertNotified('Expense recorded');
        $expense = Expense::where('description', 'Courier to London')->firstOrFail();
        $this->assertSame(12_050, $expense->amount_minor);
        Livewire::test(ListExpenses::class)->callTableAction('void', $expense, ['reason' => 'Paid by the client directly']);
        $this->assertNotNull($expense->refresh()->voided_at);

        // An outflow without authorisation is refused by the form; with it, it is recorded.
        Livewire::test(ListClientFundEntries::class)
            ->callAction('record', ['client_id' => $matter->client_id, 'type' => 'receipt', 'currency' => 'NGN', 'amount' => '10000',
                'occurred_on' => now()->toDateString(), 'description' => 'Settlement from Acme', 'evidence' => $this->pdf('credit.pdf')])
            ->assertNotified('Entry recorded');
        Livewire::test(ListClientFundEntries::class)
            ->callAction('record', ['client_id' => $matter->client_id, 'type' => 'remittance', 'currency' => 'NGN', 'amount' => '4000',
                'occurred_on' => now()->toDateString(), 'description' => 'Paid to the client'])
            ->assertHasActionErrors(['authorisation' => 'required']);
        $receipt = ClientFundEntry::where('type', 'receipt')->firstOrFail();
        $this->assertNotNull($receipt->evidence_path);

        Livewire::test(ViewClientFundEntry::class, ['record' => $receipt->id])
            ->callAction('reverse', ['reason' => 'Entered against the wrong client'])
            ->assertNotified('Entry reversed');
        $this->assertSame(0, app(ClientFunds::class)->balance($matter->client_id, 'NGN'));

        // A statement balance that disagrees with the ledger is recorded and flagged, not "fixed".
        Livewire::test(ListClientFundEntries::class)
            ->callAction('reconcile', ['currency' => 'NGN', 'statement_date' => now()->toDateString(), 'balance' => '250'])
            ->assertNotified('The balances do not agree');
        $this->assertSame(25_000, ClientFundReconciliation::firstOrFail()->differenceMinor());
    }

    public function test_evidence_downloads_are_restricted_and_audited(): void
    {
        [$matter, , , $payment, $entry] = $this->billingRecords();

        $this->actingAsStaff($this->lawyer);
        $this->get(route('admin.payment-evidence', $payment))->assertForbidden();

        $this->actingAsStaff($this->finance);
        $this->get(route('admin.payment-evidence', $payment))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Disposition');
        $this->get(route('admin.fund-evidence', $entry))->assertOk();
        $this->assertSame(2, AuditEvent::where('action', 'billing.evidence_downloaded')->count());

        $this->actingAsStaff($this->teamMember($matter));
        $this->get(route('admin.fund-evidence', $entry))->assertForbidden();
    }

    public function test_reports_keep_currencies_apart_and_exports_are_audited(): void
    {
        [$matter] = $this->openMatter();
        $ngn = $this->issuedInvoice($matter, '100000.00');
        $usd = $this->issuedInvoice($matter, '500.00', 'USD');
        $this->payments()->recordReceived($matter->client, $ngn, ['currency' => 'NGN', 'amount_minor' => 4_000_000, 'method' => 'bank_transfer',
            'received_on' => now()->toDateString(), 'bank_reference' => 'FT-1'], $this->finance);
        Invoice::whereKey($usd->id)->update(['due_date' => now()->subDays(40)->toDateString()]);

        $figures = app(ReportData::class)->finance([]);
        $this->assertSame(10_000_000, $figures['NGN']['invoiced']);
        $this->assertSame(4_000_000, $figures['NGN']['received']);
        $this->assertSame(6_000_000, $figures['NGN']['outstanding']);
        $this->assertSame(50_000, $figures['USD']['invoiced']);
        $this->assertSame(0, $figures['USD']['received']);
        $this->assertSame(50_000, $figures['USD']['31_60']);
        $this->assertSame(50_000, $figures['USD']['overdue']);

        $this->actingAsStaff($this->finance);
        Livewire::test(Reports::class)
            ->assertSee('₦60,000.00')->assertSee('$500.00')->assertDontSee('Practice')
            ->callAction('exportInvoices', ['current_password' => 'password'])
            ->assertFileDownloaded();
        $audit = AuditEvent::where('action', 'report.exported')->firstOrFail();
        $this->assertSame($this->finance->id, $audit->actor_id);
        $this->assertSame('invoices', $audit->changes['after']['report']);

        $rows = app(ReportData::class)->invoiceRows(['currency' => 'USD']);
        $this->assertCount(1, $rows);
        $this->assertSame('500.00', $rows[0]['Total']);
        $this->assertSame('31–60 days', $rows[0]['Ageing']);
    }

    public function test_draft_invoice_from_an_accepted_quotation(): void
    {
        [$matter, $contact] = $this->openMatter();
        $quotations = app(Quotations::class);
        $quotation = $quotations->create($matter->client, null, $matter, $this->quotationData([
            'payment_stages' => [['label' => 'Deposit', 'amount' => '100000.00', 'due' => 'On acceptance'], ['label' => 'Balance', 'amount' => '75000.50', 'due' => 'On completion']],
        ]), $this->lawyer);
        $quotations->send($quotation, $this->lawyer);
        $quotation->refresh();
        $quotations->respond($quotation, $quotation->current_version_id, 'accepted', $contact, null, '127.0.0.1', 'test');

        $this->actingAsStaff($this->finance);
        Livewire::test(ViewQuotation::class, ['record' => $quotation->id])
            ->callAction('invoice', ['stage' => '0'])
            ->assertNotified('Draft invoice created');

        $invoice = Invoice::where('quotation_id', $quotation->id)->firstOrFail();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame(10_000_000, $invoice->total_minor);

        // The lawyer who wrote the quotation cannot raise invoices.
        $this->actingAsStaff($this->lawyer);
        Livewire::test(ViewQuotation::class, ['record' => $quotation->id])->assertActionHidden('invoice');
    }
}
