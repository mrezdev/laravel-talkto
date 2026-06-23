# Laravel Talkto

Laravel Talkto is a Laravel package for reliable service-to-service command delivery. It gives Laravel applications a shared transport layer for signed envelopes, outbox/inbox storage, retries, dead letters, idempotency, replay protection, result callbacks, and read-only observability.

The package owns the communication boundary. Your host applications still own business rules, validation, model lookup, permissions, dashboard policy, and what a callback result means.

## What It Does

Laravel Talkto helps one Laravel app send a command to another Laravel app, persist the lifecycle on both sides, verify the message, run an approved handler, and record enough state for retries and operations.

It is useful when direct HTTP calls have become hard to reason about, but a full event streaming platform would be too much.

## When To Use It

- You have Laravel services that exchange commands.
- You need HMAC signatures, timestamp checks, payload hashes, and nonce replay protection.
- You want durable outbox/inbox records for message lifecycle and operations.
- You need idempotency, retries, dead-letter handling, and trace/report commands.
- You want host-owned command handlers with package-owned transport behavior.

## When Not To Use It

- You only need an in-process service class call.
- You need a general pub/sub broker, stream processor, or event sourcing system.
- You want the package to own host business workflows or data mapping.
- You cannot run queues or persist package message tables.
- You are not ready to configure per-peer secrets and command allowlists.

## Installation

Install from Packagist:

```bash
composer require mrezdev/laravel-talkto
```

Publish the config and migrations when the host app is ready to review them:

```bash
php artisan vendor:publish --tag=laravel-talkto-config
php artisan vendor:publish --tag=laravel-talkto-migrations
php artisan migrate
```

Short publish tags are also available:

```bash
php artisan vendor:publish --tag=talkto-config
php artisan vendor:publish --tag=talkto-migrations
```

Panel Blade views are optional and publishable with:

```bash
php artisan vendor:publish --tag=talkto-panel-views
```

Local path or VCS installation is only for package development or private testing. Normal users should install with `composer require mrezdev/laravel-talkto`.

See [docs/installation.md](docs/installation.md) for the full install flow.

## 5-Minute Quickstart

Set a stable local service name:

```dotenv
TALKTO_SERVICE=website-service
TALKTO_MIGRATIONS_ENABLED=false
TALKTO_ROUTES_ENABLED=false
```

Configure the source app with an outgoing target:

```php
'outgoing' => [
    'inventory-service' => [
        'base_url' => env('TALKTO_INVENTORY_URL'),
        'receive_endpoint' => '/api/talkto/receive',
        'secret' => env('TALKTO_TO_INVENTORY_SECRET'),
        'callback_endpoint' => '/api/talkto/callback',
    ],
],
```

Configure the receiving app with an incoming source and command allowlist:

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

Run a queue worker in each app that processes Talkto jobs:

```bash
php artisan queue:work
```

## Secure Defaults

New installs use v2 signatures by default:

```dotenv
TALKTO_SIGNATURE_VERSION=v2
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
TALKTO_REQUIRE_V2_NONCE=true
```

v2 requests include a timestamp, payload hash, signature, and signed nonce. The nonce is consumed by a replay ledger that stores nonce hashes only, not raw nonce values. v1 remains available only as an explicit legacy/manual opt-in.

Do not disable signatures, nonce replay protection, or command allowlists in production. See [docs/security.md](docs/security.md) and [docs/production-hardening.md](docs/production-hardening.md).

Outgoing HTTP TLS certificate verification is also enabled by default. Use a CA bundle for private certificate authorities instead of disabling verification in production; `talkto:security-audit` and the panel surface risky SSL settings.

## Sending Commands

Use `TalktoOutgoingMessageFactory` to create a durable outgoing message:

```php
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;

$message = app(TalktoOutgoingMessageFactory::class)->create(
    target: 'inventory-service',
    command: 'catalog.reserve-stock',
    payload: ['sku' => 'sku-123', 'quantity' => 2],
    options: [
        'business_key' => 'cart-123',
        'idempotency_key' => 'website-service:catalog.reserve-stock:cart-123:v1',
    ],
);
```

Delivery is handled after the message is stored, usually by a queue worker and retry policy. Host apps decide when to create their own business record and how to correlate it with the Talkto message.

## Receiving Commands

Incoming commands are accepted only from configured sources and only when the command is allowed. A minimal handler implements `TalktoIncomingCommandHandler`:

```php
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

final class ReserveStockHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        $payload = $message->payload ?? [];

        if (! isset($payload['sku'])) {
            return TalktoIncomingCommandResult::failedFinal('Missing sku.');
        }

        return TalktoIncomingCommandResult::succeeded([
            'reserved' => true,
            'sku' => $payload['sku'],
        ]);
    }
}
```

The package verifies the envelope, stores the incoming row, applies idempotency rules, and dispatches or runs the configured handler. The handler owns the business behavior.

## Result Callbacks

Callbacks let the destination app report handler results back to the source app. The generic signed callback runtime uses the same signed-envelope model, including v2 nonce replay protection.

Result callbacks are durable: the destination stores an outgoing `talkto.result` callback message in `talkto_messages`, queues `SendTalktoMessage`, and delivers the callback through the normal outgoing send pipeline. Callback delivery can use the existing retry, DLQ, report, panel, and reprocess behavior.

Incoming processing auto-queues callbacks by default after a handler returns a `TalktoIncomingCommandResult`, so normal handlers only need to return the result:

```php
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

return TalktoIncomingCommandResult::succeeded(['reserved' => true]);
```

Manual `ResultCallbackSenderContract::sendResult($message, $result)` is still supported for advanced flows and returns queued delivery details such as `sent=false` and `queued=true`. Duplicate calls for the same original message/status reuse the deterministic durable callback message and suppress duplicate queue dispatch where the existing callback row/events show delivery is already queued or handled. The source app must configure the destination as an incoming source and allow the callback command, which defaults to `talkto.result`. Package callback routes depend on `talkto.routes.enabled` and `talkto.callbacks.enabled`; host-owned routes can call the receiver contract directly when package routes stay disabled.

## Retry, DLQ, And Observability

Retry state is stored on message records and processed with:

```bash
php artisan talkto:retry-failed --dry-run
```

Final failures can be stored in the dead-letter table and reviewed or reprocessed deliberately:

```bash
php artisan talkto:dlq-reprocess --dry-run
```

Read-only operational commands:

```bash
php artisan talkto:report --hours=24 --direction=all --limit=20
php artisan talkto:trace <message-id>
php artisan talkto:security-audit
php artisan talkto:audit-security
```

Use `talkto:security-audit` as the main detailed security audit command. `talkto:audit-security` is also registered as a PASS/WARN/FAIL compatibility audit command.

## Optional Panel

Laravel Talkto includes an optional Blade operations panel. It is disabled by default.

```dotenv
TALKTO_PANEL_ENABLED=false
```

If you enable it, keep every panel route behind host-owned auth/admin middleware and the configured gate. Keep payload and response visibility disabled unless operators are explicitly allowed to view that data. See [docs/panel.md](docs/panel.md).

## Testing And Local Validation

For a package checkout:

```bash
composer install
composer validate --strict
composer audit
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
```

For a host integration, also test one outgoing command, one incoming command, one duplicate `message_id`, one callback if used, one retry dry run, one trace/report command, and the security audit.

## Documentation Map

Start with [docs/README.md](docs/README.md).

Common next stops:

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Security](docs/security.md)
- [Production hardening](docs/production-hardening.md)
- [Architecture](docs/architecture.md)
- [Outgoing-only example](docs/examples/outgoing-only.md)
- [Incoming-only example](docs/examples/incoming-only.md)
- [Bidirectional callback example](docs/examples/bidirectional-callback.md)
- [Result callbacks](docs/result-callbacks.md)
- [Retry, DLQ, and recovery](docs/recovery-monitoring-template.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Public API](docs/PUBLIC_API.md)
- [Upgrade guide](UPGRADE.md)

## Security

Report suspected vulnerabilities through the repository security advisory workflow or the maintainer-approved public security contact for the published package. Do not include real secrets, production payloads, raw signatures, raw nonce values, cookies, authorization headers, or private service URLs in reports.

See [SECURITY.md](SECURITY.md).

## Versioning, Changelog, License, And Support

Laravel Talkto is licensed under the MIT license. Review [CHANGELOG.md](CHANGELOG.md), [UPGRADE.md](UPGRADE.md), and [SUPPORT.md](SUPPORT.md) before upgrading or opening support requests.
