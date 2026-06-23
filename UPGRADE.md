# Upgrade Guide

Use this guide when moving a host application to a newer Laravel Talkto package version.

## Before Upgrading

- Review `CHANGELOG.md`, `README.md`, and `docs/upgrading.md`.
- Back up any published host-owned `config/talkto.php` changes before republishing.
- Compare package migrations with host table ownership before enabling `talkto.migrations.enabled`.
- Publish and review new migrations before running them.
- Keep queue workers paused or drainable during rollout if the host has in-flight messages.

## Pre-1.0 Security Hardening

Laravel Talkto hardened its defaults before a stable 1.0 release:

- outgoing signatures default to `v2`
- incoming verification accepts `v2` by default
- v2 nonces are required by default
- replay protection is enabled by default
- v1 is legacy/manual opt-in only

Recommended production env:

```dotenv
TALKTO_SIGNATURE_VERSION=v2
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
TALKTO_REQUIRE_V2_NONCE=true
TALKTO_REPLAY_PROTECTION_ENABLED=true
```

The nonce replay ledger requires the package nonce migration. If upgrading a host app that receives v2 traffic, publish and run migrations:

```bash
php artisan vendor:publish --tag=laravel-talkto-migrations
php artisan migrate
```

Use legacy compatibility only for a documented migration window:

```dotenv
TALKTO_SIGNATURE_VERSION=v1
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v1,v2
TALKTO_REQUIRE_V2_NONCE=false
```

Return to v2-only once all peers are upgraded.

## Migration Notes

- Retry/backoff uses columns on `talkto_messages`, including retry counts and retry timestamps.
- DLQ support uses `talkto_dead_letters` when the DLQ migration is installed.
- Nonce replay protection uses `talkto_nonces` and stores nonce hashes/fingerprints only.
- Observability reports read existing `talkto_messages`, `talkto_attempts`, `talkto_events`, and `talkto_dead_letters` tables.
- Durable result callbacks use outgoing `talkto_messages` rows and existing attempts, events, retry, and DLQ tables. No separate callback table or new required migration is needed for the durable callback change.
- No project-specific business tables or mappings are included.

## Durable Result Callbacks

Result callbacks are now durable and queued. `ResultCallbackSenderContract::sendResult()` creates or reuses an outgoing callback message and dispatches `SendTalktoMessage`; it no longer performs immediate callback HTTP delivery.

Upgrade notes:

- Queue workers must be running on destination services for callback delivery.
- `TALKTO_CALLBACKS_AUTO_DISPATCH` defaults to `true`.
- Incoming handlers usually only return `TalktoIncomingCommandResult`; they no longer need to call `sendResult()` manually for normal callback flow.
- Manual `sendResult()` remains supported for advanced flows and remains duplicate-safe where possible.
- Tests that previously expected immediate callback HTTP side effects should run the queued callback `SendTalktoMessage` job before asserting source-side callback results.

## Extension Points

- Incoming handlers can be configured or registered through `TalktoIncomingHandlerRegistryContract`.
- Outgoing targets can remain in config or be registered through `TalktoOutgoingTargetRegistryContract`.
- `IncomingCommandResultContract` uses instance accessors such as `isSucceeded()` and `isRetryable()`.
- `TalktoIncomingCommandResult::succeeded()`, `failedRetryable()`, `failedFinal()`, and `skipped()` factories remain available.
- Generic signed result callbacks are available through `ResultCallbackSenderContract` and `ResultCallbackReceiverContract`; hosts may still override either contract.
- `TalktoMetricsCollector` and `TalktoHealthChecker` are read-only observability services.
- Public commands include `talkto:retry-failed`, `talkto:dlq-reprocess`, `talkto:prune`, `talkto:recover-stale`, `talkto:report`, `talkto:trace`, `talkto:security-audit`, and `talkto:audit-security`.

## Post-Upgrade Checks

Run these checks in a non-production environment:

1. Send one outgoing test message.
2. Receive one incoming test message.
3. Send a duplicate `message_id` and confirm it does not execute twice.
4. Confirm v2 nonce replay protection rejects a reused signed request.
5. Run `php artisan talkto:retry-failed --dry-run`.
6. Run `php artisan talkto:report --json`.
7. Run `php artisan talkto:security-audit`.
8. Run `php artisan talkto:trace <message-id>` on smoke messages where applicable.
9. Confirm queue workers and scheduler entries are configured.

Do not claim public API stability beyond the current release line. Use Git tags as package version boundaries.
