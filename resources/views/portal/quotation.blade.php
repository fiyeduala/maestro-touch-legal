@extends('portal.layout', ['title' => 'Quotation '.$quotation->reference])

@section('portal')
    <p class="text-sm text-body/80"><a class="text-brand hover:underline" href="{{ route('portal.home') }}">← Overview</a> · Version {{ $version->version }} · {{ $quotation->status->label() }}
        @if ($version->valid_until) · valid until {{ $version->valid_until->format('j M Y') }}@endif</p>

    <section class="mt-6 max-w-[860px] space-y-6">
        <div>
            <h2 class="heading-3">Scope</h2>
            <p class="mt-2 whitespace-pre-line">{{ $version->scope }}</p>
        </div>
        @if ($version->exclusions)
            <div>
                <h2 class="heading-3">Not Included</h2>
                <p class="mt-2 whitespace-pre-line">{{ $version->exclusions }}</p>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b border-line"><th class="py-2">Item</th><th class="py-2">Type</th><th class="py-2 text-right">Qty</th><th class="py-2 text-right">Amount</th></tr></thead>
                <tbody>
                    @foreach ($version->lines as $line)
                        <tr class="border-b border-line">
                            <td class="py-2">{{ $line['description'] }}</td>
                            <td class="py-2">{{ ($line['kind'] ?? 'fee') === 'expense' ? 'Expense' : 'Fee' }}</td>
                            <td class="py-2 text-right">{{ $line['quantity'] ?? 1 }}</td>
                            <td class="py-2 text-right">{{ $money((int) $line['amount_minor']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td colspan="3" class="pt-3 text-right">Fees</td><td class="pt-3 text-right">{{ $money($version->fees_minor) }}</td></tr>
                    <tr><td colspan="3" class="text-right">Expenses</td><td class="text-right">{{ $money($version->expenses_minor) }}</td></tr>
                    <tr class="font-semibold text-ink"><td colspan="3" class="text-right">Total</td><td class="text-right">{{ $money($version->total_minor) }}</td></tr>
                </tfoot>
            </table>
        </div>

        @if ($version->payment_stages)
            <div>
                <h2 class="heading-3">Payment Stages</h2>
                <ul class="mt-2 list-disc pl-5">
                    @foreach ($version->payment_stages as $stage)
                        <li>{{ $stage['label'] }}: {{ $money((int) $stage['amount_minor']) }}@if (! empty($stage['due'])) ({{ $stage['due'] }})@endif</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($version->notes)
            <p class="whitespace-pre-line text-sm">{{ $version->notes }}</p>
        @endif

        @if ($canRespond)
            <form method="post" action="{{ route('portal.quotations.respond', $quotation) }}" class="space-y-4 rounded-md bg-tint p-5">
                @csrf
                <input type="hidden" name="version_id" value="{{ $version->id }}">
                <label class="flex items-start gap-2">
                    <input class="mt-1.5" type="checkbox" name="confirm" value="1" @checked(old('confirm'))>
                    <span>{{ $acceptStatement }}</span>
                </label>
                @error('confirm')<p class="form-error">{{ $message }}</p>@enderror
                <div>
                    <label class="form-label" for="comment">Comment (optional)</label>
                    <textarea id="comment" name="comment" rows="2" maxlength="2000" class="form-input">{{ old('comment') }}</textarea>
                </div>
                @error('decision')<p class="form-error">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-3">
                    <button type="submit" name="decision" value="accepted" class="btn">Accept Quotation</button>
                    <button type="submit" name="decision" value="declined" class="btn btn-outline">Decline</button>
                </div>
            </form>
        @endif
    </section>
@endsection
