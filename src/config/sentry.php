<?php

use App\Support\Sentry\Scrubber;

/*
 * Error monitoring (D-043). Off without SENTRY_LARAVEL_DSN. Errors only: no tracing, no profiling, no logs.
 * Everything that leaves passes App\Support\Sentry\Scrubber: the error class, the place in the code and the user id.
 */
return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // True when a DSN is set: the privacy page shows the Sentry row only then.
    'enabled' => filled(env('SENTRY_LARAVEL_DSN')),

    'environment' => env('APP_ENV', 'production'),

    // The tag of the image the code was built from
    'release' => env('IMAGE_TAG'),

    'send_default_pii' => false,

    'traces_sample_rate' => 0,

    'before_send' => [Scrubber::class, 'event'],

    // No breadcrumb carries text: SQL (it holds bindings), logs and queue payloads stay out.
    'breadcrumbs' => [
        'logs' => false,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'sql_transaction' => false,
        'queue_info' => false,
        'command_info' => false,
        'http_client_requests' => false,
        'notifications' => false,
    ],

    'tracing' => [
        'enabled' => false,
    ],
];
