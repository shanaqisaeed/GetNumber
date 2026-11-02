<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VAPID Keys
    |--------------------------------------------------------------------------
    |
    | These keys authenticate Web Push notifications sent from your server
    | to browser push services (Google, Mozilla, Apple).
    |
    */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL', '')),
        'public_key' => env('VAPID_PUBLIC_KEY', 'BNNQz50xDQJWqeaTeVaZpJENBY06yEnvFJ5Yg7z_ssKX0nWEfFYHfz0a21j95I_xHqzAp72yH84k7Q6IanNs7GA'),
        'private_key' => env('VAPID_PRIVATE_KEY', 'T_1ek5TJ1aot6rxGs8S-cQ6H8IZ5ZjjuYVQd7jspiao'),
    ],
];
