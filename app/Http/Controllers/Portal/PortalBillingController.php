<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Billing\ClientFunds;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\Payments;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\PaystackGateway;
use App\Domain\Billing\PaystackPayments;
use App\Domain\Documents\UploadGuard;
use App\Domain\Operations\BankInstructions;
use App\Domain\RuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The client's invoices, payments, receipts and client-funds statement. Clients see only issued
 * invoices for their own client records. Online payment is offered only in currencies enabled on the
 * Paystack account, and bank transfer only when the firm has entered bank details for that currency;
 * an uploaded transfer slip is shown as "awaiting verification" until finance confirms it.
 */
class PortalBillingController extends Controller
{
    public function index(Request $request): View
    {
        $clientIds = $request->user()->clients()->pluck('clients.id');

        return view('portal.invoices', [
            'invoices' => Invoice::whereIn('client_id', $clientIds)
                ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
                ->with('matter:id,reference,title')->latest('issue_date')->latest('id')->get(),
            'payments' => Payment::whereIn('client_id', $clientIds)
                ->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::PendingVerification->value, PaymentStatus::NeedsReview->value])
                ->with('invoice:id,reference')->latest('id')->limit(50)->get(),
            'hasFunds' => \App\Models\ClientFundEntry::whereIn('client_id', $clientIds)->exists(),
        ]);
    }

    public function show(Invoice $invoice, PaystackGateway $gateway): View
    {
        Gate::authorize('viewAsClient', $invoice);
        $invoice->load(['lines', 'matter:id,reference,title', 'client:id,reference,display_name', 'creditNotes']);
        $canPay = Gate::allows('payAsClient', $invoice);

        return view('portal.invoice', [
            'invoice' => $invoice,
            'money' => fn (int $minor) => Money::format($minor, $invoice->currency),
            'canPay' => $canPay,
            'online' => $canPay && $gateway->accepts($invoice->currency),
            'bank' => $canPay ? BankInstructions::for($invoice->currency) : null,
            'payments' => $invoice->payments()->whereNotIn('status', [PaymentStatus::Pending->value, PaymentStatus::Abandoned->value])->latest('id')->get(),
            'accept' => UploadGuard::acceptAttribute(),
            'maxMb' => intdiv(UploadGuard::MAX_KILOBYTES, 1024),
        ]);
    }

    public function pay(Request $request, Invoice $invoice, PaystackPayments $paystack): RedirectResponse
    {
        Gate::authorize('payAsClient', $invoice);
        try {
            $url = $paystack->startCheckout($invoice, $request->user(), route('portal.payments.callback'));
        } catch (RuleViolation $e) {
            return back()->withErrors(['pay' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    /** Paystack sends the browser back here. The payment is verified with Paystack, never taken from the URL. */
    public function callback(Request $request, PaystackPayments $paystack): RedirectResponse
    {
        $reference = (string) $request->query('reference', $request->query('trxref', ''));
        $payment = $reference !== '' ? Payment::where('provider_reference', $reference)->where('method', 'paystack')->first() : null;
        if (! $payment || ! $request->user()->clients()->whereKey($payment->client_id)->exists()) {
            return redirect()->route('portal.invoices')->withErrors(['pay' => 'We could not find that payment. If money left your account, please send us a message and we will check.']);
        }

        try {
            $outcome = $paystack->confirm($payment, 'callback');
        } catch (RuleViolation) {
            $outcome = 'unreachable';
        }
        $payment->refresh();

        return match (true) {
            $payment->status === PaymentStatus::Succeeded => redirect()->route('portal.payments.show', $payment)->with('status', 'Thank you. Your payment has been received.'),
            $payment->status === PaymentStatus::NeedsReview => redirect()->route('portal.invoices.show', $payment->invoice_id)->with('status', 'Your payment was received but did not match the amount expected. Our finance team will check it and contact you.'),
            $payment->status === PaymentStatus::Failed => redirect()->route('portal.invoices.show', $payment->invoice_id)->withErrors(['pay' => 'The payment did not go through. You have not been charged by us; you can try again.']),
            default => redirect()->route('portal.invoices.show', $payment->invoice_id)->with('status', $outcome === 'unreachable'
                ? 'We could not confirm the payment with Paystack just now. We will keep checking; you do not need to pay again.'
                : 'The payment is not complete yet. If you finished paying, we will confirm it shortly; you do not need to pay again.'),
        };
    }

    public function transfer(Request $request, Invoice $invoice, Payments $payments): RedirectResponse
    {
        Gate::authorize('payAsClient', $invoice);
        $data = $request->validate([
            'evidence' => ['required', ...UploadGuard::rules()],
            'amount' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'bank_reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'evidence.required' => 'Attach the transfer receipt or bank confirmation.',
            'evidence.extensions' => 'Upload a PDF, Word (.docx) or image file (JPG, PNG, WebP).',
            'evidence.max' => 'Files must be '.intdiv(UploadGuard::MAX_KILOBYTES, 1024).' MB or smaller.',
            'amount.regex' => 'Enter the amount as a number, for example 150000 or 150000.50.',
        ]);

        try {
            $payments->submitTransfer($invoice, $request->file('evidence'), Money::parse($data['amount']), $data['paid_on'],
                $data['bank_reference'] ?? null, $data['note'] ?? null, $request->user());
        } catch (RuleViolation $e) {
            return back()->withInput()->withErrors(['transfer' => $e->getMessage()]);
        }

        return back()->with('status', 'Thank you. We have your transfer details and will confirm once the money reaches our account. Until then the invoice remains unpaid.');
    }

    public function receipt(Payment $payment): View
    {
        Gate::authorize('viewReceiptAsClient', $payment);
        $payment->load(['invoice:id,reference,title,currency', 'client:id,reference,display_name', 'allocations']);

        return view('portal.receipt', [
            'payment' => $payment,
            'money' => fn (int $minor) => Money::format($minor, $payment->currency),
        ]);
    }

    public function funds(Request $request, ClientFunds $funds): View
    {
        $statements = [];
        foreach ($request->user()->clients()->orderBy('display_name')->get() as $client) {
            foreach ($funds->currenciesFor($client->id) as $currency) {
                $entries = $funds->statement($client->id, $currency);
                $statements[] = ['client' => $client, 'currency' => $currency, 'entries' => $entries, 'balance' => (int) ($entries->last()?->running_balance_minor ?? 0)];
            }
        }

        return view('portal.funds', ['statements' => $statements]);
    }
}
