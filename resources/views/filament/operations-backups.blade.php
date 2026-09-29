@php($tz = config('app.firm_timezone'))
<div style="display:grid;gap:1rem">
    <div class="rounded-lg p-3 text-sm bg-warning-50 text-warning-800 dark:bg-warning-400/10 dark:text-warning-300">
        These backups are kept on this hosting account. They help after a mistake, but not if the account itself is lost.
        A technical administrator should download a copy at least weekly and keep it somewhere else, with the backup password stored separately.
    </div>
    <dl style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr))">
        <div>
            <dt class="text-sm text-gray-500 dark:text-gray-400">Last backup</dt>
            <dd @class(['font-medium', 'text-danger-600 dark:text-danger-400' => $problem])>
                @if (! $configured)
                    Not set up – add BACKUP_PASSWORD to .env
                @elseif (! $last)
                    None yet
                @elseif (! ($last['ok'] ?? false))
                    Failed {{ \Illuminate\Support\Carbon::parse($last['at'])->timezone($tz)->format('j M Y, H:i') }} WAT
                    <br><span class="text-sm">{{ $last['error'] ?? '' }}</span>
                @else
                    {{ \Illuminate\Support\Carbon::parse($last['at'])->timezone($tz)->format('j M Y, H:i') }} WAT
                    <br><span class="text-sm">{{ $last['tables'] }} tables, {{ $last['rows'] }} rows, {{ $last['files'] }} files</span>
                    @if ($stale)<br><span class="text-sm">More than a day old – check the cron job.</span>@endif
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-sm text-gray-500 dark:text-gray-400">Backups on the server</dt>
            <dd class="font-medium">{{ $count }} ({{ $backupSize }}) · keeping the latest {{ $keep }}</dd>
        </div>
        <div>
            <dt class="text-sm text-gray-500 dark:text-gray-400">Free disk space</dt>
            <dd @class(['font-medium', 'text-danger-600 dark:text-danger-400' => $lowDisk])>{{ $free ?? 'Unknown' }}@if ($total) of {{ $total }}@endif
                <br><span class="text-sm font-normal">As the server reports it; the hosting plan's quota in cPanel may be lower.</span></dd>
        </div>
    </dl>
</div>
