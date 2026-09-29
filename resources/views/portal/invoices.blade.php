@extends('portal.layout', ['title' => 'Invoices & Payments'])

@section('portal')
    @error('pay')<p class="form-error mb-4">{{ $message }}</p>@enderror

    <section aria-labelledby="invoices-heading">
        <h2 id="invoices-heading" class="heading-3">Invoices</h2>
        @if ($invoices->isEmpty())
            <p class="mt-2">You have no invoices.</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b border-line"><th class="py-2 pr-3">Invoice</th><th class="py-2 pr-3">Matter</th><th class="py-2 pr-3">Issued</th><th class="py-2 pr-3">Due</th><th class="py-2 pr-3 text-right">Total</th><th class="py-2 pr-3 text-right">Balance</th><th class="py-2">Status</th></tr></thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr class="border-b border-line">
                                <td class="py-2 pr-3"><a class="font-medium text-brand hover:underline" href="{{ route('portal.invoices.show', $invoice) }}">{{ $invoice->reference }}</a><br><span class="text-body/80">{{ $invoice->title }}</span></td>
                                <td class="py-2 pr-3">{{ $invoice->matter?->reference ?? '—' }}</td>
                                <td class="py-2 pr-3 whitespace-nowrap">{{ $invoice->issue_date?->format('j M Y') }}</td>
                                <td class="py-2 pr-3 whitespace-nowrap">{{ $invoice->due_date?->format('j M Y') ?? '—' }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ \App\Support\Money::format($invoice->total_minor, $invoice->currency) }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ \App\Support\Money::format($invoice->balanceMinor(), $invoice->currency) }}</td>
                                <td class="py-2 whitespace-nowrap">{{ $invoice->status->clientLabel() }}@if ($invoice->isOverdue()) <span class="font-semibold text-brand">· Overdue</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-sm text-body/80">Amounts are shown in the currency of each invoice. Naira and dollar invoices are separate and are not added together.</p>
        @endif
    </section>

    <section class="mt-10" aria-labelledby="payments-heading">
        <h2 id="payments-heading" class="heading-3">Payments</h2>
        @if ($payments->isEmpty())
            <p class="mt-2">No payments yet.</p>
        @else
            <ul class="mt-3 space-y-2">
                @foreach ($payments as $payment)
                    <li>
                        @if ($payment->status === \App\Domain\Billing\PaymentStatus::Succeeded)
                            <a class="font-medium text-brand hover:underline" href="{{ route('portal.payments.show', $payment) }}">Receipt {{ $payment->reference }}</a>
                        @else
                            <span class="font-medium">{{ $payment->reference }}</span>
                        @endif
                        – {{ \App\Support\Money::format($payment->received_minor ?? $payment->amount_minor, $payment->currency) }}
                        @if ($payment->invoice) for {{ $payment->invoice->reference }}@endif
                        · {{ $payment->status->clientLabel() }}
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($hasFunds)
        <p class="mt-10"><a class="font-medium text-brand hover:underline" href="{{ route('portal.funds') }}">Funds we hold for you →</a></p>
    @endif
@endsection
