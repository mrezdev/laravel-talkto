# Configuration

Publish the config after installing from Packagist:

```bash
composer require mrezdev/laravel-talkto
php artisan vendor:publish --tag=laravel-talkto-config
```

This page explains the important config areas without duplicating the whole `config/talkto.php` file.

## Service Name

`talkto.service` identifies the current Laravel app inside signed envelopes and storage scoping.

```dotenv
TALKTO_SERVICE=website-service
```

Use a stable machine-readable value. Do not change it casually after messages exist.

## Publishing And Migrations

Supported publish tags:

- `laravel-talkto-config`
- `talkto-config`
- `laravel-talkto-migrations`
- `talkto-migrations`
- `talkto-panel-views`

Publish migrations and run them when the host app is ready to own the package tables:

```bash
php artisan vendor:publish --tag=laravel-talkto-migrations
php artisan migrate
```

Package migration loading is disabled by default:

```dotenv
TALKTO_MIGRATIONS_ENABLED=false
```

Set it to true only when the host intentionally wants the package service provider to load migrations directly.

## Routes

Package API routes are disabled by default:

```dotenv
TALKTO_ROUTES_ENABLED=false
```

When enabled, the package registers the receive and callback routes under `talkto.routes.prefix`, which defaults to `api`.

```dotenv
TALKTO_ROUTES_ENABLED=true
TALKTO_ROUTES_PREFIX=api
TALKTO_RECEIVE_URI=talkto/receive
TALKTO_CALLBACK_URI=talkto/callback
```

The default middleware is `api` plus `throttle:talkto` when route rate limiting is enabled.

```dotenv
TALKTO_RATE_LIMIT_ENABLED=true
TALKTO_RATE_LIMIT_NAME=talkto
TALKTO_RATE_LIMIT_MAX_ATTEMPTS=120
TALKTO_RATE_LIMIT_DECAY_MINUTES=1
```

## Outgoing Targets

Configure destination services under `talkto.outgoing`:

```php
'outgoing' => [
    'inventory-service' => [
        'url' => env('TALKTO_INVENTORY_URL'),
        'endpoint' => '/api/talkto/receive',
        'secret' => env('TALKTO_TO_INVENTORY_SECRET'),
        'callback_endpoint' => '/api/talkto/callback',
        'headers' => [],
        'timeout' => 20,
        'mode' => 'reliable',
    ],
],
```

Targets can also be registered programmatically through `TalktoOutgoingTargetRegistryContract`. Programmatic targets override config targets with the same name.

## Incoming Sources

Configure trusted source services under `talkto.incoming`:

```php
'incoming' => [
    'website-service' => [
        'secret' => env('TALKTO_FROM_WEBSITE_SECRET'),
        'allowed_commands' => [
            'catalog.reserve-stock' => [
                'driver' => 'handler',
                'handler' => App\Talkto\Handlers\ReserveStockHandler::class,
                'idempotency' => 'required',
            ],
            'talkto.result' => [
                'driver' => 'none',
            ],
        ],
        'allow_all_commands' => false,
    ],
],
```

Incoming command authorization is fail-closed. Missing or empty `allowed_commands` rejects all commands for that source. Do not use `allow_all_commands=true` in production.

Global incoming handlers can also be configured under `talkto.incoming.handlers` or registered through `TalktoIncomingHandlerRegistryContract`.

## Signature Settings

Recommended production values:

```dotenv
TALKTO_REQUIRE_SIGNATURE=true
TALKTO_SIGNATURE_VERSION=v2
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
TALKTO_TIMESTAMP_TOLERANCE_SECONDS=300
```

`signature_version` controls outgoing signing. `accept_versions` controls what receivers accept. v2 is the default and recommended production mode. v1 is legacy/manual opt-in only.

## Replay Protection And Nonces

Recommended production values:

```dotenv
TALKTO_REPLAY_PROTECTION_ENABLED=true
TALKTO_REQUIRE_V2_NONCE=true
TALKTO_NONCES_TABLE=talkto_nonces
TALKTO_RETENTION_NONCES_DAYS=7
```

The nonce ledger stores hashes/fingerprints only. Raw nonce values are not stored. Legitimate retries should keep the same `message_id` but use a new nonce.

## Storage And Model Overrides

Talkto models use the default database connection unless `TALKTO_DB_CONNECTION` is set.

```dotenv
TALKTO_DB_CONNECTION=talkto
TALKTO_MESSAGES_TABLE=talkto_messages
TALKTO_ATTEMPTS_TABLE=talkto_attempts
TALKTO_EVENTS_TABLE=talkto_events
TALKTO_DEAD_LETTERS_TABLE=talkto_dead_letters
TALKTO_NONCES_TABLE=talkto_nonces
```

Compatible model subclasses can be configured under `talkto.models`. The configured classes must extend the package model classes.

When several services share one Talkto database, keep current-service storage enforcement enabled:

```dotenv
TALKTO_ENFORCE_CURRENT_SERVICE_STORAGE_SCOPE=true
```

## Queues

Talkto uses queued jobs for outgoing sends and incoming processing. The job classes are configurable under `talkto.jobs`.

```bash
php artisan queue:work
```

Use normal Laravel queue process management in production.

## Retry

Global retry settings live under `talkto.retry`:

- `enabled`
- `max_attempts`
- `backoff_seconds`
- `outgoing_enabled`
- `incoming_enabled`
- `retryable_statuses`
- `final_failure_status`
- `retryable_http_statuses`
- `retry_server_errors`
- `jitter_seconds`

Overrides can be configured by direction, peer target/source, and command.

## Callbacks

Callbacks are enabled by default:

```dotenv
TALKTO_CALLBACKS_ENABLED=true
TALKTO_CALLBACKS_AUTO_DISPATCH=true
TALKTO_CALLBACK_COMMAND=talkto.result
TALKTO_CALLBACK_ENDPOINT=/api/talkto/callback
TALKTO_CALLBACK_TIMEOUT_SECONDS=20
```

`callbacks.enabled=false` disables callback creation and sending. `callbacks.auto_dispatch=false` disables automatic callback queueing after incoming processing, but manual `ResultCallbackSenderContract::sendResult()` can still be used when callbacks are enabled. The recommended default is `auto_dispatch=true`.

For result callbacks, every destination service that sends callbacks must configure the original source service in `talkto.outgoing` with a callback endpoint:

```php
'outgoing' => [
    'source-service' => [
        'url' => env('TALKTO_SOURCE_URL'),
        'secret' => env('TALKTO_TO_SOURCE_SECRET'),
        'callback_endpoint' => '/api/talkto/callback',
    ],
],
```

Every source service that receives callbacks must configure the destination service in `talkto.incoming` and allow the callback command:

```php
'incoming' => [
    'destination-service' => [
        'secret' => env('TALKTO_FROM_DESTINATION_SECRET'),
        'allowed_commands' => [
            'talkto.result' => [
                'driver' => 'none',
            ],
        ],
        'allow_all_commands' => false,
    ],
],
```

## Dead Letter Queue

Final failures can be stored in the DLQ:

```dotenv
TALKTO_DEAD_LETTER_ENABLED=true
TALKTO_DEAD_LETTER_AUTO_STORE=true
TALKTO_DEAD_LETTER_ALLOW_REPROCESS=true
TALKTO_DEAD_LETTER_MAX_REPROCESS_ATTEMPTS=3
```

Review before reprocessing:

```bash
php artisan talkto:dlq-reprocess --dry-run
```

## Panel

The optional panel is disabled by default:

```dotenv
TALKTO_PANEL_ENABLED=false
```

If enabled, keep `web` and `auth` or stronger admin middleware on the panel route stack, keep authorization enabled, and keep payload/response visibility off unless operators are allowed to inspect that data.

```dotenv
TALKTO_PANEL_AUTHORIZATION_ENABLED=true
TALKTO_PANEL_GATE=viewTalktoPanel
TALKTO_PANEL_SHOW_PAYLOAD=false
TALKTO_PANEL_SHOW_RESPONSE=false
```

Publish panel views only when customizing them:

```bash
php artisan vendor:publish --tag=talkto-panel-views
```

## Observability And Retention

Read-only reporting and health settings live under `talkto.observability`.

Retention settings:

```dotenv
TALKTO_RETENTION_MESSAGES_DAYS=90
TALKTO_RETENTION_ATTEMPTS_DAYS=90
TALKTO_RETENTION_EVENTS_DAYS=30
TALKTO_RETENTION_DEAD_LETTERS_DAYS=180
TALKTO_RETENTION_NONCES_DAYS=7
```

Preview pruning before deleting data:

```bash
php artisan talkto:prune --dry-run
```
