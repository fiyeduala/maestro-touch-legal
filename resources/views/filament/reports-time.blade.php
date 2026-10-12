@php use App\Models\TimeEntry; use App\Support\Money; @endphp
<div style="display:grid;gap:1.25rem;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr))">
    <div style="grid-column:1/-1;overflow-x:auto">
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Hours by staff member</p>
        <table class="text-sm" style="width:100%;min-width:32rem">
            <tr class="text-gray-500 dark:text-gray-400">
                <td style="padding:.2rem 0">Staff member</td><td style="text-align:right">Recorded</td><td style="text-align:right">Billable</td><td style="text-align:right">Billed</td><td style="text-align:right">Not yet billed</td>
            </tr>
            @forelse ($time['people'] as $row)
                <tr>
                    <td style="padding:.2rem 0">{{ $row['name'] }}</td>
                    <td style="text-align:right">{{ TimeEntry::duration($row['recorded']) }}</td>
                    <td style="text-align:right">{{ TimeEntry::duration($row['billable']) }}</td>
                    <td style="text-align:right">{{ TimeEntry::duration($row['billed']) }}</td>
                    <td style="text-align:right">{{ TimeEntry::duration($row['unbilled']) }}</td>
                </tr>
            @empty
                <tr><td style="padding:.2rem 0" class="text-gray-500 dark:text-gray-400" colspan="5">No time recorded in the period.</td></tr>
            @endforelse
        </table>
        <p class="text-xs text-gray-500 dark:text-gray-400" style="margin-top:.25rem">"Billed" counts time on issued invoices. Time on a draft is neither billed nor "not yet billed".</p>
    </div>
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Time billed on issued invoices</p>
        <table class="text-sm" style="width:100%">
            @foreach ($time['billed'] as $currency => $minor)
                <tr><td style="padding:.2rem 0">{{ $currency }}</td><td style="text-align:right">{{ Money::format($minor, $currency) }}</td></tr>
            @endforeach
        </table>
    </div>
</div>
