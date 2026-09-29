<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Expenses;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Engagement\Quotations;
use App\Domain\Identity\Role;
use App\Domain\RuleViolation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Notifications\PortalUpdate;
use App\Notifications\StaffAlert;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

class InvoicesAndPaymentsTest extends TestCase
{
    use BillingFixtures;
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('confidential');
        $this->setUpPractice();
        $this->setUpBilling();
    }

    public function test_drafts_are_editable_and_issued_invoices_are_frozen(): void
    {
        [$matter, $contact] = $this->openMatter();
        $invoice = $this->invoices()->createDraft($matter->client, [
            'title' => 'Fees', 'currency' => 'NGN', 'matter_id' => $matter->id,
            'lines' => [['kind' => 'fee', 'description' => 'Advice', 'quantity' => 2, 'unit' => '1,250.50'], ['kind' => 'tax', 'description' => 'VAT', 'quantity' => 1, 'unit' => '187.58']],
        ], $this->finance);

        $this->assertStringStartsWith('INV-', $invoice->reference);
        $this->assertSame(250100, $invoice->fees_minor);
        $this->assertSame(18758, $invoice->tax_minor);
        $this->assertSame(268858, $invoice->total_minor);

        // Lawyers read the matter's billing but cannot create or change it.
        $this->assertThrows(fn () => $this->invoices()->updateDraft($invoice, ['title' => 'x'], $this->lawyer), AuthorizationException::class);
        $this->assertTrue($this->lawyer->can('view', $invoice));
        $this->assertFalse($this->userWithRoles(Role::Lawyer)->can('view', $invoice));
        $this->assertFalse($contact->can('viewAsClient', $invoice)); // drafts are invisible to clients

        $this->invoices()->issue($invoice, $this->finance);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertNotNull($invoice->content_hash);
        $this->assertTrue($contact->can('viewAsClient', $invoice));
        Notification::assertSentTo($contact, PortalUpdate::class);

        $this->assertThrows(fn () => $this->invoices()->updateDraft($invoice, ['title' => 'Changed'], $this->finance), AuthorizationException::class);
        $this->assertThrows(fn () => $this->invoices()->discardDraft($invoice, 'Wrong client', $this->finance), RuleViolation::class);
        $this->assertFalse($this->finance->can('delete', $invoice));
    }

    public function test_credit_notes_are_capped_at_the_balance(): void
    {
        [$matter] = $this->openMatter();
        $invoice = $this->issuedInvoice($matter, '1000.00');

        $this->assertThrows(fn () => $this->invoices()->credit($invoice, 100001, 'Discount agreed', $this->finance), RuleViolation::class);
        $note = $this->invoices()->credit($invoice, 40000, 'Discount agreed with client', $this->finance);
        $this->assertStringStartsWith('CRN-', $note->reference);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status); // credited in part, nothing paid
        $this->assertSame(60000, $invoice->balanceMinor());

        $this->invoices()->credit($invoice, 60000, 'Matter closed without further work', $this->finance);
        $this->assertSame(InvoiceStatus::Credited, $invoice->refresh()->status);
        $this->assertSame(100000, $invoice->total_minor); // the invoice itself is unchanged
    }

    public function test_transfer_evidence_stays_unpaid_until_finance_verifies(): void
    {
        [$matter, $contact] = $this->openMatter();
        $invoice = $this->issuedInvoice($matter, '1000.00');

        // No bank details configured for NGN: no transfer option.
        $this->assertThrows(fn () => $this->payments()->submitTransfer($invoice, $this->pdf('receipt.pdf'), 100000, now()->toDateString(), 'TRF1', null, $contact), RuleViolation::class);

        $this->configureBank('NGN');
        $payment = $this->payments()->submitTransfer($invoice, $this->pdf('receipt.pdf'), 100000, now()->toDateString(), 'TRF1', 'Paid from GTB', $contact);
        $this->assertSame(PaymentStatus::PendingVerification, $payment->status);
        $this->assertSame(InvoiceStatus::Issued, $invoice->refresh()->status);
        $this->assertSame(0, $invoice->paid_minor);
        Storage::disk('confidential')->assertExists($payment->evidence_path);
        Notification::assertSentTo($this->admin, StaffAlert::class);
        Notification::assertSentTo($this->finance, StaffAlert::class);

        // The same screenshot again is flagged as a possible duplicate.
        $again = $this->payments()->submitTransfer($invoice, $this->pdf('receipt.pdf'), 100000, now()->toDateString(), 'TRF1', null, $contact);
        $this->assertStringContainsString($payment->reference, $again->review_reason);

        // Only finance or administrators verify; the client and the lawyer cannot.
        $this->assertThrows(fn () => $this->payments()->verifyTransfer($payment, 100000, now()->toDateString(), 'TRF1', null, $contact), AuthorizationException::class);
        $this->assertThrows(fn () => $this->payments()->verifyTransfer($payment, 100000, now()->toDateString(), 'TRF1', null, $this->lawyer), AuthorizationException::class);

        // Partial amount found in the bank.
        $this->payments()->verifyTransfer($payment, 60000, now()->toDateString(), 'TRF1', 'Seen on statement', $this->finance);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame(40000, $invoice->balanceMinor());

        // The duplicate claim with the same bank reference cannot also be verified.
        $this->assertThrows(fn () => $this->payments()->verifyTransfer($again, 100000, now()->toDateString(), 'TRF1', null, $this->finance), RuleViolation::class);
        $this->payments()->rejectTransfer($again, 'Duplicate of the earlier transfer', $this->finance);
        $this->assertSame(PaymentStatus::Rejected, $again->refresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'payment.transfer_rejected']);
    }

    public function test_overpayment_becomes_flagged_credit_and_reversal_restores_the_balance(): void
    {
        [$matter] = $this->openMatter();
        $first = $this->issuedInvoice($matter, '1000.00');
        $second = $this->issuedInvoice($matter, '300.00');

        $payment = $this->payments()->recordReceived($matter->client, $first, [
            'currency' => 'NGN', 'amount_minor' => 150000, 'method' => 'bank_transfer', 'received_on' => now()->toDateString(), 'bank_reference' => 'BANK-9',
        ], $this->finance);
        $payment->refresh();
        $this->assertSame(InvoiceStatus::Paid, $first->refresh()->status);
        $this->assertSame(50000, $payment->unapplied_minor);
        $this->assertStringContainsString('more than the invoice balance', $payment->review_reason);

        // Currency never mixes.
        $usd = $this->issuedInvoice($matter, '10.00', 'USD');
        $this->assertThrows(fn () => $this->payments()->applyCredit($payment, $usd, 1000, $this->finance), RuleViolation::class);

        $this->payments()->applyCredit($payment, $second, 30000, $this->finance);
        $this->assertSame(InvoiceStatus::Paid, $second->refresh()->status);
        $this->assertSame(20000, $payment->refresh()->unapplied_minor);

        // The cheque bounced: every allocation is undone with negative rows, nothing deleted.
        $this->payments()->reverse($payment, 'Cheque returned unpaid', $this->finance);
        $this->assertSame(PaymentStatus::Reversed, $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::Issued, $first->refresh()->status);
        $this->assertSame(InvoiceStatus::Issued, $second->refresh()->status);
        $this->assertSame(0, (int) PaymentAllocation::where('payment_id', $payment->id)->sum('amount_minor'));
        $this->assertSame(4, PaymentAllocation::where('payment_id', $payment->id)->count());
    }

    public function test_manual_refund_needs_evidence_and_reopens_the_invoice(): void
    {
        [$matter] = $this->openMatter();
        $invoice = $this->issuedInvoice($matter, '1000.00');
        $payment = $this->payments()->recordReceived($matter->client, $invoice, [
            'currency' => 'NGN', 'amount_minor' => 100000, 'method' => 'cash', 'received_on' => now()->toDateString(),
        ], $this->finance);

        $this->assertThrows(fn () => $this->payments()->recordManualRefund($payment, 30000, now()->toDateString(), '', 'Overcharged', $this->finance), RuleViolation::class);
        $this->payments()->recordManualRefund($payment, 30000, now()->toDateString(), 'RF-77', 'Part of the work was cancelled', $this->finance);

        $this->assertSame(30000, $payment->refresh()->refunded_minor);
        $invoice->refresh();
        $this->assertSame(70000, $invoice->paid_minor);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertThrows(fn () => $this->payments()->recordManualRefund($payment, 80000, now()->toDateString(), 'RF-78', 'Too much', $this->finance), RuleViolation::class);
    }

    public function test_expenses_and_quotation_stages_feed_draft_invoices(): void
    {
        [$matter, $contact] = $this->openMatter();
        $expense = app(Expenses::class)->record($matter->client, $matter, [
            'currency' => 'NGN', 'amount_minor' => 2500050, 'description' => 'CAC filing fee', 'incurred_on' => now()->toDateString(),
        ], $this->finance);
        $usdExpense = app(Expenses::class)->record($matter->client, $matter, [
            'currency' => 'USD', 'amount_minor' => 5000, 'description' => 'Courier abroad', 'incurred_on' => now()->toDateString(),
        ], $this->finance);

        $invoice = $this->invoices()->createDraft($matter->client, ['title' => 'Disbursements', 'currency' => 'NGN', 'expense_ids' => [$expense->id]], $this->finance);
        $this->assertSame(2500050, $invoice->expenses_minor);
        $this->assertSame($invoice->id, $expense->refresh()->invoice_id);
        $this->assertThrows(fn () => $this->invoices()->createDraft($matter->client, ['title' => 'Mixed', 'currency' => 'NGN', 'expense_ids' => [$usdExpense->id]], $this->finance), RuleViolation::class);
        $this->assertThrows(fn () => app(Expenses::class)->void($expense, 'Entered twice', $this->finance), RuleViolation::class);

        $this->invoices()->discardDraft($invoice, 'Will bill with the fees', $this->finance);
        $this->assertNull($expense->refresh()->invoice_id);

        // Quotation with payment stages → an invoice for one stage.
        $quotation = app(Quotations::class)->create($matter->client, null, $matter, $this->quotationData([
            'payment_stages' => [['label' => 'Deposit', 'amount' => '100000.00', 'due' => 'On acceptance'], ['label' => 'Balance', 'amount' => '75000.50', 'due' => 'On completion']],
        ]), $this->lawyer);
        app(Quotations::class)->send($quotation, $this->lawyer);
        $quotation->refresh();
        app(Quotations::class)->respond($quotation, $quotation->current_version_id, 'accepted', $contact, null, '127.0.0.1', 'test');

        $deposit = $this->invoices()->draftFromQuotation($quotation->refresh(), 0, $this->finance);
        $this->assertSame(10000000, $deposit->total_minor);
        $this->assertSame($quotation->id, $deposit->quotation_id);
        $whole = $this->invoices()->draftFromQuotation($quotation, null, $this->finance);
        $this->assertSame(17500050, $whole->total_minor);
    }

    public function test_client_a_cannot_see_or_pay_client_b_invoices(): void
    {
        [$matterA, $contactA] = $this->openMatter();
        [$matterB] = $this->openMatter();
        $invoiceB = $this->issuedInvoice($matterB);
        $this->configureBank('NGN');

        $this->assertFalse($contactA->can('viewAsClient', $invoiceB));
        $this->assertThrows(fn () => $this->payments()->submitTransfer($invoiceB, $this->pdf(), 1000, now()->toDateString(), null, null, $contactA), AuthorizationException::class);

        // A full administrator never pays as the client.
        $this->assertFalse($this->admin->can('payAsClient', $invoiceB));
        $this->assertSame(0, Payment::count());
        $this->assertSame(1, Invoice::count());
    }
}
