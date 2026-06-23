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

## Signature Verification Failed

Symptom: Incoming request is rejected with a signature error.

Likely cause: Different peer secrets, wrong source/target names, payload hash mismatch, timestamp mismatch, wrong accepted signature version, or changed signed headers.

Safe fix: Verify both services use matching secrets and service names, v2 headers are present, clocks are synchronized, and payloads are sent unchanged. Run `php artisan talkto:security-audit`.

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

Safe fix on the destination service:

- confirm the incoming original message was processed
- check whether the incoming message is `succeeded`, `skipped`, `failed_retryable`, or `failed_final`
- find the outgoing durable callback message with command `talkto.result`
- confirm its `parent_message_id` is the original incoming message id
- confirm its `target_service` is the original source service
- inspect callback status, attempts, events, and DLQ rows
- confirm a queue worker is running so `SendTalktoMessage` can deliver callbacks

Safe fix on the source service:

- confirm package callback routes or the host callback route are available
- confirm `TALKTO_CALLBACKS_ENABLED=true`
- allow `talkto.result` from the destination under `talkto.incoming`
- verify the callback secret matches the destination outgoing secret
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
