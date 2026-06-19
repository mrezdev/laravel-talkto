# Security

Laravel Talkto protects service-to-service messages with explicit peer configuration and signed envelopes.

## Signing

The package signs a canonical string containing message id, timestamp, source, target, command, and payload hash with HMAC SHA-256.

`v1` signatures remain the default for backward compatibility. `v2` signatures add explicit version, payload hash, and nonce headers and are recommended for new peer integrations once both services support them.

## Verification

Incoming requests are rejected when required headers are missing, the timestamp is outside tolerance, the source is unknown, the target is wrong, the command is not allowed, the payload hash differs, or the signature is invalid.

`talkto.security.accept_versions` controls which signature versions receivers accept. Keep `v1` only while older peers still need it. After a v2 migration, enable `talkto.security.replay_protection.require_nonce_for_v2` so v2 requests must include a nonce.

Timestamp tolerance limits replay windows and clock skew. The default is 300 seconds; unusually high values increase risk, and zero or negative values should be avoided.

## Payload Hashing

Payloads are normalized before hashing so object key order does not change the SHA-256 hash.

## Replay Protection

Allowed commands can require an idempotency key. The receive lifecycle checks duplicate message ids and completed idempotency keys before queueing work.

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
