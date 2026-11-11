<?php

return [
    'api_token' => [
        'missing' => 'A valid API access token was not provided in the request headers.',
        'invalid' => 'The provided API token is invalid, revoked, expired, or the associated account is inactive.',
        'insufficient_ability' => "The API token lacks the required ':ability' ability.",
    ],
    'account_status' => [
        'suspended' => 'Your user account has been suspended by system administration.',
        'inactive' => 'Your user account is currently inactive.',
    ],
    'access' => [
        'admin_only' => 'Unauthorized access. This area is reserved exclusively for system administrators.',
        'staff_only' => 'Unauthorized access. This area is restricted to support staff and administrators.',
    ],
    'auth' => [
        'email_not_verified' => 'Your email address is not verified. Please verify your email to access platform services.',
    ],
];