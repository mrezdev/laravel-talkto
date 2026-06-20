# Configuration

The package config is published as `config/talkto.php`. Defaults are conservative and generic.

## Service Name

`talkto.service` identifies the current Laravel app inside signed envelopes. Use a stable machine-readable name, such as `source-service` or `target-service`.

## Models

`talkto.models` lets a host application replace package Eloquent models with compatible subclasses. The configured classes must extend the package model classes.

## Routes And Migrations

`talkto.routes.enabled` and `talkto.migrations.enabled` are false by default, and the package service provider also fails closed if those keys are missing. Enable them only when the host application does not already provide the same tables or receive endpoint.

When package routes are enabled, the default route middleware is `api` plus Laravel's named Talkto throttle middleware, `throttle:talkto`. Set `TALKTO_ROUTE_MIDDLEWARE` to a comma-separated list only when the host wants to fully override the route middleware stack.

```dotenv
TALKTO_ROUTES_ENABLED=true
TALKTO_ROUTE_MIDDLEWARE=api,throttle:talkto
```

Route rate limiting is configured under `talkto.routes.rate_limit`:

```dotenv
TALKTO_RATE_LIMIT_ENABLED=true
TALKTO_RATE_LIMIT_NAME=talkto
TALKTO_RATE_LIMIT_MAX_ATTEMPTS=120
TALKTO_RATE_LIMIT_DECAY_MINUTES=1
```

Throttling helps reduce request volume, but it does not replace HMAC signatures, timestamp checks, replay protection, peer secrets, or command allowlists.

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
TALKTO_NONCES_TABLE=talkto_nonces
```

`talkto.database.tables.dead_letters` is the canonical dead-letter table config path. Older published configs may still contain `talkto.dead_letter.table`; when both are present, `talkto.database.tables.dead_letters` wins.

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
        // Missing or empty allowed_commands rejects all commands.
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

Incoming command authorization is fail-closed. A known source with no `allowed_commands`, an empty `allowed_commands` array, or a command missing from the allowlist is rejected with `command_not_allowed`.

Both indexed and associative allowlists are supported:

```php
'allowed_commands' => [
    'orders.mark-paid',
    'invoices.sync-status',
],

'allowed_commands' => [
    'orders.mark-paid' => ['driver' => 'handler'],
    'invoices.sync-status' => true,
],
```

Use `allow_all_commands => true` only for trusted internal development cases where every command from that source is intentionally accepted. `allow_all_commands => false` does not bypass the allowlist.

## Security

`talkto.security.signature_version` defaults to `v2`, and `talkto.security.accept_versions` defaults to `['v2']`. New projects should use v2-only signatures in production.

`talkto.security.timestamp_tolerance_seconds` should stay small enough to limit replay windows while allowing normal clock skew. The default is 300 seconds.

`talkto.security.replay_protection.require_nonce_for_v2` defaults to true. v2 nonces are covered by the signature and consumed by an independent nonce ledger that stores nonce hashes, not raw nonces, payloads, or responses. `message_id` idempotency prevents duplicate business execution; nonce replay protection prevents reuse of a signed request.

Recommended production config:

```dotenv
TALKTO_SIGNATURE_VERSION=v2
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
TALKTO_REQUIRE_V2_NONCE=true
```

Legacy/manual compatibility config:

```dotenv
TALKTO_SIGNATURE_VERSION=v1
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v1,v2
TALKTO_REQUIRE_V2_NONCE=false
```

Use v1, or accepting both v1 and v2, only for rare interoperability, debugging, or migration cases.

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

For a stricter new-integration profile, see [Production hardening](production-hardening.md).

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
