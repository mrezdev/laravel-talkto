# Installation

Install Laravel Talkto from Packagist:

```bash
composer require mrezdev/laravel-talkto
```

Local path or VCS installation is only for package development or private testing. Normal host applications should use the Packagist package name above.

## Publish Package Files

The service provider exposes these publish tags:

- `laravel-talkto-config`
- `talkto-config`
- `laravel-talkto-migrations`
- `talkto-migrations`
- `talkto-panel-views`

Publish and review the config:

```bash
php artisan vendor:publish --tag=laravel-talkto-config
```

Publish migrations when the host app is ready to own the Talkto tables:

```bash
php artisan vendor:publish --tag=laravel-talkto-migrations
php artisan migrate
```

The short aliases are equivalent:

```bash
php artisan vendor:publish --tag=talkto-config
php artisan vendor:publish --tag=talkto-migrations
```

Publish panel views only when you need to customize the optional panel:

```bash
php artisan vendor:publish --tag=talkto-panel-views
```

Routes and migrations are disabled by default. Keep them disabled until the host application has checked route ownership, table ownership, and deployment order.

## Configure The Local Service

Set a stable machine-readable name for the current app:

```dotenv
TALKTO_SERVICE=website-service
```

Use a value that will not change across deployments. This value is signed into envelopes and used for storage scoping.

## Configure Outgoing Targets

In the source app, configure each destination under `talkto.outgoing`:

```php
'outgoing' => [
    'inventory-service' => [
        'url' => env('TALKTO_INVENTORY_URL'),
        'endpoint' => '/api/talkto/receive',
        'secret' => env('TALKTO_TO_INVENTORY_SECRET'),
        'callback_endpoint' => '/api/talkto/callback',
        'timeout' => 20,
    ],
],
```

Keep secrets in environment variables or a secret manager. Do not commit real shared secrets.

## Configure Incoming Sources

In the receiving app, configure each trusted source under `talkto.incoming`:

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
        ],
        'allow_all_commands' => false,
    ],
],
```

Incoming command authorization is fail-closed. Missing or empty `allowed_commands` rejects all commands for that source unless `allow_all_commands` is explicitly true. Do not use `allow_all_commands=true` in production.

## Configure V2 Security

New installs already default to the recommended v2 profile:

```dotenv
TALKTO_REQUIRE_SIGNATURE=true
TALKTO_SIGNATURE_VERSION=v2
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
TALKTO_REQUIRE_V2_NONCE=true
TALKTO_REPLAY_PROTECTION_ENABLED=true
```

Make sure the nonce migration has been published and run before receiving v2 traffic with nonce replay protection.

## Enable Package Routes If Needed

Package API routes are disabled by default:

```dotenv
TALKTO_ROUTES_ENABLED=false
```

Enable them only when the host app wants the package receive and callback endpoints:

```dotenv
TALKTO_ROUTES_ENABLED=true
TALKTO_ROUTES_PREFIX=api
TALKTO_RECEIVE_URI=talkto/receive
TALKTO_CALLBACK_URI=talkto/callback
```

The default route middleware is `api` plus `throttle:talkto` when rate limiting is enabled.

## Queue Workers

Run queue workers for asynchronous send, receive, retry, callback, and recovery work:

```bash
php artisan queue:work
```

Use your normal Laravel process manager and queue monitoring in production.

## First Checks

Run the package security audit in a host app before production:

```bash
php artisan talkto:security-audit
```

The compatibility PASS/WARN/FAIL audit command is also available:

```bash
php artisan talkto:audit-security
```

Then run one source-side send test, one receiver handler test, one duplicate `message_id` test, and one trace/report check in a non-production environment.
