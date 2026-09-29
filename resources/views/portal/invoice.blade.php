@extends('portal.layout', ['title' => 'Invoice '.$invoice->reference])

@section('portal')
    <p class="text-sm text-body/80" data-no-print><a class="text-brand hover:underline" href="{{ route('portal.invoices') }}">← Invoices</a>
        · <button type="button" class="text-brand hover:underline" data-print>Print or save as PDF</button></p>

    <article class="mt-6 max-w-[860px] space-y-6" data-printable>
        <div class="flex flex-wrap justify-between gap-6">
            @include('portal.partials.firm-letterhead')
            <dl class="text-sm">
                <div><dt class="inline font-semibold">Invoice:</dt> <dd class="inline">{{ $invoice->reference }}</dd></div>
                <div><dt class="inline font-semibold">Issued:</dt> <dd class="inline">{{ $invoice->issue_date?->format('j M Y') }}</dd></div>
                @if ($invoice->due_date)<div><dt class="inline font-semibold">Due:</dt> <dd class="inline">{{ $invoice->due_date->format('j M Y') }}</dd></div>@endif
                <div><dt class="inline font-semibold">Currency:</dt> <dd class="inline">{{ $invoice->currency }}</dd></div>
                <div><dt class="inline font-semibold">Status:</dt> <dd class="inline">{{ $invoice->status->clientLabel() }}@if ($invoice->isOverdue()) (overdue)@endif</dd></div>
            </dl>
        </div>

        <div class="text-sm">
            <p class="font-semibold">Billed to</p>
            <p>{{ $invoice->client->display_name }} ({{ $invoice->client->reference }})</p>
            @if ($invoice->matter)<p>Matter: {{ $invoice->matter->reference }} – {{ $invoice->matter->title }}</p>@endif
        </div>

        <h2 class="heading-3">{{ $invoice->title }}</h2>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b border-line"><th class="py-2">Description</th><th class="py-2">Type</th><th class="py-2 text-right">Qty</th><th class="py-2 text-right">Unit</th><th class="py-2 text-right">Amount</th></tr></thead>
                <tbody>
                    @foreach ($invoice->lines as $line)
                        <tr class="border-b border-line">
                            <td class="py-2">{{ $line->description }}</td>
                            <td class="py-2">{{ ucfirst($line->kind) }}</td>
                            <td class="py-2 text-right">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                            <td class="py-2 text-right whitespace-nowrap">{{ $money($line->unit_minor) }}</td>
                            <td class="py-2 text-right whitespace-nowrap">{{ $money($line->amount_minor) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td colspan="4" class="pt-3 text-right">Fees</td><td class="pt-3 text-right">{{ $money($invoice->fees_minor) }}</td></tr>
                    <tr><td colspan="4" class="text-right">Expenses</td><td class="text-right">{{ $money($invoice->expenses_minor) }}</td></tr>
                    @if ($invoice->tax_minor)<tr><td colspan="4" class="text-right">Tax</td><td class="text-right">{{ $money($invoice->tax_minor) }}</td></tr>@endif
                    <tr class="font-semibold text-ink"><td colspan="4" class="text-right">Total</td><td class="text-right">{{ $money($invoice->total_minor) }}</td></tr>
                    @if ($invoice->credited_minor)<tr><td colspan="4" class="text-right">Credited</td><td class="text-right">−{{ $money($invoice->credited_minor) }}</td></tr>@endif
                    <tr><td colspan="4" class="text-right">Paid</td><td class="text-right">−{{ $money($invoice->paid_minor) }}</td></tr>
                    <tr class="font-semibold text-ink"><td colspan="4" class="text-right">Balance due</td><td class="text-right">{{ $money($invoice->balanceMinor()) }}</td></tr>
                </tfoot>
            </table>
        </div>

        @if ($invoice->creditNotes->isNotEmpty())
            <div class="text-sm">
                <p class="font-semibold">Credit notes</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($invoice->creditNotes as $note)
                        <li>{{ $note->reference }} ({{ $note->created_at->format('j M Y') }}): {{ $money($note->amount_minor) }} – {{ $note->reason }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($invoice->notes)
            <p class="whitespace-pre-line text-sm">{{ $invoice->notes }}</p>
        @endif
    </article>

    @if ($payments->isNotEmpty())
        <section class="mt-8 max-w-[860px]" aria-labelledby="payments-heading" data-no-print>
            <h2 id="payments-heading" class="heading-3">Payments on this invoice</h2>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($payments as $payment)
                    <li>
                        {{ $payment->reference }} · {{ $payment->methodLabel() }} · {{ $money($payment->received_minor ?? $payment->amount_minor) }} · {{ $payment->status->clientLabel() }}
                        @if ($payment->status === \App\Domain\Billing\PaymentStatus::Succeeded) · <a class="text-brand hover:underline" href="{{ route('portal.payments.show', $payment) }}">Receipt</a>@endif
                        @if ($payment->status === \App\Domain\Billing\PaymentStatus::Rejected && $payment->rejection_reason)<br><span class="text-body/80">{{ $payment->rejection_reason }}</span>@endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($canPay)
        <section class="mt-8 max-w-[860px] space-y-6" aria-labelledby="pay-heading" data-no-print>
            <h2 id="pay-heading" class="heading-3">Pay this invoice</h2>
            @error('pay')<p class="form-error">{{ $message }}</p>@enderror

            @if ($online)
                <form method="post" action="{{ route('portal.invoices.pay', $invoice) }}" class="rounded-md bg-tint p-5">
                    @csrf
                    <p>Pay {{ $money($invoice->balanceMinor()) }} online through Paystack. You will be taken to Paystack's secure page and returned here afterwards.</p>
                    <p class="mt-2 text-sm text-body/80">You will be charged in {{ $invoice->currency }}. Some cards issued outside Nigeria may be declined by the card issuer; if that happens, please use bank transfer.</p>
                    <button type="submit" class="btn mt-4">Pay Online</button>
                </form>
            @endif

            @if ($bank)
                <div class="rounded-md border border-line p-5">
                    <h3 class="font-semibold text-ink">Bank transfer ({{ $invoice->currency }})</h3>
                    <dl class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]">
                        @foreach (['bank_name' => 'Bank', 'account_name' => 'Account name', 'account_number' => 'Account number', 'swift' => 'SWIFT/BIC', 'routing' => 'Routing number', 'bank_address' => 'Bank address', 'intermediary' => 'Intermediary bank'] as $key => $label)
                            @isset($bank[$key])<dt class="font-semibold">{{ $label }}</dt><dd class="whitespace-pre-line">{{ $bank[$key] }}</dd>@endisset
                        @endforeach
                    </dl>
                    @isset($bank['notes'])<p class="mt-2 whitespace-pre-line text-sm">{{ $bank['notes'] }}</p>@endisset
                    <p class="mt-3 text-sm">Please use <strong>{{ $invoice->reference }}</strong> as the payment reference. Pay in {{ $invoice->currency }} only; we cannot convert between currencies.</p>

                    <form method="post" action="{{ route('portal.invoices.transfer', $invoice) }}" enctype="multipart/form-data" class="mt-5 space-y-4">
                        @csrf
                        <p class="font-medium">Already paid? Tell us about your transfer.</p>
                        @error('transfer')<p class="form-error">{{ $message }}</p>@enderror
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="form-label" for="amount">Amount transferred ({{ $invoice->currency }})</label>
                                <input id="amount" name="amount" inputmode="decimal" required class="form-input" value="{{ old('amount', \App\Support\Money::toDecimal($invoice->balanceMinor())) }}">
                                @error('amount')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="form-label" for="paid_on">Date of transfer</label>
                                <input id="paid_on" name="paid_on" type="date" required max="{{ now()->toDateString() }}" class="form-input" value="{{ old('paid_on', now()->toDateString()) }}">
                                @error('paid_on')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="form-label" for="bank_reference">Bank reference (optional)</label>
                                <input id="bank_reference" name="bank_reference" maxlength="100" class="form-input" value="{{ old('bank_reference') }}">
                            </div>
                            <div>
                                <label class="form-label" for="evidence">Transfer receipt</label>
                                <input id="evidence" name="evidence" type="file" required accept="{{ $accept }}" class="form-input">
                                <p class="mt-1 text-xs text-body/80">PDF, Word or image, up to {{ $maxMb }} MB.</p>
                                @error('evidence')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <div>
                            <label class="form-label" for="note">Note (optional)</label>
                            <textarea id="note" name="note" rows="2" maxlength="2000" class="form-input">{{ old('note') }}</textarea>
                        </div>
                        <p class="text-sm text-body/80">Your invoice stays unpaid until our finance team confirms the money has reached our account.</p>
                        <button type="submit" class="btn btn-outline">Send Transfer Details</button>
                    </form>
                </div>
            @endif

            @if (! $online && ! $bank)
                <p>Payment options for {{ $invoice->currency }} invoices are not set up yet. Please send us a message and we will tell you how to pay.</p>
            @elseif (! $online)
                <p class="text-sm text-body/80">Online card payment is not available for {{ $invoice->currency }} invoices.</p>
            @endif
        </section>
    @endif
@endsection
