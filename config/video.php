<?php

/*
| Video calls on Daily.co (D50). Until DAILY_API_KEY and DAILY_DOMAIN are set, meetings can still be
| scheduled, but the join page says video is not set up yet. Check the account with `php artisan mtl:video-check`.
|
| Recording is off: Daily's API cannot be told "recording: false", so it is never requested on a room or
| a token, and every room is read back. If Daily reports recording on (a default set in the Daily
| dashboard), it is logged and written to the audit log. Leave recording unset in the dashboard.
*/
return [
    'daily' => [
        // Daily dashboard → Developers → API key. .env only.
        'api_key' => env('DAILY_API_KEY'),

        // The Daily subdomain: 'yourteam' or 'yourteam.daily.co'.
        'domain' => env('DAILY_DOMAIN'),

        'api_url' => env('DAILY_API_URL', 'https://api.daily.co/v1'),

        // Daily Prebuilt, from a CDN. Pin a version here if preferred.
        'js_url' => env('DAILY_JS_URL', 'https://unpkg.com/@daily-co/daily-js'),

        // Up to this many people in one call.
        'max_participants' => (int) env('DAILY_MAX_PARTICIPANTS', 10),

        // The camera and microphone check before joining.
        'prejoin_ui' => (bool) env('DAILY_PREJOIN_UI', true),

        // Seconds. The join page waits for Daily; keep it short.
        'timeout' => (int) env('DAILY_TIMEOUT', 6),
    ],

    // The call opens this many minutes before the start and accepts new joins until this many minutes
    // after the scheduled end. Anyone already in the call is not cut off.
    'join_early_minutes' => (int) env('VIDEO_JOIN_EARLY_MINUTES', 15),
    'join_late_minutes' => (int) env('VIDEO_JOIN_LATE_MINUTES', 60),

    // A reminder (bell, push and email) this many minutes before a meeting starts.
    'reminder_minutes' => (int) env('MEETING_REMINDER_MINUTES', 15),
];
