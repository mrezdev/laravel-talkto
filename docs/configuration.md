# Configuration

The package config is published as `config/talkto.php`. Defaults are conservative and generic.

## Service Name

`talkto.service` identifies the current Laravel app inside signed envelopes. Use a stable machine-readable name, such as `source-service` or `target-service`.

## Models

`talkto.models` lets a host application replace package Eloquent models with compatible subclasses. The configured classes must extend the package model classes.

## Routes And Migrations

`talkto.routes.enabled` and `talkto.migrations.enabled` are false by default. Enable them only when the host application does not already provide the same tables or receive endpoint.

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
