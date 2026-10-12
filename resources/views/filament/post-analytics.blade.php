@php
    use App\Filament\Resources\Comments\CommentResource;
    use Illuminate\Support\Carbon;
    $date = fn ($d) => $d ? Carbon::parse($d)->timezone($tz)->format('j M Y, H:i') : '—';
@endphp
<x-filament-panels::page>
    <style>
        .mtl-muted { color: var(--gray-500); }
        .dark .mtl-muted { color: var(--gray-400); }
        .mtl-table { width: 100%; text-align: left; font-size: .875rem; border-collapse: collapse; }
        .mtl-table th, .mtl-table td { padding: .5rem .75rem .5rem 0; vertical-align: top; }
        .mtl-table th { font-weight: 500; }
        .mtl-table tbody tr, .mtl-table > tr { border-top: 1px solid var(--gray-200); }
        .dark .mtl-table tbody tr, .dark .mtl-table > tr { border-top-color: rgb(255 255 255 / .1); }
        .mtl-table td:last-child, .mtl-table th:last-child { padding-right: 0; }
    </style>
    <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr))">
        @foreach ([
            ['Visits', $totals['views'], 'One per visitor, per day'],
            ['Reads', $totals['reads'], 'Reached the end, 20 seconds or more'],
            ['Visits, last 30 days', $totals['last30'], null],
            ['Signed-in clients', $totals['clients'], 'Named below'],
            ['Comments', $comments->count(), $comments->where('status', 'pending')->count().' awaiting review'],
        ] as [$label, $value, $hint])
            <x-filament::section>
                <p class="mtl-muted" style="font-size:.875rem">{{ $label }}</p>
                <p style="font-size:1.5rem;font-weight:600;line-height:2rem">{{ number_format($value) }}</p>
                @if ($hint)<p class="mtl-muted" style="font-size:.75rem">{{ $hint }}</p>@endif
            </x-filament::section>
        @endforeach
    </div>

    <p class="mtl-muted" style="font-size:.875rem">
        @if ($totals['first'])
            Counting since {{ Carbon::parse($totals['first'])->format('j F Y') }}.
        @else
            No visits recorded yet.
        @endif
        Visitors who are not signed in are counted but cannot be named. Staff and search-engine robots are not counted.
        @if ($totals['imported'])
            {{ number_format($totals['imported']) }} of the visits were imported from the server's access log; those are approximate and are not counted as reads.
        @endif
    </p>

    <x-filament::section heading="Visits by day, last 30 days">
        <div style="display:flex;align-items:flex-end;gap:2px;height:8rem" role="img" aria-label="Visits by day for the last 30 days">
            @foreach ($days as $day)
                <div title="{{ Carbon::parse($day['day'])->format('j M') }}: {{ $day['views'] }} visits, {{ $day['reads'] }} reads"
                     style="background:var(--primary-500);flex:1;min-height:1px;height:{{ round($day['views'] / $peak * 100) }}%;opacity:{{ $day['views'] ? 1 : .2 }}"></div>
            @endforeach
        </div>
        <div class="mtl-muted" style="font-size:.75rem;display:flex;justify-content:space-between;margin-top:.25rem">
            <span>{{ Carbon::parse($days->first()['day'])->format('j M') }}</span>
            <span>Today</span>
        </div>
    </x-filament::section>

    <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(18rem,1fr))">
        <x-filament::section heading="Signed-in clients who opened it">
            @if ($readers->isEmpty())
                <p class="mtl-muted" style="font-size:.875rem">No signed-in client has opened this post yet.</p>
            @else
                <table class="mtl-table">
                    <thead class="mtl-muted"><tr><th>Client</th><th>Last visit</th><th>Read</th></tr></thead>
                    <tbody>
                        @foreach ($readers as $reader)
                            <tr>
                                <td>{{ $reader['user']->name }}<br><span class="mtl-muted">{{ $reader['user']->email }}</span></td>
                                <td>{{ $date($reader['last']) }}@if ($reader['visits'] > 1)<br><span class="mtl-muted">on {{ $reader['visits'] }} days</span>@endif</td>
                                <td>{{ $reader['read'] ? 'Yes' : 'Not yet' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section heading="Where visitors came from">
            @if ($referrers->isEmpty())
                <p class="mtl-muted" style="font-size:.875rem">No visits from other sites yet. Direct visits, and apps that hide the link (WhatsApp, many email apps), do not show here.</p>
            @else
                <table class="mtl-table">
                    @foreach ($referrers as $host => $count)
                        <tr><td>{{ $host }}</td><td style="text-align:right">{{ number_format($count) }}</td></tr>
                    @endforeach
                </table>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section heading="Who commented">
        @if ($comments->isEmpty())
            <p class="mtl-muted" style="font-size:.875rem">No comments on this post.</p>
        @else
            <table class="mtl-table">
                <thead class="mtl-muted"><tr><th>Name</th><th>Email</th><th>Posted</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($comments as $comment)
                        <tr>
                            <td>{{ $comment->author_name }}</td>
                            <td>{{ $comment->author_email ?: '—' }}</td>
                            <td>{{ $date($comment->posted_at) }}</td>
                            <td>{{ CommentResource::STATUSES[$comment->status] ?? $comment->status }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p style="margin-top:.75rem;font-size:.875rem"><a style="color:var(--primary-600);text-decoration:underline" href="{{ CommentResource::getUrl() }}">Moderate comments</a></p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
