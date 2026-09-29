@extends('portal.layout', ['title' => 'Funds Held for You'])

@section('portal')
    <p class="text-sm text-body/80" data-no-print><a class="text-brand hover:underline" href="{{ route('portal.invoices') }}">← Invoices</a>
        @if ($statements) · <button type="button" class="text-brand hover:underline" data-print>Print or save as PDF</button>@endif</p>

    <div class="mt-6 max-w-[960px] space-y-10" data-printable>
        @forelse ($statements as $statement)
            <section aria-label="{{ $statement['client']->display_name }} {{ $statement['currency'] }}">
                @if ($loop->first)@include('portal.partials.firm-letterhead')@endif
                <h2 class="heading-3 mt-4">{{ $statement['client']->display_name }} – {{ $statement['currency'] }} statement</h2>
                <p class="mt-1">Balance held: <strong class="text-ink">{{ \App\Support\Money::format($statement['balance'], $statement['currency']) }}</strong></p>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead><tr class="border-b border-line"><th class="py-2 pr-3">Date</th><th class="py-2 pr-3">Reference</th><th class="py-2 pr-3">Details</th><th class="py-2 pr-3 text-right">In</th><th class="py-2 pr-3 text-right">Out</th><th class="py-2 text-right">Balance</th></tr></thead>
                        <tbody>
                            @foreach ($statement['entries'] as $entry)
                                <tr class="border-b border-line">
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ $entry->occurred_on->format('j M Y') }}</td>
                                    <td class="py-2 pr-3">{{ $entry->reference }}</td>
                                    <td class="py-2 pr-3">{{ $entry->typeLabel() }}: {{ $entry->description }}</td>
                                    <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $entry->amount_minor > 0 ? \App\Support\Money::format($entry->amount_minor, $entry->currency) : '' }}</td>
                                    <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $entry->amount_minor < 0 ? \App\Support\Money::format(-$entry->amount_minor, $entry->currency) : '' }}</td>
                                    <td class="py-2 text-right whitespace-nowrap">{{ \App\Support\Money::format($entry->running_balance_minor, $entry->currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @empty
            <p>We are not holding any funds for you.</p>
        @endforelse
        @if ($statements)
            <p class="text-sm text-body/80">These are funds we hold on your behalf, kept separately from our fees. Nothing is paid out or deducted without your authorisation. If anything looks wrong, please send us a message.</p>
        @endif
    </div>
@endsection
