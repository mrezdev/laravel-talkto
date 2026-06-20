# Production Hardening

Use this checklist before exposing Laravel Talkto package routes to network traffic. Routes are disabled by default and should stay disabled until the host application has reviewed route ownership, peer secrets, queue workers, storage, monitoring, and rollback steps.

## Strict V2 Receive Profile

For production, use v2-only signatures with nonce and replay protection. These are the package defaults for new installs:

```dotenv
TALKTO_ROUTES_ENABLED=true
TALKTO_REQUIRE_SIGNATURE=true
TALKTO_SIGNATURE_VERSION=v2
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
TALKTO_REQUIRE_V2_NONCE=true
TALKTO_REPLAY_PROTECTION_ENABLED=true
TALKTO_ROUTE_MIDDLEWARE=api,throttle:talkto
TALKTO_RATE_LIMIT_ENABLED=true
TALKTO_RATE_LIMIT_NAME=talkto
TALKTO_RATE_LIMIT_MAX_ATTEMPTS=120
TALKTO_RATE_LIMIT_DECAY_MINUTES=1
TALKTO_PANEL_ENABLED=false
```

Enable package routes only when the host is ready to receive public or private network traffic on the package endpoints. Existing applications can keep package routes disabled and wrap package services in host-owned controllers instead.

## Route Throttling

The default package route middleware is `api,throttle:talkto` when route rate limiting is enabled. The service provider registers the named `talkto` Laravel rate limiter when package routes are enabled.

Throttling reduces accidental or abusive request volume, but it is not a replacement for HMAC signatures, timestamp checks, nonce checks, replay protection, peer allowlists, or command allowlists.

Override the package route middleware with `TALKTO_ROUTE_MIDDLEWARE` only when the host owns an equivalent stack:

```dotenv
TALKTO_ROUTE_MIDDLEWARE=api,auth:sanctum,throttle:talkto
```

If the limiter name changes, keep the middleware and limiter config aligned:

```dotenv
TALKTO_RATE_LIMIT_NAME=internal-talkto
TALKTO_ROUTE_MIDDLEWARE=api,throttle:internal-talkto
```

## Signature Version

Use v2 for new integrations. v1 remains available only as an explicit legacy/manual opt-in for rare interoperability, debugging, or migration cases. New projects should not start with v1 or accept both v1 and v2.

Require v2 nonces in production:

```dotenv
TALKTO_REQUIRE_V2_NONCE=true
TALKTO_REPLAY_PROTECTION_ENABLED=true
```

Replay protection should stay enabled for production receivers.

The nonce replay ledger stores hashes/fingerprints, not raw nonces, payloads, or responses. It is independent from message id idempotency: `message_id` prevents duplicate business execution, while the nonce prevents reuse of a signed request.

## Security Audit Command

Run the PASS/WARN/FAIL audit before enabling Talkto in production:

```bash
php artisan talkto:audit-security
php artisan talkto:audit-security --json
```

The command can be used manually or in CI. Treat `FAIL` checks as deployment blockers. Review every `WARN` check intentionally, especially accepted v1 signatures, missing v2 nonce enforcement, broad command allowlists, exposed routes without throttling, and panel exposure.

## Stale Message Recovery

Use stale recovery when a worker crashes or a message remains stuck in `sending` or `processing` with an old lock:

```bash
php artisan talkto:recover-stale --dry-run
php artisan talkto:recover-stale --direction=outgoing
php artisan talkto:recover-stale --older-than=30
php artisan talkto:recover-stale --limit=50
```

Always run `--dry-run` first in production. This command does not replace normal retry behavior; it is an operator tool for stale in-flight locks. Schedule it only when your operators want automated stale-lock recovery, and keep the limit small enough for safe review.

## Retention Pruning

Use pruning to keep Talkto storage bounded after operational retention windows expire:

```bash
php artisan talkto:prune --dry-run
php artisan talkto:prune --type=events --older-than=30d
php artisan talkto:prune --type=attempts --older-than=90d
php artisan talkto:prune --type=dead-letters --older-than=180d
php artisan talkto:prune --type=nonces --older-than=7d
php artisan talkto:prune --type=messages --older-than=90d
php artisan talkto:prune --type=all --dry-run
php artisan talkto:prune --type=all --limit=500
```

Always run `--dry-run` first in production. Message pruning is conservative: it only deletes old terminal messages and skips active or in-flight rows such as queued, waiting to send, sending, processing, and retryable messages. Pruning is for storage retention, not message recovery; use `talkto:recover-stale` for stuck in-flight messages before pruning. Retention defaults can be changed with `TALKTO_RETENTION_MESSAGES_DAYS`, `TALKTO_RETENTION_ATTEMPTS_DAYS`, `TALKTO_RETENTION_EVENTS_DAYS`, `TALKTO_RETENTION_DEAD_LETTERS_DAYS`, and `TALKTO_RETENTION_NONCES_DAYS`.

## Panel

Keep the panel disabled in production unless the host has authenticated middleware, a narrow authorization gate, payload visibility rules, and operator procedures in place:

```dotenv
TALKTO_PANEL_ENABLED=false
```

When enabling it for trusted operators, keep every panel route, including POST actions, behind host-owned auth or stricter admin middleware:

```php
'panel' => [
    'route' => [
        'middleware' => ['web', 'auth'],
    ],
],
```

Keep `TALKTO_PANEL_SHOW_PAYLOAD=false` and `TALKTO_PANEL_SHOW_RESPONSE=false` unless operators are explicitly allowed to inspect those values. List views avoid loading heavy payload/response columns, and detail/trace views redact common secrets such as authorization headers, cookies, API keys, Talkto signatures, tokens, secrets, and passwords. Redaction is only a safety layer; it is not a substitute for access control.

Run the audit command after panel config changes:

```bash
php artisan talkto:audit-security
```
