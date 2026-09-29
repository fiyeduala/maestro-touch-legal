<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Paystack (spec §10). The secret key lives only in .env; it is never stored in the database, shown in the
    // admin panel or written to logs. Paystack signs webhooks with the same secret key (HMAC-SHA512).
    // Test keys start sk_test_, live keys sk_live_; use a separate .env per environment.
    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'), // not needed for the redirect checkout; kept for completeness
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        // Only currencies the Paystack account is enabled for. USD must be enabled by Paystack first.
        'currencies' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAYSTACK_CURRENCIES', 'NGN'))))),
        // Optional CA bundle for PHP builds without one (DECISIONS D7). Certificate checks are never disabled.
        'ca_bundle' => env('PAYSTACK_CA_BUNDLE'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
