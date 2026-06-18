# Security

Talkto Reliable protects service-to-service messages with explicit peer configuration and signed envelopes.

## Signing

The package signs a canonical string containing message id, timestamp, source, target, command, and payload hash with HMAC SHA-256.

## Verification

Incoming requests are rejected when required headers are missing, the timestamp is outside tolerance, the source is unknown, the target is wrong, the command is not allowed, the payload hash differs, or the signature is invalid.

## Payload Hashing

Payloads are normalized before hashing so object key order does not change the SHA-256 hash.

## Replay Protection

Allowed commands can require an idempotency key. The receive lifecycle checks duplicate message ids and completed idempotency keys before queueing work.

## Secrets

Keep peer secrets in environment variables or a secret manager. Do not place real secrets in package docs, host docs, reports, or tests.
