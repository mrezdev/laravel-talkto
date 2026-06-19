# Production Hardening

Use this checklist before exposing Laravel Talkto package routes to network traffic. Routes are disabled by default and should stay disabled until the host application has reviewed route ownership, peer secrets, queue workers, storage, monitoring, and rollback steps.

## Strict V2 Receive Profile

For new integrations, prefer v2 signatures with nonce and replay protection:

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

Use v2 for new integrations. v1 remains available mainly for compatibility with existing peers that have not moved to v2 headers.

Require v2 nonces in production after every peer sends `X-Talkto-Nonce`:

```dotenv
TALKTO_REQUIRE_V2_NONCE=true
TALKTO_REPLAY_PROTECTION_ENABLED=true
```

Replay protection should stay enabled for production receivers.

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

## Panel

Keep the panel disabled in production unless the host has authenticated middleware, a narrow authorization gate, payload visibility rules, and operator procedures in place:

```dotenv
TALKTO_PANEL_ENABLED=false
```
