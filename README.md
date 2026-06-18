# Talkto Reliable

Talkto Reliable is a Laravel package for secure service-to-service command delivery. It helps one Laravel application send a signed command to another application, track that command through an outbox/inbox lifecycle, process it through an approved handler, and receive a signed result callback.

The package is intentionally generic. It provides the communication layer; each host application keeps its own domain rules, model lookups, data mapping, writes, and rollout decisions.

## What Problem It Solves

Cross-service commands are easy to send once and hard to operate safely. Talkto Reliable gives hosts a common structure for signed payloads, idempotent delivery, retries, attempts, status tracking, and callbacks without forcing domain code into the transport package.

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
composer require ibake/talkto-reliable
php artisan vendor:publish --tag=talkto-reliable-config
php artisan vendor:publish --tag=talkto-reliable-migrations
```

The Laravel service provider is auto-discovered. Package routes and migrations are disabled by default so existing applications can adopt the package without duplicate endpoints or duplicate tables.

## Publishing Config And Migrations

Publish config first and review ownership before enabling routes or migrations:

```bash
php artisan vendor:publish --tag=talkto-reliable-config
php artisan vendor:publish --tag=talkto-reliable-migrations
```

Keep `talkto.routes.enabled` and `talkto.migrations.enabled` false unless the host application has confirmed it wants package-owned routes or tables.

## Configure Peers

Each application names itself with `TALKTO_SERVICE` and configures explicit peers under `talkto.outgoing` and `talkto.incoming`.

```php
'outgoing' => [
    'target-service' => [
        'url' => env('TALKTO_TARGET_SERVICE_URL'),
        'secret' => env('TALKTO_TO_TARGET_SERVICE_SECRET'),
        'endpoint' => '/api/talkto/receive',
        'mode' => 'reliable',
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

Never store real secrets in documentation or committed config.

## Basic Sender Example

Use `TalktoFlowFactory` when the host wants to run a source-side action and create an outgoing message in one lifecycle:

```php
$message = app(\Ibake\TalktoReliable\Services\TalktoFlowFactory::class)
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
$message = app(\Ibake\TalktoReliable\Services\TalktoOutgoingMessageFactory::class)
    ->create(
        target: 'target-service',
        command: 'domain.command',
        payload: ['id' => 123],
        businessKey: 'business-key-123',
        idempotencyKey: 'command-123',
    );
```

## Basic Receiver Handler Example

Destination applications whitelist allowed source services and commands. A handler keeps domain behavior in the host:

```php
namespace App\Talkto\Handlers;

use Ibake\TalktoReliable\Contracts\CommandHandlerContract;
use Ibake\TalktoReliable\Contracts\IncomingCommandResultContract;
use Ibake\TalktoReliable\Services\TalktoIncomingCommandResult;

class DomainCommandHandler implements CommandHandlerContract
{
    public function handle(array $envelope, array $payload): IncomingCommandResultContract
    {
        // Host-owned lookup, validation, and write logic lives here.

        return TalktoIncomingCommandResult::succeeded([
            'processed' => true,
        ]);
    }
}
```

## Result Callback Example

Hosts can send a signed result back to the source after a destination command succeeds or fails:

```php
use Ibake\TalktoReliable\Contracts\ResultCallbackSenderContract;

app(ResultCallbackSenderContract::class)->sendResultCallback(
    messageId: $message->message_id,
    result: ['processed' => true],
);
```

Concrete callback sender and receiver services may remain host-owned while implementing the package contracts.

## Retry And Backoff

Talkto Reliable stores retry state on `talkto_messages` and uses database state as the source of truth. Outgoing delivery retries are enabled by default; incoming handler retries are disabled by default because handlers may have host-owned side effects.

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

## Adding Talkto To A New Laravel Service

Use the onboarding kit when a new Laravel service adopts Talkto Reliable:

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
cd packages/talkto-reliable
composer install
vendor/bin/pest
```

If `vendor/` is missing inside the package directory, the test runner will not exist. Do not run Composer network operations in a host phase unless that phase explicitly allows it.

For local end-to-end checks, use non-production services, non-production queues, testing databases, local URLs, and throwaway secrets. Verify the sender can create and sign a message, the receiver can verify and process it, and the source can accept the result callback.

## Security Notes

Talkto Reliable signs canonical message fields with HMAC SHA-256, verifies timestamps within a configured tolerance, hashes normalized payloads for tamper detection, enforces source and command allowlists, and supports required idempotency keys for replay protection.

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
- `docs/private-composer-installation.md` for path and private VCS installation examples.
- `docs/package-extraction-checklist.md` for the future extraction checklist.
- `docs/public-release-readiness.md` for blockers before any public release.

## Extensibility

The package exposes contracts for handlers, source actions, incoming results, result callback senders, and result callback receivers. Hosts can bind their own implementations while keeping package-owned transport behavior generic.

## Host App Responsibilities

Host applications own command naming, payload mapping, validation, model lookup, writes, callback side effects, dashboards, traffic enablement, and operational runbooks.

More detail is available in the `docs/` directory.
