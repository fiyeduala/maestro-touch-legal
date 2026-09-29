@php($tz = config('app.firm_timezone'))
<dl style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr))">
    <div>
        <dt class="text-sm text-gray-500 dark:text-gray-400">Scheduler last ran</dt>
        <dd @class(['font-medium', 'text-danger-600 dark:text-danger-400' => $stale])>
            {{ $lastRun ? $lastRun->timezone($tz)->format('j M Y, H:i').' WAT ('.$lastRun->diffForHumans().')' : 'Never' }}
            @if ($stale)<br><span class="text-sm">Check the cPanel cron job (every 5 minutes).</span>@endif
        </dd>
    </div>
    <div>
        <dt class="text-sm text-gray-500 dark:text-gray-400">Queue</dt>
        <dd class="font-medium">{{ $pendingJobs }} waiting · <span @class(['text-danger-600 dark:text-danger-400' => $failedJobs])>{{ $failedJobs }} failed</span></dd>
    </div>
    <div>
        <dt class="text-sm text-gray-500 dark:text-gray-400">Emails in the last 24 hours</dt>
        <dd class="font-medium">{{ $sent24h }} sent · <span @class(['text-danger-600 dark:text-danger-400' => $failed24h])>{{ $failed24h }} failed</span></dd>
    </div>
    <div>
        <dt class="text-sm text-gray-500 dark:text-gray-400">Mail transport</dt>
        <dd class="font-medium">
            {{ $mailer }}
            @if (in_array($mailer, ['log', 'array'], true))<br><span class="text-sm text-danger-600 dark:text-danger-400">Emails are not being delivered. Set the SMTP details in .env.</span>@endif
        </dd>
    </div>
</dl>

@if ($recentFailures->isNotEmpty())
    <h3 class="mt-6 text-sm font-medium">Recent email failures</h3>
    <ul class="mt-2 divide-y divide-gray-200 text-sm dark:divide-white/10">
        @foreach ($recentFailures as $failure)
            <li class="py-2">
                {{ $failure->created_at->timezone($tz)->format('j M H:i') }} · {{ $failure->type ?? 'Email' }} · {{ $failure->recipient }}
                @if ($failure->error)<br><span class="text-gray-500 dark:text-gray-400">{{ $failure->error }}</span>@endif
            </li>
        @endforeach
    </ul>
@endif
