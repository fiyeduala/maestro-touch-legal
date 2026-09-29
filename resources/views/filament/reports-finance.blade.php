@php($m = fn (int $minor, string $c) => \App\Support\Money::format($minor, $c))
<div style="display:grid;gap:1.5rem">
    @foreach ($finance as $currency => $r)
        <div>
            <h3 class="text-base font-semibold">{{ $currency }}</h3>
            <div style="display:grid;gap:1.25rem;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));margin-top:.5rem">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">In the selected period</p>
                    <table class="text-sm" style="width:100%">
                        <tr><td style="padding:.2rem 0">Invoiced ({{ $r['invoiced_count'] }})</td><td style="text-align:right">{{ $m($r['invoiced'], $currency) }}</td></tr>
                        <tr><td style="padding:.2rem 0">Credit notes</td><td style="text-align:right">{{ $m($r['credited'], $currency) }}</td></tr>
                        <tr><td style="padding:.2rem 0">Received ({{ $r['received_count'] }})</td><td style="text-align:right">{{ $m($r['received'], $currency) }}</td></tr>
                        <tr><td style="padding:.2rem 0">of which refunded (confirmed)</td><td style="text-align:right">{{ $m($r['refunded'], $currency) }}</td></tr>
                    </table>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Unpaid invoices today, by age</p>
                    <table class="text-sm" style="width:100%">
                        @foreach (\App\Domain\Reporting\Reports::AGEING as $key => $label)
                            <tr @class(['text-danger-600 dark:text-danger-400' => $key !== 'current' && $r[$key] > 0])><td style="padding:.2rem 0">{{ $label }}</td><td style="text-align:right">{{ $m($r[$key], $currency) }}</td></tr>
                        @endforeach
                        <tr class="font-semibold"><td style="padding:.2rem 0">Outstanding ({{ $r['outstanding_count'] }})</td><td style="text-align:right">{{ $m($r['outstanding'], $currency) }}</td></tr>
                    </table>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Held or unconfirmed today</p>
                    <table class="text-sm" style="width:100%">
                        <tr><td style="padding:.2rem 0">Unapplied client credit</td><td style="text-align:right">{{ $m($r['unapplied_credit'], $currency) }}</td></tr>
                        <tr><td style="padding:.2rem 0">Transfers awaiting verification ({{ $r['awaiting_verification_count'] }}), not counted as received</td><td style="text-align:right">{{ $m($r['awaiting_verification'], $currency) }}</td></tr>
                        <tr><td style="padding:.2rem 0">Client funds held (not firm money)</td><td style="text-align:right">{{ $m($r['client_funds_held'], $currency) }}</td></tr>
                        <tr><td style="padding:.2rem 0">Quotations sent, awaiting reply ({{ $r['pipeline_count'] }}), estimates only</td><td style="text-align:right">{{ $m($r['pipeline'], $currency) }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
    <p class="text-xs text-gray-500 dark:text-gray-400">
        Invoiced: issued invoices by issue date. Received: verified payments by the date on the bank statement (manual) or the date Paystack reports (online).
        Outstanding: total less payments and credit notes on unpaid invoices, aged from the due date. With a service or team filter, only records linked to a matter are counted.
    </p>
</div>
