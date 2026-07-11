# Recovery And Monitoring Template

Use this template to design operational views and recovery actions for a host service.

## Monitoring Summary Fields

Expose summary fields for operators:

- service identity
- peer service
- command
- direction
- status
- retryability
- count
- oldest pending age
- newest failure age
- callback status
- last redacted error code

## Message List Fields

A message list should be searchable by:

- message id
- correlation id
- business key
- peer service
- command
- status
- retryability
- attempt count
- created time
- updated time
- next retry time

## Message Detail Fields

A detail page or command output should show:

- envelope metadata
- redacted payload summary
- attempts
- lifecycle events
- callback state
- retryability classification
- last redacted error
- available safe actions

## Trace One Flow

Use the read-only trace report before recovery actions:

```bash
php artisan talkto:trace <message-id>
php artisan talkto:trace --correlation=<correlation-id> --json
```

The trace combines related messages, attempts, lifecycle events, dead letters, callback events, and correlation ids into a chronological timeline. Payload values are redacted by default; `--payload` includes payload values while still redacting secret-like fields.

## Retryability Classifications

Use explicit classifications:

- `retryable`: temporary network, queue, or timeout issue.
- `review_required`: validation error, unexpected state, or missing source message.
- `blocked`: config, peer, route, or handler problem.
- `not_retryable`: invalid signature, payload hash mismatch, unknown source, unsafe duplicate.

## Retry And DLQ Lifecycle

Use `php artisan talkto:retry-failed --dry-run` before dispatching retries. The command reports eligible and skipped messages with compact skip reasons such as retry disabled, direction disabled, max attempts exhausted, or not due.

Dead letters move through a small lifecycle:

- `open`: stored final failure.
- `reprocessing`: claimed by `talkto:dlq-reprocess`.
- `reprocessed`: a reprocess completed successfully or was skipped as an accepted terminal result.
- `failed_reprocess`: the claimed reprocess failed again or dispatch could not happen.
- `ignored`: deliberately set aside by operator policy.

Use `php artisan talkto:trace <message-id>` to inspect attempts, events, and DLQ transitions before running recovery commands.

## Payload Hash Repair

For old outgoing rows that failed with `payload_hash_mismatch` because the row was created under legacy float precision behavior, use the single-message repair command. It is dry-run by default and does not dispatch any job:

```bash
php artisan talkto:repair-payload-hash <message-id>
```

To mutate one eligible failed outgoing row, provide both confirmation and an operator reason:

```bash
php artisan talkto:repair-payload-hash <message-id> --confirm --reason="legacy serialize_precision hash drift"
```

Only stopped outgoing rows in `failed_final` or `dead_lettered` are repairable. The command refuses incoming messages, `failed_retryable`, non-failed states, unrelated failures, and rows whose deterministic hash already matches. `failed_retryable` is intentionally never repairable because it may still be selected by `talkto:retry-failed` or an already-running worker. Let the normal retry lifecycle finish or move the message into a stopped review state first.

If the deterministic hash cannot be calculated, the command returns a redacted failure and makes no changes. Repair never resends automatically; after repair, use the existing `talkto:dlq-reprocess` command deliberately, or another operator-approved retry path for the stopped row.

## Recovery Action Safety Gates

Recovery actions should require:

- trusted operator access
- current environment confirmation
- message id
- expected current status
- reason
- `confirm=true`
- redacted logging

## Single-Message Action Rule

Run recovery actions on one message at a time unless a separate batch recovery process has its own design, rate limits, dry-run output, and approval.

## Confirm True Rule

Any action that retries, marks, replays, cancels, or completes a message must require `confirm=true`. Without confirmation, return a dry-run result showing what would happen.

## Redaction Rule

Monitoring and recovery output must not show shared secrets, raw signatures, full headers, full payloads, credentials, or sensitive result data. Prefer ids, counts, statuses, and short redacted error codes.

## Production Access Policy

Restrict production monitoring and recovery to approved operators. Log who requested the action, when it ran, the message id, the prior state, the new state, and the redacted reason. Do not run destructive database commands for recovery.

## Readiness Checks

A production readiness check should confirm config, peer services, route ownership, migration ownership, queue settings, retry settings, callback settings, monitoring, redaction, and rollback steps before enabling traffic.
