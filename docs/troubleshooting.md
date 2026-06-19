# Troubleshooting

This guide covers generic package issues. Host-specific handlers, mappers, writes, and dashboards should be debugged in the host application.

## Invalid Signature

Check that both services use the same shared secret, canonical message fields, timestamp format, and HMAC algorithm. Confirm the request body was not changed by middleware before verification.

## Payload Hash Mismatch

Compare the normalized payload used when the sender built the envelope with the payload received by the destination. Avoid adding transport-only fields after the payload hash is calculated.

## Command Not Allowed

Confirm the source service is configured under `talkto.incoming` and the command name appears in that source service's `allowed_commands` list. Command names are exact strings.

## Duplicate Or Replay

If idempotency is required, confirm every retry sends the same idempotency key for the same logical command. A new logical command should get a new key.

## Callback Failed

Check the callback target URL, endpoint, shared secret, queue state, and stored message relationship. Callback handlers should be safe to retry and should not expose raw secrets in logs.

## Trace One Message

Use `php artisan talkto:trace <message-id>` to inspect a single flow without retrying, dispatching jobs, or mutating rows. Use `php artisan talkto:trace --correlation=<correlation-id> --json` when the message id is unknown but a correlation id is available.

The trace output includes related messages, attempts, events, dead letters, and a sorted timeline. Payload values are redacted by default.

## Queue Job Stuck

Confirm the host queue connection is running in the intended environment. Inspect failed jobs, retry limits, backoff configuration, and any HTTP timeouts reported by the sender or receiver job.

## Routes Disabled

Routes are disabled by default. Enable `talkto.routes.enabled` only when the host wants the package receive route. Existing hosts may keep their own route and controller wrapper.

## Migrations Disabled

Migrations are disabled by default. Enable `talkto.migrations.enabled` only when the host wants package-owned tables. Existing hosts should check for table conflicts first.

## Local Test Server Verification

Use local-only services, local URLs, testing databases, and non-production secrets. Verify that the sender can reach the receiver URL, the receiver can return a signed result, and the source can process that callback.
