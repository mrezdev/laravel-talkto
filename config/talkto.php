<?php

use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoNonce;
use Mrezdev\LaravelTalkto\Services\TalktoFlowBuilder;

return [
    'service' => env('TALKTO_SERVICE', 'app'),

    'aliases' => [
        // Optional host-defined short names for peer services:
        // 'peer' => 'peer-service',
    ],

    'models' => [
        'message' => TalktoMessage::class,
        'attempt' => TalktoAttempt::class,
        'event' => TalktoEvent::class,
        'dead_letter' => TalktoDeadLetter::class,
        'nonce' => TalktoNonce::class,
    ],

    'database' => [
        /*
         * Database connection used by Talkto models, panel queries,
         * retry/DLQ, trace, metrics, health, and future migrations.
         *
         * null means the Laravel default database connection.
         */
        'connection' => env('TALKTO_DB_CONNECTION'),

        'tables' => [
            'messages' => env('TALKTO_MESSAGES_TABLE', 'talkto_messages'),
            'attempts' => env('TALKTO_ATTEMPTS_TABLE', 'talkto_attempts'),
            'events' => env('TALKTO_EVENTS_TABLE', 'talkto_events'),
            'dead_letters' => env('TALKTO_DEAD_LETTERS_TABLE', 'talkto_dead_letters'),
            'nonces' => env('TALKTO_NONCES_TABLE', 'talkto_nonces'),
        ],
    ],

    'storage' => [
        /*
         * When multiple services share the same Talkto database, queued jobs
         * must only process rows owned by the current configured service.
         */
        'enforce_current_service' => env('TALKTO_ENFORCE_CURRENT_SERVICE_STORAGE_SCOPE', true),
    ],

    'security' => [
        // v2 is the default and recommended production signing mode. v1 is
        // legacy/manual opt-in only for rare interoperability, debugging, or
        // migration cases; new projects should not start with v1.
        'require_signature' => env('TALKTO_REQUIRE_SIGNATURE', true),
        'signature_version' => env('TALKTO_SIGNATURE_VERSION', 'v2'),
        'accept_versions' => (static function (): array {
            $versions = env('TALKTO_ACCEPT_SIGNATURE_VERSIONS');

            if (! is_string($versions) || trim($versions) === '') {
                return ['v2'];
            }

            return array_values(array_filter(
                array_map('trim', explode(',', $versions)),
                static fn (string $version): bool => $version !== ''
            ));
        })(),
        // Signed requests always require X-Talkto-Timestamp. require_timestamp
        // only controls unsigned requests when require_signature is false.
        'timestamp_tolerance_seconds' => (int) env('TALKTO_TIMESTAMP_TOLERANCE_SECONDS', 300),
        'require_timestamp' => env('TALKTO_REQUIRE_TIMESTAMP', true),
        'algorithm' => 'sha256',
        'replay_protection' => [
            'enabled' => env('TALKTO_REPLAY_PROTECTION_ENABLED', true),
            'use_message_id' => true,
            // Required by default for v2. Set TALKTO_REQUIRE_V2_NONCE=false
            // only for explicit legacy/manual compatibility work.
            'require_nonce_for_v2' => env('TALKTO_REQUIRE_V2_NONCE', true),
        ],
        'nonce_header' => 'X-Talkto-Nonce',
        'signature_version_header' => 'X-Talkto-Signature-Version',
        // Extra key names redacted by reports, traces, audit output, and safe
        // event excerpts. The built-in list already covers common secrets.
        'redacted_keys' => [],
    ],

    'http' => [
        'timeout_seconds' => (int) env('TALKTO_HTTP_TIMEOUT_SECONDS', 20),
    ],

    'callbacks' => [
        'enabled' => env('TALKTO_CALLBACKS_ENABLED', true),
        // Automatically queue durable result callbacks after incoming handling.
        'auto_dispatch' => env('TALKTO_CALLBACKS_AUTO_DISPATCH', true),
        'command' => env('TALKTO_CALLBACK_COMMAND', 'talkto.result'),
        'endpoint' => env('TALKTO_CALLBACK_ENDPOINT', '/api/talkto/callback'),
        'timeout_seconds' => (int) env('TALKTO_CALLBACK_TIMEOUT_SECONDS', env('TALKTO_HTTP_TIMEOUT_SECONDS', 20)),
    ],

    'migrations' => [
        // Disabled by default so hosts can publish and review table ownership.
        'enabled' => env('TALKTO_MIGRATIONS_ENABLED', false),
    ],

    'routes' => [
        // Disabled by default so existing apps can keep their own receive route.
        // When enabled, the default middleware uses Laravel's named Talkto
        // throttle. Set TALKTO_ROUTE_MIDDLEWARE to a comma-separated list to
        // fully override the route middleware stack.
        'enabled' => env('TALKTO_ROUTES_ENABLED', false),
        'prefix' => env('TALKTO_ROUTES_PREFIX', 'api'),
        'middleware' => (static function (): array {
            $middleware = env('TALKTO_ROUTE_MIDDLEWARE');

            if (is_string($middleware) && trim($middleware) !== '') {
                return array_values(array_filter(
                    array_map('trim', explode(',', $middleware)),
                    static fn (string $name): bool => $name !== ''
                ));
            }

            $default = ['api'];

            if (env('TALKTO_RATE_LIMIT_ENABLED', true)) {
                $limiterName = env('TALKTO_RATE_LIMIT_NAME', 'talkto');
                $default[] = 'throttle:'.(is_string($limiterName) && $limiterName !== '' ? $limiterName : 'talkto');
            }

            return $default;
        })(),
        'rate_limit' => [
            'enabled' => env('TALKTO_RATE_LIMIT_ENABLED', true),
            'name' => env('TALKTO_RATE_LIMIT_NAME', 'talkto'),
            'max_attempts' => (int) env('TALKTO_RATE_LIMIT_MAX_ATTEMPTS', 120),
            'decay_minutes' => (int) env('TALKTO_RATE_LIMIT_DECAY_MINUTES', 1),
        ],
        'receive_uri' => env('TALKTO_RECEIVE_URI', 'talkto/receive'),
        'receive_name' => env('TALKTO_RECEIVE_NAME', 'talkto.receive'),
        'callback_uri' => env('TALKTO_CALLBACK_URI', 'talkto/callback'),
        'callback_name' => env('TALKTO_CALLBACK_NAME', 'talkto.callback'),
    ],

    'jobs' => [
        'send_message' => SendTalktoMessage::class,
        'process_incoming' => ProcessIncomingTalktoMessage::class,
    ],

    'builders' => [
        'flow' => TalktoFlowBuilder::class,
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
        'jitter_seconds' => 0,
        'directions' => [
            // Optional per-direction overrides. Global values above remain the
            // base; direction values override only the keys they define.
            // 'outgoing' => [
            //     'enabled' => true,
            //     'max_attempts' => 5,
            //     'backoff_seconds' => [10, 30, 60, 120, 300],
            // ],
            // 'incoming' => [
            //     'enabled' => false,
            // ],
        ],
        'targets' => [
            // Optional peer overrides. Outgoing uses target_service; incoming
            // uses source_service.
            // 'peer-service' => [
            //     'max_attempts' => 3,
            //     'backoff_seconds' => [30, 120, 300],
            // ],
        ],
        'commands' => [
            // Optional command overrides. These have the highest config
            // precedence.
            // 'domain.command' => [
            //     'max_attempts' => 2,
            //     'backoff_seconds' => [60, 300],
            // ],
        ],
    ],

    'dead_letter' => [
        // Stores final failures only. The dead-letter table name is configured
        // at talkto.database.tables.dead_letters. Older published configs may
        // still contain talkto.dead_letter.table; that legacy path remains a
        // lower-priority runtime fallback only.
        'enabled' => env('TALKTO_DEAD_LETTER_ENABLED', true),
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

    'recovery' => [
        // Stale in-flight locks older than this can be recovered by
        // talkto:recover-stale. Operators can override per run.
        'stale_after_minutes' => (int) env('TALKTO_STALE_AFTER_MINUTES', 15),
    ],

    'retention' => [
        'messages_days' => (int) env('TALKTO_RETENTION_MESSAGES_DAYS', 90),
        'attempts_days' => (int) env('TALKTO_RETENTION_ATTEMPTS_DAYS', 90),
        'events_days' => (int) env('TALKTO_RETENTION_EVENTS_DAYS', 30),
        'dead_letters_days' => (int) env('TALKTO_RETENTION_DEAD_LETTERS_DAYS', 180),
        'nonces_days' => (int) env('TALKTO_RETENTION_NONCES_DAYS', 7),
    ],

    'panel' => [
        /*
         * Disabled by default. Enable the panel only for trusted/admin
         * operators and keep it behind host-owned authentication middleware.
         */
        'enabled' => env('TALKTO_PANEL_ENABLED', false),

        'route' => [
            'prefix' => env('TALKTO_PANEL_PREFIX', 'talkto'),
            'domain' => env('TALKTO_PANEL_DOMAIN'),
            /*
             * These middleware wrap every panel route, including POST action
             * routes. Keep auth or stricter admin middleware in production.
             */
            'middleware' => ['web', 'auth'],
            'name' => env('TALKTO_PANEL_ROUTE_NAME', 'talkto.panel.'),
        ],

        'authorization' => [
            'enabled' => env('TALKTO_PANEL_AUTHORIZATION_ENABLED', true),
            'gate' => env('TALKTO_PANEL_GATE', 'viewTalktoPanel'),
        ],

        'scope' => [
            /*
             * Keep the panel focused on rows involving this service by default.
             * Disable only for a trusted central observer panel.
             */
            'current_service_only' => env('TALKTO_PANEL_CURRENT_SERVICE_ONLY', true),
        ],

        'messages' => [
            /*
             * List pages use a small safe column set and do not load payloads
             * or response bodies. Detail/trace visibility is controlled below.
             *
             * Payload and response bodies can contain sensitive host data.
             * Keep both false unless operators are explicitly allowed to view
             * them. Redaction is a safety layer, not access control.
             */
            'per_page' => (int) env('TALKTO_PANEL_MESSAGES_PER_PAGE', 25),
            'show_payload' => env('TALKTO_PANEL_SHOW_PAYLOAD', false),
            'show_response' => env('TALKTO_PANEL_SHOW_RESPONSE', false),
            'redact_sensitive_values' => true,
            'redacted_keys' => [
                'authorization',
                'cookie',
                'x-api-key',
                'x-talkto-signature',
                'token',
                'password',
            ],
        ],

        'health' => [
            'window_minutes' => (int) env('TALKTO_PANEL_HEALTH_WINDOW_MINUTES', 60),
            'cache_seconds' => (int) env('TALKTO_PANEL_HEALTH_CACHE_SECONDS', 30),
            'active_checks' => [
                'enabled' => env('TALKTO_PANEL_ACTIVE_HEALTH_CHECKS_ENABLED', false),
                'timeout_seconds' => (int) env('TALKTO_PANEL_HEALTH_TIMEOUT_SECONDS', 3),
                'cache_seconds' => (int) env('TALKTO_PANEL_HEALTH_CACHE_SECONDS', 30),
                'allowed_methods' => ['GET', 'HEAD'],
            ],
        ],

        'views' => [
            'layout' => env('TALKTO_PANEL_LAYOUT', 'talkto::panel.layout'),
            'tailwind_cdn' => env('TALKTO_PANEL_TAILWIND_CDN', false),
        ],

        'actions' => [
            'retry_enabled' => env('TALKTO_PANEL_RETRY_ENABLED', true),
            'dead_letter_reprocess_enabled' => env('TALKTO_PANEL_DLQ_REPROCESS_ENABLED', true),
            'active_health_checks_enabled' => env('TALKTO_PANEL_ACTIVE_HEALTH_CHECKS_ENABLED', false),
        ],
    ],

    'outgoing' => [
        // Outgoing targets can also be registered programmatically through
        // TalktoOutgoingTargetRegistryContract. Programmatic targets override
        // config targets with the same name. The url and endpoint aliases
        // remain supported for base_url and receive_endpoint.
        // Preferred base URL example:
        // 'peer-service' => [
        //     'base_url' => env('TALKTO_PEER_SERVICE_URL'),
        //     'receive_endpoint' => env('TALKTO_PEER_SERVICE_RECEIVE_ENDPOINT', '/api/talkto/receive'),
        //     'callback_endpoint' => env('TALKTO_PEER_SERVICE_CALLBACK_ENDPOINT', '/api/talkto/callback'),
        //     'secret' => env('TALKTO_PEER_SERVICE_SECRET'),
        //     'headers' => [],
        //     'timeout' => 20,
        //     'mode' => env('TALKTO_PEER_SERVICE_MODE', 'reliable'),
        // ],
        // Alternative full URL example:
        // 'peer-service' => [
        //     'receive_url' => env('TALKTO_PEER_SERVICE_RECEIVE_URL'),
        //     'callback_url' => env('TALKTO_PEER_SERVICE_CALLBACK_URL'),
        //     'secret' => env('TALKTO_PEER_SERVICE_SECRET'),
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
        //     // Fail-closed: missing or empty allowed_commands rejects all
        //     // commands. Use allow_all_commands => true only for trusted
        //     // internal development cases.
        //     'allowed_commands' => [
        //         'domain.command' => [
        //             'driver' => 'none',
        //             'idempotency' => 'required',
        //         ],
        //         'talkto.result' => [
        //             'driver' => 'none',
        //         ],
        //     ],
        //     'allow_all_commands' => false,
        // ],
    ],
];
