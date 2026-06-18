<?php

return [
    'service' => env('TALKTO_SERVICE', 'app'),

    'aliases' => [
        // Optional host-defined short names for peer services:
        // 'peer' => 'peer-service',
    ],

    'models' => [
        'message' => \Ibake\TalktoReliable\Models\TalktoMessage::class,
        'attempt' => \Ibake\TalktoReliable\Models\TalktoAttempt::class,
        'event' => \Ibake\TalktoReliable\Models\TalktoEvent::class,
        'dead_letter' => \Ibake\TalktoReliable\Models\TalktoDeadLetter::class,
    ],

    'security' => [
        // v1 remains the default for backward compatibility. Enable v2 only
        // after both peers understand the version and nonce headers.
        'require_signature' => env('TALKTO_REQUIRE_SIGNATURE', true),
        'signature_version' => env('TALKTO_SIGNATURE_VERSION', 'v1'),
        'accept_versions' => ['v1', 'v2'],
        // Signed requests always require X-Talkto-Timestamp. require_timestamp
        // only controls unsigned requests when require_signature is false.
        'timestamp_tolerance_seconds' => (int) env('TALKTO_TIMESTAMP_TOLERANCE_SECONDS', 300),
        'require_timestamp' => env('TALKTO_REQUIRE_TIMESTAMP', true),
        'algorithm' => 'sha256',
        'replay_protection' => [
            'enabled' => env('TALKTO_REPLAY_PROTECTION_ENABLED', true),
            'use_message_id' => true,
            'require_nonce_for_v2' => env('TALKTO_REQUIRE_V2_NONCE', false),
        ],
        'nonce_header' => 'X-Talkto-Nonce',
        'signature_version_header' => 'X-Talkto-Signature-Version',
    ],

    'http' => [
        'timeout_seconds' => (int) env('TALKTO_HTTP_TIMEOUT_SECONDS', 20),
    ],

    'migrations' => [
        // Disabled by default so hosts can publish and review table ownership.
        'enabled' => env('TALKTO_MIGRATIONS_ENABLED', false),
    ],

    'routes' => [
        // Disabled by default so existing apps can keep their own receive route.
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
        // Outgoing retries are enabled by default; incoming handler retries are
        // opt-in because handlers may perform host-owned side effects.
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

    'dead_letter' => [
        // Uses the configured table name and stores final failures only.
        'enabled' => env('TALKTO_DEAD_LETTER_ENABLED', true),
        'table' => 'talkto_dead_letters',
        'auto_store_on_final_failure' => env('TALKTO_DEAD_LETTER_AUTO_STORE', true),
        'allow_reprocess' => env('TALKTO_DEAD_LETTER_ALLOW_REPROCESS', true),
        'max_reprocess_attempts' => (int) env('TALKTO_DEAD_LETTER_MAX_REPROCESS_ATTEMPTS', 3),
    ],

    'observability' => [
        // Read-only report/health defaults. No dashboard or mutations.
        'enabled' => env('TALKTO_OBSERVABILITY_ENABLED', true),
        'report' => [
            'default_window_hours' => (int) env('TALKTO_REPORT_WINDOW_HOURS', 24),
            'default_limit' => (int) env('TALKTO_REPORT_LIMIT', 20),
        ],
        'health' => [
            'stale_processing_minutes' => (int) env('TALKTO_STALE_PROCESSING_MINUTES', 15),
            'due_retry_grace_minutes' => (int) env('TALKTO_DUE_RETRY_GRACE_MINUTES', 5),
        ],
    ],

    'outgoing' => [
        // Outgoing targets can also be registered programmatically through
        // TalktoOutgoingTargetRegistryContract. Programmatic targets override
        // config targets with the same name. Existing url/secret/endpoint keys
        // remain supported.
        // Example:
        // 'peer-service' => [
        //     'url' => env('TALKTO_PEER_SERVICE_URL'),
        //     'secret' => env('TALKTO_TO_PEER_SERVICE_SECRET'),
        //     'endpoint' => env('TALKTO_PEER_SERVICE_ENDPOINT', '/api/talkto/receive'),
        //     'headers' => [],
        //     'timeout' => 20,
        //     'mode' => env('TALKTO_PEER_SERVICE_MODE', 'reliable'),
        // ],
    ],

    'incoming' => [
        // Shared handler registry config. Hosts can also register handlers
        // programmatically through TalktoIncomingHandlerRegistryContract.
        'handlers' => [
            // 'domain.command' => App\Talkto\Handlers\DomainCommandHandler::class,
        ],

        'unknown_command_strategy' => env('TALKTO_UNKNOWN_COMMAND_STRATEGY', 'fail'),

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
