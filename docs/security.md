# Security

Laravel Talkto protects service-to-service messages with explicit peer configuration and signed envelopes.

## Signing

The package signs a canonical string containing message id, timestamp, source, target, command, and payload hash with HMAC SHA-256.

`v2` signatures are the default and recommended production mode. `v2` adds explicit version, payload hash, and nonce headers, and the nonce is included in the signed material. `v1` remains available only as an explicit legacy/manual opt-in for rare interoperability, debugging, or migration cases; new projects should not start with v1.

## Verification

Incoming requests are rejected when required headers are missing, the timestamp is outside tolerance, the source is unknown, the target is wrong, the command is not allowed, the payload hash differs, or the signature is invalid.

`talkto.security.accept_versions` controls which signature versions receivers accept. New installs accept only `v2`. Keep `v1`, or `v1,v2`, only as an explicit legacy/manual compatibility choice. v2 requests require `X-Talkto-Nonce` by default.

Timestamp tolerance limits replay windows and clock skew. The default is 300 seconds; unusually high values increase risk, and zero or negative values should be avoided.

## Payload Hashing

Payloads are normalized before hashing so object key order does not change the SHA-256 hash.

## Replay Protection

Allowed commands can require an idempotency key. The receive lifecycle checks duplicate message ids and completed idempotency keys before queueing work.

The v2 nonce replay ledger is separate from message id idempotency. `message_id` prevents duplicate business execution; the nonce ledger prevents reuse of a signed request. Legitimate retries should keep the same `message_id` but send a fresh nonce. The nonce ledger stores a SHA-256 nonce fingerprint with source, target, signature version, timestamps, and expiry metadata. It does not store raw nonces, payloads, or responses.

Recommended production signing config:

```dotenv
TALKTO_SIGNATURE_VERSION=v2
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
TALKTO_REQUIRE_V2_NONCE=true
```

Legacy/manual compatibility config:

```dotenv
TALKTO_SIGNATURE_VERSION=v1
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v1,v2
TALKTO_REQUIRE_V2_NONCE=false
```

## Command Allowlist

Each incoming peer should define `allowed_commands`. Missing or overly broad allowlists let a known peer attempt commands that were not intentionally exposed.

## Secrets

Keep peer secrets in environment variables or a secret manager. Do not place real secrets in package docs, host docs, reports, or tests.

Laravel Talkto centralizes safe output through `TalktoSecurityRedactor`. Traces, audit output, and callback event excerpts redact common secret-like keys, Talkto shared secrets from configured peers, and sensitive headers such as `Authorization`, `X-Talkto-Signature`, `X-Talkto-Nonce`, `Cookie`, and `Set-Cookie`. Add host-specific key names with `talkto.security.redacted_keys`.

## Route Rate Limiting

Package routes are opt-in. When enabled, their default middleware includes Laravel's named `throttle:talkto` limiter. Hosts can override the route middleware stack with `TALKTO_ROUTE_MIDDLEWARE` or use a host-owned route wrapper.

Rate limiting is a volume control, not an authentication control. Keep signatures, timestamp checks, replay protection, source allowlists, and command allowlists enabled for production receivers.

## Security Audit

Run `php artisan talkto:security-audit` for a read-only configuration review. Use `--json` for automation and `--fail-on=error` or `--fail-on=critical` in CI-style checks. The command reports findings and recommendations without changing config, database rows, cache entries, routes, queues, or files.
