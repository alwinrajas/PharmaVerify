<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | Origins are configuration, never a built-in default. Development machines
    | reach the API because their own .env names the origin, not because
    | localhost is permanently allowed in every deployment.
    |
    | CORS_ALLOWED_ORIGINS takes a comma-separated list when more than one
    | origin is needed; otherwise FRONTEND_URL is the single origin. If neither
    | is set no cross-origin request is allowed, which is the right way for this
    | to fail.
    */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', (string) env('FRONTEND_URL', '')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,
];
