@php($tz = config('app.firm_timezone'))
@if ($deadlines->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">Nothing due in this period.</p>
@else
    <ul class="divide-y divide-gray-200 dark:divide-white/10">
        @foreach ($deadlines as $deadline)
            @php($overdue = $deadline->due_at->isPast())
            <li class="flex flex-wrap items-baseline justify-between gap-2 py-2 text-sm">
                <span>
                    <span class="font-medium">{{ $deadline->title }}</span>
                    <span class="text-gray-500 dark:text-gray-400">
                        · {{ \App\Models\MatterDeadline::KINDS[$deadline->kind] ?? $deadline->kind }} ·
                        <a class="underline" href="{{ \App\Filament\Pages\MyWork::matterUrl($deadline->matter) }}">{{ $deadline->matter->reference }}</a>
                    </span>
                </span>
                <span @class(['font-medium text-danger-600 dark:text-danger-400' => $overdue])>
                    {{ $deadline->due_at->timezone($tz)->format('j M Y H:i') }}@if ($overdue) (overdue)@endif
                </span>
            </li>
        @endforeach
    </ul>
@endif
