# Upgrade Guide

Use this guide when moving a host application to a newer Laravel Talkto package version.

## Before Upgrading

- Review `CHANGELOG.md`, `README.md`, and `docs/upgrading.md`.
- Back up any published host-owned `config/talkto.php` changes before republishing.
- Compare package migrations with host table ownership before enabling `talkto.migrations.enabled`.
- Keep queue workers paused or drainable during rollout if the host has in-flight messages.

## Security v1/v2

- v1 signatures remain the default outgoing format.
- Verification accepts v1 and v2 by default.
- Enable v2 sending with `talkto.security.signature_version = v2` only after both peers have upgraded.
- Force `talkto.security.accept_versions = ['v2']` only after all old senders have stopped using v1.
- Signed requests always require `X-Talkto-Timestamp`.

## Migration Notes

- Retry/backoff uses columns on `talkto_messages`, including retry counts and `next_retry_at`.
- DLQ support uses `talkto_dead_letters` when the DLQ migration is installed.
- Observability reports read existing `talkto_messages`, `talkto_attempts`, `talkto_events`, and `talkto_dead_letters` tables.
- No project-specific business tables or mappings are included.

## Extension Points

- Incoming handlers can be configured or registered through `TalktoIncomingHandlerRegistryContract`.
- Outgoing targets can remain in config or be registered through `TalktoOutgoingTargetRegistryContract`.
- `IncomingCommandResultContract` now uses non-conflicting instance accessors such as `isSucceeded()` and `isRetryable()` instead of names that overlap result factories. This is a pre-public-release API consistency correction; existing `TalktoIncomingCommandResult::succeeded()`, `failedRetryable()`, `failedFinal()`, and `skipped()` factories remain available.
- `TalktoMetricsCollector` and `TalktoHealthChecker` are read-only observability services.
- Public commands include `talkto:retry-failed`, `talkto:dlq-reprocess`, and `talkto:report`.

## Post-Upgrade Checks

Run these checks in a non-production environment:

1. Send one outgoing test message.
2. Receive one incoming test message.
3. Send a duplicate `message_id` and confirm it does not execute twice.
4. Run `php artisan talkto:retry-failed --dry-run`.
5. Run `php artisan talkto:report --json`.
6. Confirm queue workers and scheduler entries are configured.

Do not claim public API stability beyond the current release line. Use Git tags as package version boundaries.
