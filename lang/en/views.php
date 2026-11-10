<?php

return [
    'errors' => [
        '400' => [
            'title' => 'Bad Request',
            'message' => 'The structure or parameters of the request sent to the server are invalid and cannot be processed.',
        ],
        '401' => [
            'title' => 'Authentication Required',
            'message' => 'To access this section or resource, please log in to your account first.',
            'login_button' => 'Log In to Account',
        ],
        '403' => [
            'title' => 'Access Forbidden',
            'message' => 'You do not have the required permissions or access level to view this section or perform this action.',
        ],
        '404' => [
            'title' => 'Page Not Found',
            'message' => 'The requested address does not exist on the system, has been deleted, or may have moved.',
        ],
        '405' => [
            'title' => 'Method Not Allowed',
            'message' => 'The HTTP method used for this request is not supported by the server for this route.',
        ],
        '408' => [
            'title' => 'Request Timeout',
            'message' => 'The connection and data exchange time with the server timed out. Please try again.',
        ],
        '419' => [
            'title' => 'Page Expired',
            'message' => 'Due to inactivity, your security token has expired. Please refresh the page.',
            'relogin_button' => 'Log In Again',
        ],
        '422' => [
            'title' => 'Unprocessable Content',
            'message' => 'The submitted information does not match validation criteria and cannot be processed.',
        ],
        '429' => [
            'title' => 'Too Many Requests',
            'message' => 'You have sent too many requests exceeding rate limits. Please wait a moment and try again.',
        ],
        '500' => [
            'title' => 'Internal Server Error',
            'message' => 'An unexpected server error occurred while processing your request. This incident has been logged.',
        ],
        '502' => [
            'title' => 'Bad Gateway',
            'message' => 'The server received an invalid response while communicating with upstream services.',
        ],
        '503' => [
            'title' => 'Service Unavailable',
            'message' => 'The system is undergoing maintenance or infrastructure updates. Please check back shortly.',
        ],
        '504' => [
            'title' => 'Gateway Timeout',
            'message' => 'The server did not receive a timely response from upstream infrastructure or gateways.',
        ],
        '505' => [
            'title' => 'HTTP Version Not Supported',
            'message' => 'The HTTP protocol version provided in the request is not supported by the web server.',
        ],
    ],
    'layout' => [
        'system_error' => 'System Error',
        'back_to_home' => 'Back to Home',
        'footer_security' => 'GetNumber Secure Enterprise Communications • Protected Connection',
    ],
    'head' => [
        'meta_description' => 'Comprehensive Enterprise Virtual Numbers Management, Dedicated Business Lines, Secure Messaging Hub, and API Gateway - GetNumber',
        'og_title' => 'Enterprise Virtual Numbers & Communications Platform',
        'og_description' => 'Comprehensive Enterprise Virtual Numbers Management, Dedicated Business Lines, Secure Messaging Hub, and API Gateway - GetNumber',
        'twitter_title' => 'Virtual Numbers Management Platform',
        'twitter_description' => 'Comprehensive Virtual Numbers Management, Dedicated Business Lines, and Secure Messaging',
        'jsonld_description' => 'Enterprise Virtual Number Management & Internal Messaging Gateway',
    ],
    'noscript' => [
        'title' => 'JavaScript Required',
        'description' => 'To use the system with full functionality, please enable JavaScript in your browser.',
    ],
];