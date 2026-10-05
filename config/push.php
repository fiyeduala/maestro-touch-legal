<?php

/*
| Browser push notifications (D49). Generate the pair once with `php artisan mtl:vapid-keys` and put both
| lines in the server .env. Replacing the pair later stops every existing subscription, so everyone has
| to turn alerts on again. The public key goes to the browser; the private key stays in .env.
| With no keys, notifications still appear in the app's bell and by email; only the push is skipped.
*/
return [
    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
        // Push services want a contact for the sender: the site address by default.
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'https://mtouchlegal.com')),
    ],

    // Seconds to wait for the push services. Pushes are sent during the request that caused them.
    'timeout' => (int) env('PUSH_TIMEOUT', 5),
];
