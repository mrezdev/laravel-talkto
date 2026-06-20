# Public API

This page defines the supported public surface for `mrezdev/laravel-talkto`.
Host applications may depend on the surfaces listed here. Code marked
`@internal`, implementation classes behind these contracts, and undocumented
helper details may change as the package evolves.

## Public API policy

Laravel Talkto keeps its public API small and explicit. A class, interface,
command, config key, publish tag, route behavior, or data object is public when
it is documented on this page or in the task-focused public docs linked from the
documentation map.

Public APIs should remain source-compatible where practical. Additive options,
new nullable fields, new commands, new config keys, and new data-object fields
may be introduced without breaking existing consumers.

## Versioning expectations while pre-1.0

The package is still pre-1.0. Documented public API changes will be called out in
the changelog and, when needed, upgrade notes. Internal code can change without a
dedicated deprecation path before 1.0.

Security defaults are intentionally conservative for new installs: signatures use
v2 by default, new installs accept v2 only, and v2 nonces are required by default.
v1 is legacy/manual opt-in only.

## Supported public surfaces

Supported public surfaces are:

- Composer package name: `mrezdev/laravel-talkto`.
- Installation command: `composer require mrezdev/laravel-talkto`.
- Service provider auto-discovery through `Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider`.
- Publish tags listed below.
- Config keys listed below.
- Artisan commands listed below.
- Contracts, data objects, result objects, services, factories, builders, models,
  events-as-records, and exceptions listed below.

## Installation and provider

Laravel discovers `Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider` through
Composer package discovery. Applications can still register the provider
manually if package discovery is disabled.

The provider is public as the package entry point. Its internal registration
order and container wiring are not public extension points.

## Publish tags

The supported publish tags are:

- `laravel-talkto-config`
- `talkto-config`
- `laravel-talkto-migrations`
- `talkto-migrations`
- `talkto-panel-views`

## Configuration keys

The supported top-level config keys under `talkto` are:

- `service`
- `aliases`
- `models`
- `database`
- `storage`
- `security`
- `http`
- `callbacks`
- `migrations`
- `routes`
- `jobs`
- `builders`
- `retry`
- `dead_letter`
- `observability`
- `recovery`
- `retention`
- `panel`
- `outgoing`
- `incoming`

Important supported subkeys include:

- `talkto.security.require_signature`
- `talkto.security.signature_version` (`TALKTO_SIGNATURE_VERSION=v2` by default)
- `talkto.security.accept_versions` (`TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2` by default)
- `talkto.security.timestamp_tolerance_seconds`
- `talkto.security.require_timestamp`
- `talkto.security.algorithm`
- `talkto.security.replay_protection.enabled`
- `talkto.security.replay_protection.use_message_id`
- `talkto.security.replay_protection.require_nonce_for_v2` (`TALKTO_REQUIRE_V2_NONCE=true` by default)
- `talkto.security.nonce_header`
- `talkto.security.signature_version_header`
- `talkto.security.redacted_keys`
- `talkto.routes.enabled`
- `talkto.routes.prefix`
- `talkto.routes.middleware`
- `talkto.routes.rate_limit.*`
- `talkto.routes.receive_uri`
- `talkto.routes.receive_name`
- `talkto.routes.callback_uri`
- `talkto.routes.callback_name`
- `talkto.callbacks.enabled`
- `talkto.callbacks.command`
- `talkto.callbacks.endpoint`
- `talkto.callbacks.timeout_seconds`
- `talkto.database.connection`
- `talkto.database.tables.messages`
- `talkto.database.tables.attempts`
- `talkto.database.tables.events`
- `talkto.database.tables.dead_letters`
- `talkto.database.tables.nonces`
- `talkto.models.message`
- `talkto.models.attempt`
- `talkto.models.event`
- `talkto.models.dead_letter`
- `talkto.models.nonce`
- `talkto.jobs.send_message`
- `talkto.jobs.process_incoming`
- `talkto.builders.flow`
- `talkto.retry.*`
- `talkto.dead_letter.*`
- `talkto.observability.*`
- `talkto.recovery.stale_after_minutes`
- `talkto.retention.*`
- `talkto.panel.*`
- `talkto.incoming.handlers`
- `talkto.incoming.unknown_command_strategy`
- `talkto.incoming.<source>.allowed_commands`
- `talkto.incoming.<source>.allow_all_commands`
- `talkto.incoming.<source>.secret`
- `talkto.outgoing.<target>.*`

## Artisan commands

The supported command names and public options are:

- `talkto:make-incoming {service} {talktoCommand} {--force} {--dry-run} {--base-path=} {--base-namespace=}`
- `talkto:make-integration {service} {talktoCommand} {--outgoing} {--incoming} {--transactional} {--force} {--dry-run} {--base-path=} {--base-namespace=}`
- `talkto:make-outgoing {service} {talktoCommand} {--force} {--dry-run} {--transactional} {--base-path=} {--base-namespace=}`
- `talkto:retry-failed {--direction=} {--limit=} {--dry-run}`
- `talkto:dlq-reprocess {--id=} {--message-id=} {--direction=} {--limit=} {--dry-run} {--force}`
- `talkto:report {--hours=} {--from=} {--to=} {--json} {--direction=} {--limit=}`
- `talkto:trace {message_id?} {--correlation} {--json} {--limit=} {--payload}`
- `talkto:security-audit {--json} {--fail-on=}`
- `talkto:audit-security {--json}`
- `talkto:prune {--type=} {--older-than=} {--dry-run} {--limit=}`
- `talkto:recover-stale {--dry-run} {--direction=} {--older-than=} {--limit=}`

Command output formatting may gain new fields or clearer wording. Scripts should
prefer `--json` where available.

## Public contracts

- `Mrezdev\LaravelTalkto\Contracts\CommandHandlerContract`
- `Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler`
- `Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract`
- `Mrezdev\LaravelTalkto\Contracts\SourceActionContract`
- `Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient`
- `Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract`
- `Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract`
- `Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract`
- `Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract`

`IncomingCommandResultContract` exposes `isSucceeded()`, `isRetryable()`,
`isSkipped()`, `errorClass()`, `errorMessage()`, `result()`, and `meta()`.

## Public DTOs, result objects, and data objects

- `Mrezdev\LaravelTalkto\Data\TalktoEnvelopeData`
- `Mrezdev\LaravelTalkto\Data\TalktoHttpResponse`
- `Mrezdev\LaravelTalkto\Data\TalktoIncomingCommandResultData`
- `Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData`
- `Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult`
- `Mrezdev\LaravelTalkto\Support\TalktoMetricsSnapshot`
- `Mrezdev\LaravelTalkto\Support\TalktoRetryDecision`
- `Mrezdev\LaravelTalkto\Support\TalktoSecurityAuditSnapshot`
- `Mrezdev\LaravelTalkto\Support\TalktoSecurityFinding`
- `Mrezdev\LaravelTalkto\Support\TalktoTraceSnapshot`

These objects provide stable names and stable existing keys. New keys may be
added over time.

## Public factories, builders, and services

- `Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory`
- `Mrezdev\LaravelTalkto\Services\TalktoFlowFactory`
- `Mrezdev\LaravelTalkto\Services\TalktoFlowBuilder`
- `Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult`
- `Mrezdev\LaravelTalkto\Services\TalktoIncomingHandlerRegistry`
- `Mrezdev\LaravelTalkto\Services\TalktoOutgoingTargetRegistry`
- `Mrezdev\LaravelTalkto\Services\TalktoOutgoingTarget`
- `Mrezdev\LaravelTalkto\Services\TalktoMetricsCollector`
- `Mrezdev\LaravelTalkto\Services\TalktoHealthChecker`
- `Mrezdev\LaravelTalkto\Services\TalktoTraceReporter`
- `Mrezdev\LaravelTalkto\Services\TalktoSecurityAuditor`
- `Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor`
- `Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher`
- `Mrezdev\LaravelTalkto\Services\TalktoSigner`
- `Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy`
- `Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue`

`TalktoPayloadHasher` and `TalktoSigner` are advanced security utilities for
diagnostics and custom tests. Normal applications should let the package build,
hash, sign, and verify envelopes through documented send/receive flows.

`TalktoDeadLetterQueue` exposes DLQ lifecycle helpers such as
`markReprocessedForMessage()`, `markFailedReprocess()`, and `markIgnored()`.

## Advanced extension points

- Replace outgoing HTTP transport by binding `TalktoHttpClient`.
- Register incoming handlers through config or `TalktoIncomingHandlerRegistryContract`.
- Register outgoing targets through config or `TalktoOutgoingTargetRegistryContract`.
- Implement `SourceActionContract` for transactional outgoing source work.
- Implement `ResultCallbackSenderContract` or `ResultCallbackReceiverContract`
  when a host needs custom callback behavior.
- Configure custom model classes through `talkto.models.*`; custom classes should
  extend the package base models:
  - `Mrezdev\LaravelTalkto\Models\TalktoMessage`
  - `Mrezdev\LaravelTalkto\Models\TalktoAttempt`
  - `Mrezdev\LaravelTalkto\Models\TalktoEvent`
  - `Mrezdev\LaravelTalkto\Models\TalktoDeadLetter`
  - `Mrezdev\LaravelTalkto\Models\TalktoNonce`

## Events and exceptions

Laravel Talkto stores message timeline rows in `TalktoEvent` records. No Laravel
event class is currently documented as public API.

Public exceptions that host apps may catch are:

- `Mrezdev\LaravelTalkto\Exceptions\TalktoException`
- `Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoSignatureException`
- `Mrezdev\LaravelTalkto\Exceptions\TalktoCommandNotAllowedException`
- `Mrezdev\LaravelTalkto\Exceptions\TalktoIdempotencyException`
- `Mrezdev\LaravelTalkto\Exceptions\TalktoPayloadHashMismatchException`
- `Mrezdev\LaravelTalkto\Exceptions\UnknownTalktoIncomingCommand`
- `Mrezdev\LaravelTalkto\Exceptions\UnknownTalktoOutgoingTarget`
- `Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoIncomingHandler`
- `Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoOutgoingTarget`

## What is internal

The following categories are internal unless a specific class is documented
above:

- HTTP and panel controllers.
- Queue jobs.
- Pipeline classes.
- Default implementations behind callback and HTTP contracts.
- Envelope builders, signature verifiers, nonce ledger internals, current-service
  guards, pruning/recovery internals, and command resolution internals.
- Panel query/action/presenter support classes.
- Scaffolding resolver/writer/renderer internals and scaffold result objects.
- Migration class internals and Blade view implementation details.
- Tests, test fixtures, and private helper methods.

Classes marked with `@internal` are not intended for host application extension
or direct use.

## Safe usage examples

Implement an incoming handler:

```php
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

final class CreateOrderHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        return TalktoIncomingCommandResult::succeeded([
            'accepted' => true,
        ]);
    }
}
```

Create an outgoing message:

```php
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;

$message = app(TalktoOutgoingMessageFactory::class)->create(
    target: 'billing',
    command: 'invoice.create',
    payload: ['invoice_id' => 123],
    options: ['idempotency_key' => 'invoice-123']
);
```

Override HTTP transport:

```php
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use App\Services\Talkto\AppTalktoHttpClient;

$this->app->bind(TalktoHttpClient::class, AppTalktoHttpClient::class);
```

## What not to depend on

Do not depend on:

- Controller, job, or pipeline class names.
- Private, protected, or undocumented methods.
- Internal envelope array construction details beyond documented data objects.
- Signature canonicalization internals.
- Nonce storage internals.
- Default callback sender/receiver implementation details.
- Panel support classes, query builders, and view internals.
- Scaffolding helper internals.
- Database implementation details beyond documented config, base models, and
  migrations.

## How to request a public API addition

Open an issue or pull request with:

- The host-app use case.
- The current workaround.
- The proposed contract, method, command option, or config key.
- Compatibility, migration, and security considerations.

Small, contract-based additions are preferred over exposing internal runtime
classes.
