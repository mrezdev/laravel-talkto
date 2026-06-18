<?php

return [
    'service' => env('TALKTO_SERVICE', 'app'),

    'aliases' => [
        // Optional host-defined short names:
        // 'peer' => 'peer-service',
    ],

    'models' => [
        'message' => \Ibake\TalktoReliable\Models\TalktoMessage::class,
        'attempt' => \Ibake\TalktoReliable\Models\TalktoAttempt::class,
        'event' => \Ibake\TalktoReliable\Models\TalktoEvent::class,
    ],

    'security' => [
        'require_signature' => env('TALKTO_REQUIRE_SIGNATURE', true),
        'timestamp_tolerance_seconds' => (int) env('TALKTO_TIMESTAMP_TOLERANCE_SECONDS', 300),
        'algorithm' => 'sha256',
    ],

    'http' => [
        'timeout_seconds' => (int) env('TALKTO_HTTP_TIMEOUT_SECONDS', 20),
    ],

    'migrations' => [
        'enabled' => env('TALKTO_MIGRATIONS_ENABLED', false),
    ],

    'routes' => [
        'enabled' => env('TALKTO_ROUTES_ENABLED', false),
        'prefix' => env('TALKTO_ROUTES_PREFIX', 'api'),
        'middleware' => ['api'],
        'receive_uri' => env('TALKTO_RECEIVE_URI', 'talkto/receive'),
        'receive_name' => env('TALKTO_RECEIVE_NAME', 'talkto.receive'),
    ],

    'jobs' => [
        'send_message' => \Ibake\TalktoReliable\Jobs\SendTalktoMessage::class,
        'process_incoming' => \Ibake\TalktoReliable\Jobs\ProcessIncomingTalktoMessage::class,
    ],

    'builders' => [
        'flow' => \Ibake\TalktoReliable\Services\TalktoFlowBuilder::class,
    ],

    'retry' => [
        'enabled' => env('TALKTO_RETRY_ENABLED', true),
        'max_attempts' => (int) env('TALKTO_MAX_ATTEMPTS', 5),
        'backoff_seconds' => [10, 30, 60, 120, 300],
        'outgoing_enabled' => env('TALKTO_OUTGOING_RETRY_ENABLED', true),
        'incoming_enabled' => env('TALKTO_INCOMING_RETRY_ENABLED', false),
        'retryable_statuses' => ['failed_retryable'],
        'final_failure_status' => 'failed_final',
        'retryable_http_statuses' => [408, 425, 429],
        'retry_server_errors' => true,
    ],

    'outgoing' => [
        // Example:
        // 'peer-service' => [
        //     'url' => env('TALKTO_PEER_SERVICE_URL'),
        //     'secret' => env('TALKTO_TO_PEER_SERVICE_SECRET'),
        //     'endpoint' => env('TALKTO_PEER_SERVICE_ENDPOINT', '/api/talkto/receive'),
        //     'mode' => env('TALKTO_PEER_SERVICE_MODE', 'reliable'),
        // ],
    ],

    'incoming' => [
        // Example:
        // 'peer-service' => [
        //     'secret' => env('TALKTO_FROM_PEER_SERVICE_SECRET'),
        //     'allowed_commands' => [
        //         'domain.command' => [
        //             'driver' => 'none',
        //             'idempotency' => 'required',
        //         ],
        //     ],
        // ],
    ],
];
