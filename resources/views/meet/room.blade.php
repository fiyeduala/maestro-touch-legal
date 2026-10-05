@php
    $tz = config('app.firm_timezone');
    $title = $result['title'] ?? 'Video call';
    $when = isset($result['starts_at']) ? $result['starts_at']->copy()->timezone($tz)->format('l j F Y, g:i a').' WAT' : null;
    $reason = $result['ok'] ? null : $result['reason'];
    $message = match ($reason) {
        null => null,
        'early' => 'This call opens at '.$result['opens_at']->copy()->timezone($tz)->format('g:i a').' WAT on '.$result['opens_at']->copy()->timezone($tz)->format('l j F').'. Please come back then; you can keep this page and refresh it.',
        'over' => 'This call has finished.',
        'cancelled' => 'This call has been cancelled.',
        'not_confirmed' => 'This consultation has not been confirmed yet. The firm will email you when it is.',
        'not_video' => 'This consultation is not a video call on this website. Please use the details in your confirmation email.',
        'not_invited' => 'You are not invited to this call. If you think that is wrong, please contact the firm.',
        'not_configured' => 'Video calls are not set up on this website yet. Please contact the firm to arrange another way to meet.',
        default => 'The video service could not be reached just now. Please refresh this page in a minute.',
    };
@endphp
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $result['ok'] ? $title : 'Video call' }} - Maestro Touch Legal</title>
    <link rel="icon" href="{{ url((string) \App\Domain\Operations\Settings::get('site.favicon_path')) }}">
    @vite(['resources/css/app.css'])
    <style>
        html, body { height: 100%; }
        #call { position: absolute; inset: 0; }
        #call iframe { width: 100%; height: 100%; border: 0; }
    </style>
</head>
<body class="flex h-full flex-col bg-white">
    <header class="flex items-center justify-between gap-4 border-b border-line px-4 py-3">
        <div class="flex min-w-0 items-center gap-3">
            <img src="{{ url((string) \App\Domain\Operations\Settings::get('site.favicon_path')) }}" alt="" class="h-8 w-8">
            <div class="min-w-0">
                <p class="truncate font-semibold text-ink">{{ $result['ok'] ? $title : 'Video call' }}</p>
                @if ($when)<p class="truncate text-sm text-body/80">{{ $when }}</p>@endif
            </div>
        </div>
        <a href="{{ $back }}" class="btn-outline">{{ $result['ok'] ? 'Leave' : 'Back' }}</a>
    </header>

    @if ($result['ok'])
        <main class="relative flex-1" aria-label="Video call">
            <div id="call"></div>
            <noscript><p class="p-6">The video call needs JavaScript. Please turn it on and reload this page.</p></noscript>
            <p id="call-error" class="hidden p-6"></p>
        </main>
        <script src="{{ $jsUrl }}" crossorigin="anonymous"></script>
        <script>
        (function () {
            const errorBox = document.getElementById('call-error');
            function fail(text) { errorBox.textContent = text; errorBox.classList.remove('hidden'); }
            if (!window.DailyIframe) { fail('The video call could not load. Check your internet connection and reload this page.'); return; }

            const frame = window.DailyIframe.createFrame(document.getElementById('call'), {
                showLeaveButton: true,
                showFullscreenButton: true,
                iframeStyle: { width: '100%', height: '100%', border: '0' },
            });
            frame.on('left-meeting', () => { window.location.href = @json($back); });
            frame.on('error', (e) => fail('The call stopped: ' + ((e && e.errorMsg) || 'unknown error') + '. Reload this page to rejoin.'));
            frame.join({ url: @json($result['url']), token: @json($result['token']), userName: @json($result['name']) })
                .catch(() => fail('Could not join the call. Reload this page to try again.'));
        })();
        </script>
    @else
        <main class="flex flex-1 items-center justify-center p-6">
            <div class="max-w-[560px] text-center">
                <h1 class="heading-3">{{ $reason === 'early' ? 'Not open yet' : 'You cannot join this call' }}</h1>
                <p class="mt-3">{{ $message }}</p>
            </div>
        </main>
    @endif
</body>
</html>
