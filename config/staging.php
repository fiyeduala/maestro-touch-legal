<?php

/*
 * Protection for any copy of the site that is not the real production site (DECISIONS D37).
 * With APP_ENV=staging every page asks for a username and password, nothing is indexed,
 * email only reaches STAGING_MAIL_TO (or is only logged), and live Paystack keys are refused.
 */
return [

    // On by default for APP_ENV=staging. Production ignores this setting.
    'protect' => (bool) env('STAGING_PROTECT', env('APP_ENV') === 'staging'),

    // Create the hash with: php artisan mtl:staging-password
    'user' => env('STAGING_USER'),
    'password_hash' => env('STAGING_PASSWORD_HASH'),

    // One address that receives every email sent by staging. Empty: emails are written to the log only.
    'mail_to' => env('STAGING_MAIL_TO'),

    // Paths that must work without the password: Paystack's signed webhook and the health check.
    'open_paths' => ['webhooks/paystack', 'up'],

];
