# Production Rollout Template

Use this template only after local and staging checks pass. It is not a production enablement by itself.

## Deploy Code Disabled

Deploy package integration code with send and receive traffic disabled. Keep handlers, routes, queues, callbacks, and monitoring present but inactive until readiness gates pass.

## Readiness Check

Run a readiness check before enabling traffic:

- service identity is correct
- peer services are configured
- secrets exist in environment management
- route ownership is decided
- migration ownership is decided
- queues are configured
- failed job handling is ready
- monitoring is available
- recovery actions require `confirm=true`
- rollback steps are written
- no destructive database commands are part of rollout

## Receive Enablement

Enable receive behavior first when adding a destination service. Confirm signature verification, command allowlist, handler resolver, idempotency, queue behavior, monitoring, and redacted errors.

## Send Enablement

Enable send behavior only after the destination service can receive safely. Start with one command and one peer service. Keep volume low until callbacks and monitoring are stable.

## Callback Enablement

Enable callbacks after the source service can verify signatures, match source messages, handle duplicates, and redact logs. Confirm callback retry behavior before increasing traffic.

## Single Controlled Command Smoke

Run one controlled command with a known idempotency key and a safe payload. Confirm:

- outgoing message created
- destination message received
- handler completed expected state
- callback delivered or intentionally skipped
- monitoring shows all lifecycle events
- retry and rollback remain available

## Rollback

Rollback order should be safe and reversible:

1. Disable new sends.
2. Disable new receives if needed.
3. Pause workers only if queued work is unsafe.
4. Keep message tables and logs for investigation.
5. Record the reason and current message state.

Do not delete message tables, truncate data, or run destructive database commands.

## Monitor

Monitor message counts, failures, retries, callback failures, queue delays, oldest pending age, and redacted error codes during and after rollout.

## Cutover Criteria

Increase traffic only when:

- smoke tests pass
- no unexpected retry spike exists
- callbacks are stable
- operators can see message state
- rollback is still available
- downstream service owners approve the next step

## Stop Criteria

Stop rollout if invalid signatures, payload hash mismatches, duplicate unsafe work, callback mismatches, queue backlog, or unclear recovery states appear.
