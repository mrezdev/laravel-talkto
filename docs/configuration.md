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
        ],
    ],
],
```

## Aliases

`talkto.aliases` is optional. Use it only for host-local shortcuts that resolve to canonical peer names.
