# Troubleshooting

Use safe fixes first. Do not disable signatures, nonce protection, or command allowlists as the first response to a production issue.

## Composer Require Fails

Symptom: `composer require mrezdev/laravel-talkto` cannot resolve the package.

Likely cause: Packagist access, package name typo, PHP/Laravel version mismatch, or stale Composer metadata.

Safe fix: Confirm the package name is exact, run `composer clear-cache`, check PHP `^8.2`, and check Laravel component compatibility with `^12.0|^13.0`.

## Package Auto-Discovery Does Not Work

Symptom: The service provider is not loaded.

Likely cause: Composer autoload is stale or package discovery is disabled in the host app.

Safe fix: Run `composer dump-autoload` and `php artisan package:discover`. Confirm `Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider` appears in Composer package discovery.

## Publish Command Fails

Symptom: `vendor:publish` reports no publishable resources.

Likely cause: Wrong publish tag.

Safe fix: Use one of the real tags: `laravel-talkto-config`, `talkto-config`, `laravel-talkto-migrations`, `talkto-migrations`, or `talkto-panel-views`.

## Migrations Are Missing Or Not Run

Symptom: Runtime errors mention missing `talkto_messages`, `talkto_attempts`, `talkto_events`, `talkto_dead_letters`, or `talkto_nonces`.

Likely cause: Migrations were not published/run, or the app is using a different database connection/table config.

Safe fix: Publish migrations, verify `TALKTO_DB_CONNECTION` and table names, run `php artisan migrate`, and clear config cache.

## Config Cache Is Stale

Symptom: Env changes do not affect Talkto behavior.

Likely cause: Laravel config cache still contains old values.

Safe fix: Run `php artisan config:clear` during troubleshooting or `php artisan config:cache` during deployment after env values are correct.

## Outgoing Target Audit Or Panel Warning

Symptom: `talkto:security-audit` or the panel reports an outgoing target URL or secret problem.

Likely cause: The target is missing a normalized URL/secret value, has an invalid URL, or includes incomplete callback URL config.

Safe fix: Prefer `base_url` with `receive_endpoint` and `callback_endpoint`, or explicit `receive_url` and `callback_url`. `signing_secret` is accepted as a secret alias.

## Outgoing TLS Or CA Bundle Warning

Symptom: sends fail with certificate errors, or `talkto:security-audit`/the panel reports disabled SSL verification, a missing CA bundle, an unreadable CA bundle, or an ignored CA bundle.

Likely cause: `verify_ssl=false`, a private CA is missing from the host trust store, `ca_bundle` points to the wrong file, or verification is disabled while a CA bundle is still configured.

Safe fix: Keep `TALKTO_HTTP_VERIFY_SSL=true` for production. If the peer uses a private CA, set `TALKTO_HTTP_CA_BUNDLE` or the target `ca_bundle` to a readable CA bundle file. Clear Laravel config cache after changing env values. Use `verify_ssl=false` only for documented local/staging/internal testing, and remove unused CA bundle values when verification is disabled.

## Signature Verification Failed

Symptom: Incoming request is rejected with a signature error.

Likely cause: Different peer secrets, wrong source/target names, payload hash mismatch, timestamp mismatch, wrong accepted signature version, or changed signed headers.

Safe fix: Verify both services use matching secrets and service names, v2 headers are present, clocks are synchronized, and payloads are sent unchanged. Run `php artisan talkto:security-audit`.

## `payload_hash_mismatch` With Decimal Floats

Symptom: A valid outgoing message is rejected with:

```json
{"received":false,"status":"rejected","error":"payload_hash_mismatch"}
```

Likely cause: Older package code calculated the payload hash with one PHP `serialize_precision` setting, then a database reload, queue worker, HTTP client, or receiver recalculated or encoded the same decoded float payload under another setting. Only some decimal values show this because binary floating-point values such as `79.95` can be rendered as either short JSON (`79.95`) or a long decimal expansion (`79.950000000000002842...`) depending on precision.

Safe fix for new messages: deploy the hardened package to both sender and receiver services, clear config/opcache as usual, and restart PHP-FPM plus long-running queue workers. The package now uses deterministic encoding for Talkto hashes and the default HTTP body. Keep `serialize_precision=-1` in PHP config as defense in depth.

Safe fix for old failed outgoing rows: do not edit payloads manually and do not bulk repair. The repair command is only for stopped outgoing rows in `failed_final` or `dead_lettered`. It refuses `failed_retryable` because that status may still be scheduled for automatic retry or already dispatched to a worker. Inspect the message and run a dry run first:

```bash
php artisan talkto:repair-payload-hash 2c4be25d-c9bb-4d5d-b345-1b75653d7140
```

If the output shows a stale stored hash and the row has payload-hash mismatch evidence, confirm one repair with an operator reason:

```bash
php artisan talkto:repair-payload-hash 2c4be25d-c9bb-4d5d-b345-1b75653d7140 --confirm --reason="legacy serialize_precision hash drift"
```

Repair updates only the stored derived payload hash and records an audit event. If deterministic JSON encoding fails, the command reports a short redacted error and makes no changes. Repair does not resend. After repair, use the existing deliberate DLQ reprocess flow:

```bash
php artisan talkto:dlq-reprocess --message-id=2c4be25d-c9bb-4d5d-b345-1b75653d7140 --dry-run
php artisan talkto:dlq-reprocess --message-id=2c4be25d-c9bb-4d5d-b345-1b75653d7140
```

## Missing Nonce

Symptom: v2 request is rejected because a nonce is missing.

Likely cause: Sender is not sending v2 headers or receiver requires v2 nonce while the sender is still legacy.

Safe fix: Upgrade/configure the sender for v2. Use temporary legacy compatibility only during a documented migration, not as a normal production fix.

## `replay_nonce_reused`

Symptom: A request or callback is rejected as a reused nonce.

Likely cause: The exact signed HTTP request was retried with the same nonce, or a captured request was replayed.

Safe fix: Ensure each signed HTTP attempt generates a fresh nonce. Keep the same `message_id` for legitimate retries, but use a new nonce.

## Timestamp Outside Tolerance

Symptom: Incoming request is rejected for timestamp tolerance.

Likely cause: Clock skew, queued request delayed too long, or too narrow tolerance.

Safe fix: Synchronize clocks and review `TALKTO_TIMESTAMP_TOLERANCE_SECONDS`. Do not use an excessive tolerance to hide clock problems.

## Unknown Source Service

Symptom: Request is rejected because the source is unknown.

Likely cause: Receiver does not have the sender under `talkto.incoming`.

Safe fix: Add the source service with a shared secret and explicit `allowed_commands`.

## Target Service Mismatch

Symptom: Request is rejected because target does not match the current service.

Likely cause: `TALKTO_SERVICE` differs from the target value the sender signs.

Safe fix: Align the receiver `TALKTO_SERVICE` and sender target config.

## Command Not Allowed

Symptom: Request is rejected with `command_not_allowed`.

Likely cause: The source is known, but the command is missing from `allowed_commands`.

Safe fix: Add the exact command under the source allowlist if the receiver intentionally supports it. Do not set `allow_all_commands=true` in production.

## Queue Worker Not Running

Symptom: Messages stay queued, waiting to send, or processing.

Likely cause: No queue worker, wrong queue connection, crashed worker, or stale in-flight lock.

Safe fix: Start `php artisan queue:work`, inspect failed jobs, run `php artisan talkto:report`, and use `php artisan talkto:recover-stale --dry-run` before recovering stale locks.

## Source Message Stuck At `destination_received`

Symptom: The original outgoing message reached the destination, but it never receives destination result/status.

Likely cause: In the durable callback design, the destination may have processed the command but the queued callback message is waiting, retrying, failed, dead-lettered, or rejected by the source callback receiver.

Unexpected destination handler exceptions also queue durable failed callbacks after the destination stores the failure state. The payload is `failed_retryable` or `failed_final` according to the stored retry/DLQ decision and does not include stack traces.

Duplicate manual and automatic callback queue attempts should reuse the same durable callback message. If a callback has an active `result_callback_queued` event and no later queue-failed event, duplicate queue attempts are suppressed. If dispatch failed before a job was queued, the later `result_callback_queue_failed` event allows a future `sendResult()` call to retry queue dispatch.

Safe fix on the destination service:

- confirm the incoming original message was processed
- check whether the incoming message is `succeeded`, `skipped`, `failed_retryable`, or `failed_final`
- find the outgoing durable callback message with command `talkto.result`
- confirm its `parent_message_id` is the original incoming message id
- confirm its `target_service` is the original source service
- inspect callback status, attempts, events, and DLQ rows
- confirm a queue worker is running so `SendTalktoMessage` can deliver callbacks
- use `talkto:retry-failed` or `talkto:dlq-reprocess` for failed callback delivery instead of repeatedly calling `sendResult()` for already queued callback messages

Safe fix on the source service:

- confirm package callback routes or the host callback route are available
- confirm `TALKTO_CALLBACKS_ENABLED=true`
- allow `talkto.result` from the destination under `talkto.incoming`
- verify the callback secret matches the destination outgoing secret
- prefer destination `talkto.outgoing` config with `base_url` plus `receive_endpoint` and `callback_endpoint`, or explicit `receive_url` and `callback_url`
- verify nonce, timestamp, and signature settings match
- confirm the original outgoing message exists and the callback original message id matches it

If automatic queueing is expected, confirm `TALKTO_CALLBACKS_AUTO_DISPATCH=true`. If it is disabled, the destination handler or host workflow must call `ResultCallbackSenderContract::sendResult()` manually.

## DLQ Growth

Symptom: Dead-letter rows keep increasing.

Likely cause: Repeated final failures, exhausted retries, bad peer config, handler failures, or a downstream service outage.

Safe fix: Use `php artisan talkto:report`, inspect `php artisan talkto:trace <message-id>`, fix the root cause, then run `php artisan talkto:dlq-reprocess --dry-run` before reprocessing.

## Panel Returns 403

Symptom: Panel route responds with 403.

Likely cause: `talkto.panel.authorization.enabled` is true and the configured gate denies access.

Safe fix: Define the configured gate, usually `viewTalktoPanel`, for trusted operators. Do not disable authorization in production.

## Packagist Or Install Issues

Symptom: Host app installs an unexpected version or cannot see a new tag.

Likely cause: Packagist has not updated, the tag is missing, or Composer is locked to another constraint.

Safe fix: Verify the intended Git tag, Packagist metadata, and the host `composer.lock`. Do not claim a GitHub Release or Packagist update is complete until it has actually happened.
