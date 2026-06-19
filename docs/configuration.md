# Configuration

The package config is published as `config/talkto.php`. Defaults are conservative and generic.

## Service Name

`talkto.service` identifies the current Laravel app inside signed envelopes. Use a stable machine-readable name, such as `source-service` or `target-service`.

## Models

`talkto.models` lets a host application replace package Eloquent models with compatible subclasses. The configured classes must extend the package model classes.

## Routes And Migrations

`talkto.routes.enabled` and `talkto.migrations.enabled` are false by default. Enable them only when the host application does not already provide the same tables or receive endpoint.

When enabling package routes for public traffic, include throttle or rate-limit middleware in `talkto.routes.middleware` or wrap the package controller in host-owned routes.

## Storage Connection And Tables

By default, Talkto models and migrations use Laravel's default database connection and the standard package table names.

To store Talkto data on a dedicated connection, configure the connection before running the package migrations:

```dotenv
TALKTO_DB_CONNECTION=talkto
```

Optional table names can also be configured:

```dotenv
TALKTO_MESSAGES_TABLE=talkto_messages
TALKTO_ATTEMPTS_TABLE=talkto_attempts
TALKTO_EVENTS_TABLE=talkto_events
TALKTO_DEAD_LETTERS_TABLE=talkto_dead_letters
```

Run Talkto migrations after the connection and table names are configured. If multiple services share one Talkto database, run the Talkto migrations once from one service or deployment job.

## Shared Talkto Database

Talkto can store several services in one Talkto database as long as each app has a stable `talkto.service` value. Outgoing rows are owned by the service in `source_service`; incoming rows are owned by the service in `target_service`.

Queued processing is scoped to the current service by default:

```dotenv
TALKTO_ENFORCE_CURRENT_SERVICE_STORAGE_SCOPE=true
```

With this default, an outgoing job only sends rows where `source_service` matches `talkto.service`, and an incoming processing job only handles rows where `target_service` matches `talkto.service`. Jobs that find another service's row log a warning and exit without mutating the row.

The panel is also scoped to rows involving the current service by default:

```dotenv
TALKTO_PANEL_CURRENT_SERVICE_ONLY=true
```

Set `TALKTO_PANEL_CURRENT_SERVICE_ONLY=false` only for a trusted central observer panel. Mutating actions still respect `TALKTO_ENFORCE_CURRENT_SERVICE_STORAGE_SCOPE`, so a central observer can inspect shared data without retrying or reprocessing another service's rows unless storage enforcement is explicitly disabled.

Passive connection health always compares configured peers to the current service. For outgoing peers it counts current-service-to-peer rows; for incoming peers it counts peer-to-current-service rows.

## Peers

Outgoing peer config contains the destination URL, endpoint, secret, and mode. Incoming peer config contains the source secret and allowed commands.

```php
'outgoing' => [
    'target-service' => [
        'url' => env('TALKTO_TARGET_SERVICE_URL'),
        'secret' => env('TALKTO_TO_TARGET_SERVICE_SECRET'),
        'endpoint' => '/api/talkto/receive',
        'callback_endpoint' => '/api/talkto/callback',
    ],
],

'incoming' => [
    'source-service' => [
        'secret' => env('TALKTO_FROM_SOURCE_SERVICE_SECRET'),
        'allowed_commands' => [
            'domain.command' => [
                'driver' => 'handler',
                'handler' => App\Talkto\Handlers\DomainCommandHandler::class,
                'idempotency' => 'required',
            ],
            'talkto.result' => [
                'driver' => 'none',
            ],
        ],
    ],
],
```

## Security

`talkto.security.signature_version` defaults to `v1` for backward compatibility. New peer integrations should prefer `v2` after both services understand the v2 signature headers. Receivers use `talkto.security.accept_versions` to control which versions are allowed.

`talkto.security.timestamp_tolerance_seconds` should stay small enough to limit replay windows while allowing normal clock skew. The default is 300 seconds.

`talkto.security.replay_protection.require_nonce_for_v2` remains false by default for compatibility. Enable it after all v2 peers send `X-Talkto-Nonce`.

`talkto.security.redacted_keys` lets hosts add extra key names that should be masked in traces, audit output, and safe event excerpts:

```php
'security' => [
    'redacted_keys' => [
        'custom_credential',
        'session_secret',
    ],
],
```

Use `php artisan talkto:security-audit` to review signature, timestamp, nonce, route middleware, peer secret, and allowed command settings without mutating state.

## Callbacks

`talkto.callbacks.command` defaults to `talkto.result`, and `talkto.callbacks.endpoint` defaults to `/api/talkto/callback`. Source apps should allow the callback command under the destination service in `talkto.incoming`. Destination apps should configure the source service in `talkto.outgoing` with a shared secret and callback endpoint.

## Retry Policy

Global retry keys remain the base policy: `talkto.retry.enabled`, `max_attempts`, `backoff_seconds`, direction enablement, retryable statuses, final failure status, retryable HTTP statuses, and server-error retry behavior.

Optional overrides are resolved in this order:

1. Global `talkto.retry` values.
2. `talkto.retry.directions.outgoing` or `talkto.retry.directions.incoming`.
3. `talkto.retry.targets.<peer>`, where outgoing uses `target_service` and incoming uses `source_service`.
4. `talkto.retry.commands.<command>`.

Supported override keys include `enabled`, `max_attempts`, `backoff_seconds`, `retryable_statuses`, `final_failure_status`, `retryable_http_statuses`, `retry_server_errors`, and `jitter_seconds`. A positive message-level `max_attempts` still wins over config.

`jitter_seconds` defaults to `0`, preserving deterministic backoff unless a host explicitly configures jitter.

## Aliases

`talkto.aliases` is optional. Use it only for host-local shortcuts that resolve to canonical peer names.
