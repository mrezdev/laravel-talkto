# Production Hardening

Use this checklist before a Laravel host app sends or receives Talkto traffic in production.

## Secret Management

- Store peer secrets in environment variables or a secret manager.
- Use a different shared secret for each service pair and direction when possible.
- Never commit secrets, raw signatures, raw nonce values, private URLs, or production payloads.
- Rotate secrets carefully across both services so senders and receivers stay in sync.

## V2 Signatures

Use v2-only signatures for normal production deployments:

```dotenv
TALKTO_REQUIRE_SIGNATURE=true
TALKTO_SIGNATURE_VERSION=v2
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
```

Never use `TALKTO_REQUIRE_SIGNATURE=false` in production.

## Nonce Replay Protection

Keep v2 nonce protection enabled:

```dotenv
TALKTO_REPLAY_PROTECTION_ENABLED=true
TALKTO_REQUIRE_V2_NONCE=true
```

Publish and run the nonce migration before receiving v2 traffic. The ledger stores nonce hashes, not raw nonces.

## Timestamp Window

Keep `TALKTO_TIMESTAMP_TOLERANCE_SECONDS` narrow enough for replay resistance and wide enough for normal clock skew. The default is 300 seconds. Monitor clocks on all participating hosts.

## Source And Target Service Names

Set a stable service name in every app:

```dotenv
TALKTO_SERVICE=website-service
```

Make sure outgoing target names and incoming source names match the values signed into envelopes. A mismatch should fail verification.

Outgoing target diagnostics use the same normalized URL config as runtime. Prefer `base_url` with `receive_endpoint` and `callback_endpoint`, or explicit `receive_url` and `callback_url`.

## Outgoing TLS Verification

Keep outgoing HTTP TLS verification enabled in production:

```dotenv
TALKTO_HTTP_VERIFY_SSL=true
```

If a peer uses an internal certificate authority, prefer a readable CA bundle instead of disabling verification:

```dotenv
TALKTO_HTTP_CA_BUNDLE=/path/to/internal-ca.pem
```

Per-target `verify_ssl` and `ca_bundle` settings override the global HTTP settings. `talkto:security-audit` warns when effective verification is disabled, when a configured CA bundle is missing or unreadable, and when a CA bundle is ignored because verification is disabled. The panel connection registry shows the effective mode and a safe CA bundle filename label.

## Command Allowlist

Every incoming source should explicitly allow only the commands it accepts:

```php
'incoming' => [
    'website-service' => [
        'secret' => env('TALKTO_FROM_WEBSITE_SECRET'),
        'allowed_commands' => [
            'catalog.reserve-stock' => [
                'driver' => 'handler',
                'handler' => App\Talkto\Handlers\ReserveStockHandler::class,
                'idempotency' => 'required',
            ],
        ],
        'allow_all_commands' => false,
    ],
],
```

Never use `allow_all_commands=true` in production.

## Routes

Package routes are disabled by default:

```dotenv
TALKTO_ROUTES_ENABLED=false
```

Enable them only when the host is ready to expose the package receive and callback endpoints:

```dotenv
TALKTO_ROUTES_ENABLED=true
TALKTO_ROUTES_PREFIX=api
TALKTO_ROUTE_MIDDLEWARE=api,throttle:talkto
TALKTO_RATE_LIMIT_ENABLED=true
```

Rate limiting reduces request volume. It does not replace signatures, timestamp checks, nonce protection, peer secrets, or command allowlists.

## Queue Workers And Retry Behavior

Run queue workers with your normal process manager:

```bash
php artisan queue:work
```

Review retry settings before production:

- `talkto.retry.enabled`
- `talkto.retry.outgoing_enabled`
- `talkto.retry.incoming_enabled`
- `talkto.retry.max_attempts`
- `talkto.retry.backoff_seconds`
- direction, peer, and command overrides

Incoming retries are opt-in because handlers may perform host-owned side effects.

## Failed Jobs And DLQ

Keep dead-letter storage enabled unless you have a host-owned replacement:

```dotenv
TALKTO_DEAD_LETTER_ENABLED=true
TALKTO_DEAD_LETTER_AUTO_STORE=true
```

Review DLQ rows before reprocessing:

```bash
php artisan talkto:dlq-reprocess --dry-run
```

Use `--force` only for operator-approved recovery.

## Callback Verification

Callbacks use signed envelopes too. The source app must configure the destination as an incoming source and allow the callback command, which defaults to `talkto.result`.

Callback v2 nonces are consumed by the same nonce replay ledger. A replayed callback should be rejected with `replay_nonce_reused`.

## Durable Callback Operations

Result callbacks are queued durable outgoing messages on the destination service. A source message stuck at `destination_received` usually means callback delivery is pending or failed, not necessarily that the destination handler failed.

When a destination handler throws unexpectedly, Talkto applies the normal retry/final-failure behavior first and then queues a durable failed callback. Exception callback payloads include a safe exception class/message summary, not a stack trace. Expected business errors should still be returned explicitly with `failedRetryable()` or `failedFinal()` from the handler.

Duplicate callback queue attempts are suppressed where practical by reusing the deterministic callback message, locking that callback row during the queue decision, and checking queued/queue-failed events for the same callback before dispatching `SendTalktoMessage`. Failed callback delivery remains an outgoing-message retry/DLQ/reprocess concern.

On the destination service, check:

- the original incoming message was processed
- the incoming status is `succeeded`, `skipped`, `failed_retryable`, or `failed_final`
- an outgoing callback message exists with command `talkto.result`
- the callback `parent_message_id` points to the original incoming message id
- the callback `target_service` is the original source service
- the callback status is `waiting_to_send`, `sending`, `completed`, `failed_retryable`, or `failed_final`
- callback attempts and events exist
- the DLQ does or does not contain the callback message
- queue workers are running for `SendTalktoMessage`

On the source service, check:

- callback routes or host callback route are enabled
- `TALKTO_CALLBACKS_ENABLED=true`
- incoming config allows `talkto.result` from the destination
- the callback secret matches the destination outgoing secret
- nonce, timestamp, and signature settings match
- the original outgoing message exists and matches the callback original message id

Keep `TALKTO_CALLBACKS_AUTO_DISPATCH=true` unless a host intentionally queues callbacks manually.

## Panel Access Control

The panel is disabled by default:

```dotenv
TALKTO_PANEL_ENABLED=false
```

Never enable panel routes without host-owned auth/admin middleware and the configured authorization gate. Keep POST action routes behind the same protections.

```dotenv
TALKTO_PANEL_AUTHORIZATION_ENABLED=true
TALKTO_PANEL_GATE=viewTalktoPanel
```

## Payload And Response Visibility

Keep payload and response bodies hidden in production:

```dotenv
TALKTO_PANEL_SHOW_PAYLOAD=false
TALKTO_PANEL_SHOW_RESPONSE=false
```

Redaction is a safety layer, not access control. Do not expose sensitive host data to operators who should not see it.

## Logging And Redaction

- Use `talkto.security.redacted_keys` for host-specific secret names.
- Do not log raw signatures, raw nonces, authorization headers, cookies, or full production payloads.
- Use `talkto:trace` without `--payload` by default.

## Migrations And Pruning

Publish and review migrations before running them:

```bash
php artisan vendor:publish --tag=laravel-talkto-migrations
php artisan migrate
```

Use pruning only after retention windows are approved:

```bash
php artisan talkto:prune --dry-run
php artisan talkto:prune --type=nonces --older-than=7d --dry-run
php artisan talkto:prune --type=all --limit=500
```

Message pruning is conservative and skips active/in-flight rows.

## Deployment Checklist

- `composer validate --strict` passes.
- `composer audit` passes.
- `vendor/bin/pint --test` passes.
- `vendor/bin/phpstan analyse` passes.
- `vendor/bin/pest` passes.
- Config cache is refreshed after env/config changes.
- Migrations are published, reviewed, and run in the correct order.
- Queue workers are running.
- `php artisan talkto:security-audit` has no deployment-blocking findings.
- `php artisan talkto:audit-security` has no FAIL checks.
- Package routes are intentionally enabled or intentionally disabled.
- Panel is disabled or protected by auth/admin middleware and gate.

## Post-Deploy Smoke Checks

Run these in a non-production or carefully controlled production smoke path:

```bash
php artisan talkto:report --hours=1 --direction=all --limit=20
php artisan talkto:retry-failed --dry-run
php artisan talkto:dlq-reprocess --dry-run
php artisan talkto:trace <message-id>
```

Also verify one outgoing command, one incoming command, one duplicate `message_id`, and one callback if callbacks are enabled.

## Rollback Notes

- Keep previous config values available during rollout.
- Pause or drain workers before rolling back code that changes handler behavior.
- Do not delete Talkto tables during rollback.
- If v2 rollout fails, prefer fixing secrets, timestamps, headers, or nonce migrations over disabling signatures.
- If you temporarily accept v1 during migration, document the reason and remove it after peers are upgraded.
