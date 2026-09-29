@php($tz = config('app.firm_timezone'))
<dl style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr))">
    <div>
        <dt class="text-sm text-gray-500 dark:text-gray-400">Mode</dt>
        <dd @class(['font-medium', 'text-danger-600 dark:text-danger-400' => in_array($mode, [null, 'unknown'], true) || $liveRefused])>
            @switch($mode)
                @case('test') Test mode (no real money) @break
                @case('live') {{ $liveRefused ? 'Live key refused – this is not the production site, so online payment is switched off' : 'Live' }} @break
                @case('unknown') Key not recognised – check PAYSTACK_SECRET_KEY in .env @break
                @default Not configured – online payment is switched off
            @endswitch
            <br><span class="text-sm">Currencies: {{ $currencies ? implode(', ', $currencies) : 'none' }}</span>
        </dd>
    </div>
    <div>
        <dt class="text-sm text-gray-500 dark:text-gray-400">Last verified webhook</dt>
        <dd class="font-medium">
            {{ $lastWebhook ? $lastWebhook->timezone($tz)->format('j M Y, H:i').' WAT' : 'None received' }}
            @if ($mode && ! $lastWebhook)<br><span class="text-sm">Set the webhook URL in the Paystack dashboard to {{ $webhookUrl }}</span>@endif
        </dd>
    </div>
    <div>
        <dt class="text-sm text-gray-500 dark:text-gray-400">Online payments waiting</dt>
        <dd class="font-medium">{{ $pending }} pending · <span @class(['text-danger-600 dark:text-danger-400' => $needsReview])>{{ $needsReview }} need review</span></dd>
    </div>
    <div>
        <dt class="text-sm text-gray-500 dark:text-gray-400">Last reconciliation check</dt>
        <dd class="font-medium">{{ $lastReconcile ? $lastReconcile->timezone($tz)->format('j M Y, H:i').' WAT' : 'Never' }}</dd>
    </div>
</dl>
