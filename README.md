# Laravel Talkto

Laravel Talkto is a Laravel package for secure service-to-service command delivery. It helps one Laravel application send a signed command to another application, track that command through an outbox/inbox lifecycle, process it through an approved handler, and receive a signed result callback.

The package is intentionally generic. It provides the communication layer; each host application keeps its own domain rules, model lookups, data mapping, writes, and rollout decisions.

## What Problem It Solves

Cross-service commands are easy to send once and hard to operate safely. Laravel Talkto gives hosts a common structure for signed payloads, idempotent delivery, retries, attempts, status tracking, and callbacks without forcing domain code into the transport package.

## What It Does

- Builds and signs outgoing command envelopes.
- Verifies incoming signatures and timestamp tolerance.
- Stores outbox/inbox messages with statuses.
- Tracks attempts and lifecycle events.
- Supports idempotency and replay protection.
- Runs source action lifecycle hooks around outgoing messages.
- Resolves approved receiver handlers.
- Provides result callback contracts and examples.
- Exposes retry and recovery hooks for host-owned operations.
- Supports monitoring and reporting patterns through generic message state.

## What It Does Not Do

- It does not implement host business logic.
- It does not implement inventory/accounting/payment logic.
- It does not ship a UI by default.
- It does not decide production deployment, traffic, queue, or rollback policy.
- It does not replace host-specific handlers, mappers, permissions, or rollout checks.

## Installation

```bash
composer require mrezdev/laravel-talkto
php artisan vendor:publish --tag=laravel-talkto-config
php artisan vendor:publish --tag=laravel-talkto-migrations
```

The Laravel service provider is auto-discovered. Package routes and migrations are disabled by default so existing applications can adopt the package without duplicate endpoints or duplicate tables. Short publish tags are also available: `talkto-config` and `talkto-migrations`.

## Production Install Checklist

1. Install with Composer.
2. Publish config and migrations.
3. Review `config/talkto.php` ownership, peer names, secrets, routes, and migration settings.
4. Run package migrations only after confirming the host owns the `talkto_*` tables.
5. Run a queue worker for `SendTalktoMessage` and `ProcessIncomingTalktoMessage`.
6. Schedule `talkto:retry-failed`, and schedule or manually run `talkto:report`.
7. Keep DLQ reprocessing manual or operator-approved with `talkto:dlq-reprocess`.
8. Run one outgoing test, one incoming test, one duplicate `message_id` test, one retry dry-run, and one report command in a non-production environment.

## Publishing Config And Migrations

Publish config first and review ownership before enabling routes or migrations:

```bash
php artisan vendor:publish --tag=laravel-talkto-config
php artisan vendor:publish --tag=laravel-talkto-migrations
# or:
php artisan vendor:publish --tag=talkto-config
php artisan vendor:publish --tag=talkto-migrations
```

Keep `talkto.routes.enabled` and `talkto.migrations.enabled` false unless the host application has confirmed it wants package-owned routes or tables.

## Environment Variables

Common production variables include:

```dotenv
TALKTO_SERVICE=source-app
TALKTO_ROUTES_ENABLED=false
TALKTO_MIGRATIONS_ENABLED=false
TALKTO_REQUIRE_SIGNATURE=true
TALKTO_SIGNATURE_VERSION=v1
TALKTO_TIMESTAMP_TOLERANCE_SECONDS=300
TALKTO_RETRY_ENABLED=true
TALKTO_DEAD_LETTER_ENABLED=true
TALKTO_OBSERVABILITY_ENABLED=true
```

Use peer-specific environment variable names for URLs and secrets, for example `TALKTO_TARGET_APP_URL`, `TALKTO_TO_TARGET_APP_SECRET`, and `TALKTO_FROM_SOURCE_APP_SECRET`.

## Configure Peers

Each application names itself with `TALKTO_SERVICE` and configures explicit peers under `talkto.outgoing` and `talkto.incoming`.

```php
'outgoing' => [
    'target-app' => [
        'url' => env('TALKTO_TARGET_APP_URL'),
        'secret' => env('TALKTO_TO_TARGET_APP_SECRET'),
        'endpoint' => '/api/talkto/receive',
        'mode' => 'reliable',
    ],
],

'incoming' => [
    'source-app' => [
        'secret' => env('TALKTO_FROM_SOURCE_APP_SECRET'),
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

Never store real secrets in documentation or committed config.

## Queues And Scheduler

Run Laravel queue workers for the queues used by package jobs. A typical scheduler setup runs:

```bash
php artisan talkto:retry-failed --direction=all --limit=100
php artisan talkto:report --hours=24 --direction=all --limit=20
```

Use `talkto:retry-failed --dry-run` before enabling scheduled dispatch in a new environment. Use `talkto:dlq-reprocess --dry-run` before reprocessing dead letters.

## Basic Sender Example

Use `TalktoFlowFactory` when the host wants to run a source-side action and create an outgoing message in one lifecycle:

```php
$message = app(\Mrezdev\LaravelTalkto\Services\TalktoFlowFactory::class)
    ->flow('reserve-resource')
    ->to('target-service')
    ->command('domain.command')
    ->businessKey('business-key-123')
    ->idempotencyKey('command-123')
    ->run(fn () => [
        'payload' => ['id' => 123],
        'result' => ['source_saved' => true],
    ]);
```

Use `TalktoOutgoingMessageFactory` directly when the host already completed its source-side work:

```php
$message = app(\Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory::class)
    ->create(
        target: 'target-service',
        command: 'domain.command',
        payload: ['id' => 123],
        businessKey: 'business-key-123',
        idempotencyKey: 'command-123',
    );
```

## Outgoing Targets

Outgoing targets describe where a command should be delivered and how it should be signed. Existing `talkto.outgoing` config remains supported:

```php
'outgoing' => [
    'target-service' => [
        'url' => env('TALKTO_TARGET_SERVICE_URL'),
        'secret' => env('TALKTO_TO_TARGET_SERVICE_SECRET'),
        'endpoint' => '/api/talkto/receive',
        'headers' => [],
    ],
],
```

Hosts can also register or override targets from a service provider:

```php
app(\Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract::class)
    ->register('target-service', [
        'url' => 'https://target.test',
        'secret' => env('TALKTO_TO_TARGET_SERVICE_SECRET'),
    ]);
```

Delivery still runs through the existing queued send job. Retry/backoff and DLQ behavior continue to handle transport failures after the target is resolved.

## Incoming Handlers

Destination applications register incoming command handlers while keeping domain behavior in the host:

```php
namespace App\Talkto\Handlers;

use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

final class CreateOrderHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        $payload = $message->payload ?? [];
        // Host-owned validation, lookup, and write logic lives here.

        return TalktoIncomingCommandResult::succeeded(['processed' => true]);
    }
}
```

Register handlers in config:

```php
'incoming' => [
    'handlers' => [
        'order.create' => App\Talkto\Handlers\CreateOrderHandler::class,
    ],
    'unknown_command_strategy' => 'fail',
],
```

Or register from a host service provider:

```php
app(\Mrezdev\LaravelTalkto\Services\TalktoIncomingHandlerRegistry::class)
    ->register('order.create', App\Talkto\Handlers\CreateOrderHandler::class);
```

Hosts may also inject or resolve `Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract`; it shares the same registry instance as the concrete service.

Unknown commands fail by default so existing retry and DLQ behavior can handle them. Set `talkto.incoming.unknown_command_strategy` to `skip` only when the host explicitly wants unknown commands marked skipped. Handler execution happens in the queued incoming job and keeps the existing idempotency and status guards.

## Result Callback Example

The package currently exposes callback sender and receiver contracts. Concrete callback sender and receiver services may remain host-owned until the generic callback runtime phase.

After a host app binds `ResultCallbackSenderContract` to its own callback sender implementation, it can send a result back to the source after a destination command succeeds or fails:

```php
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

$result = TalktoIncomingCommandResult::succeeded(['processed' => true]);

app(ResultCallbackSenderContract::class)->sendResult($message, $result);
```

Concrete callback sender and receiver services may remain host-owned while implementing the package contracts.

## Retry And Backoff

Laravel Talkto stores retry state on `talkto_messages` and uses database state as the source of truth. Outgoing delivery retries are enabled by default; incoming handler retries are disabled by default because handlers may have host-owned side effects.

Key config values live under `talkto.retry`: `enabled`, `max_attempts`, `backoff_seconds`, `outgoing_enabled`, `incoming_enabled`, `retryable_statuses`, and `final_failure_status`.

Run due retries with:

```bash
php artisan talkto:retry-failed --direction=outgoing --limit=100
```

Use `--direction=incoming|outgoing|all` and `--dry-run` when inspecting work. In production, schedule the command from the host application scheduler, for example every minute, after queue workers and retry limits are reviewed.

## Dead Letter Queue

Final or exhausted failures can be preserved in `talkto_dead_letters` so operators can inspect them and optionally dispatch them for reprocessing later. Temporary retryable failures are not dead-lettered.

DLQ behavior is controlled under `talkto.dead_letter`: `enabled`, `table`, `auto_store_on_final_failure`, `allow_reprocess`, and `max_reprocess_attempts`.

Reprocess eligible rows with:

```bash
php artisan talkto:dlq-reprocess --direction=all --limit=50
```

Use `--id=`, `--message-id=`, `--dry-run`, or `--force` when needed. Reprocessing dispatches the existing outgoing or incoming jobs and relies on the same retry, status, and idempotency guards; it does not execute handlers inline and does not include a dashboard/UI.

If a reprocessing message reaches final failure again, the existing DLQ row is moved to `failed_reprocess` without resetting its reprocess count.

## Observability Reports

Laravel Talkto includes read-only observability services and a report command over the existing `talkto_messages`, `talkto_attempts`, `talkto_events`, and `talkto_dead_letters` tables. No dashboard/UI is included.

```bash
php artisan talkto:report --hours=24 --direction=all --limit=20
```

Use `--json` for machine-readable output, `--from=` and `--to=` for an explicit window, and `--direction=incoming|outgoing|all` to filter message metrics. The report includes message totals, status and direction counts, retry/DLQ counts, health warnings, and recent failures/events. It does not dispatch jobs or mutate rows.

Observability defaults live under `talkto.observability`: report window/limit settings and health thresholds for stale processing messages and due retry backlog grace.

## Public Extension Points

Stable package extension points include `TalktoIncomingCommandHandler`, `TalktoIncomingHandlerRegistryContract`, `TalktoOutgoingTargetRegistryContract`, `TalktoMetricsCollector`, `TalktoHealthChecker`, the `talkto:*` artisan commands, and the documented `talkto.*` config keys. Host applications should extend through these surfaces instead of depending on internal pipeline details.

## Internal Pipelines

The receive controller and queue jobs delegate orchestration to focused pipelines: `ReceiveIncomingTalktoMessagePipeline`, `ProcessIncomingTalktoMessagePipeline`, and `SendOutgoingTalktoMessagePipeline`. Public routes, jobs, retry behavior, DLQ behavior, and handler/target registries remain the external integration points.

## Adding Talkto To A New Laravel Service

Use the onboarding kit when a new Laravel service adopts Laravel Talkto:

- `docs/new-service-onboarding.md` for the full service checklist.
- `docs/local-http-e2e-template.md` for a two-service local test pattern.
- `docs/command-contract-template.md` for command names, payloads, idempotency, and handler contracts.
- `docs/callback-contract-template.md` for result callback structure, verification, and retry behavior.
- `docs/recovery-monitoring-template.md` for retryability, recovery actions, redaction, and access policy.
- `docs/production-rollout-template.md` for disabled deploys, rollout gates, rollback, monitoring, and cutover.

Generic copy/paste examples live under `stubs/host/`. They are intentionally not auto-installed; each host must review names, routes, queues, secrets, and database ownership before copying anything.

## Testing And Local End-To-End

Package tests are designed for a package-local checkout:

```bash
cd packages/laravel-talkto
composer install
vendor/bin/pest
```

If `vendor/` is missing inside the package directory, the test runner will not exist. Do not run Composer network operations in a host phase unless that phase explicitly allows it.

For local end-to-end checks, use non-production services, non-production queues, testing databases, local URLs, and throwaway secrets. Verify the sender can create and sign a message, the receiver can verify and process it, and the source can accept the result callback.

## Security Notes

Laravel Talkto signs canonical message fields with HMAC SHA-256, verifies timestamps within a configured tolerance, hashes normalized payloads for tamper detection, enforces source and command allowlists, and supports required idempotency keys for replay protection.

Signature verification accepts `v1` and `v2` by default. Outgoing messages continue to use the backward-compatible `v1` signature unless `talkto.security.signature_version` is set to `v2`. Update both peers before forcing `talkto.security.accept_versions` to only `v2`. Unsupported outgoing signature versions fail clearly instead of silently downgrading.

Signed requests always require `X-Talkto-Timestamp` because both `v1` and `v2` signatures include it. `talkto.security.require_timestamp` only controls unsigned requests when `talkto.security.require_signature` is `false`.

`v2` signatures include the signature version, timestamp, optional nonce, message ID, source, target, command, and payload hash in the signed canonical string. `v2` outgoing headers include `X-Talkto-Signature-Version`, `X-Talkto-Timestamp`, `X-Talkto-Payload-Hash`, and a generated nonce header. Set `talkto.security.replay_protection.require_nonce_for_v2` only after all senders provide the nonce header.

Replay protection continues to rely on the existing `message_id` ledger and unique constraint; no separate nonce table is created. Tune `talkto.security.timestamp_tolerance_seconds` for clock skew between services.

Never log shared secrets, raw signature secrets, production payloads, or secret headers.

## Production Checklist

Read `docs/production-readiness.md` before enabling real traffic. Hosts should have pause controls, retry procedures, queue monitoring, callback monitoring, secret rotation plans, and rollback steps.

## Release Notes

Package releases should be tagged in Git; `composer.json` intentionally does not carry a static `version` field. The package remains proprietary until the project owner approves a public license and repository metadata.

## Repository / CI / Release

Use the repository preparation docs before extracting the package into a private repository:

- `docs/private-repository-setup.md` for private repository creation steps.
- `docs/ci.md` for GitHub Actions and local test parity.
- `docs/release-process.md` for private-first release tagging.
- `docs/versioning.md` for semantic versioning and compatibility policy.
- `docs/PUBLIC_API.md` for stable extension points.
- `docs/SMOKE_TESTS.md` for host-app release checks.
- `UPGRADE.md` for host upgrade notes.
- `RELEASE_CHECKLIST.md` for release verification.
- `docs/private-composer-installation.md` for path and private VCS installation examples.
- `docs/package-extraction-checklist.md` for the future extraction checklist.
- `docs/public-release-readiness.md` for blockers before any public release.

## Extensibility

The package exposes contracts for handlers, source actions, incoming results, result callback senders, and result callback receivers. Hosts can bind their own implementations while keeping package-owned transport behavior generic.

## Host App Responsibilities

Host applications own command naming, payload mapping, validation, model lookup, writes, callback side effects, dashboards, traffic enablement, and operational runbooks.

More detail is available in the `docs/` directory.
