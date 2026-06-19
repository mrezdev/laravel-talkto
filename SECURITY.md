# Security Policy

Laravel Talkto is open-sourced software licensed under the MIT license.

## Supported Versions

Supported versions are defined by the active release line and Git tags.

## Reporting Vulnerabilities

Report suspected vulnerabilities through the repository security advisory workflow or the maintainer-approved security contact for the published package.

Do not include real shared secrets, production payloads, private credentials, raw signatures, nonce values, authorization headers, cookies, or sensitive headers in issue text, pull requests, logs, screenshots, or documentation.

## Secret Handling

Keep peer secrets in environment variables or a secret manager. Do not commit real secrets in package config, docs, tests, examples, or reports.

## Signatures And Replay Protection

v1 signatures remain the default for backward compatibility. v2 signatures include version, timestamp, nonce support, message ID, source, target, command, and payload hash. Prefer v2 for new peers after both services can send and verify the v2 headers.

Signed requests always require `X-Talkto-Timestamp`. Keep `talkto.security.timestamp_tolerance_seconds` tight enough for replay resistance and loose enough for normal clock skew.

Replay protection relies on the existing `message_id` ledger and unique constraint. Duplicate message IDs should be treated as already received rather than executed again.

After all v2 peers send `X-Talkto-Nonce`, enable `talkto.security.replay_protection.require_nonce_for_v2`.

Run `php artisan talkto:security-audit` in host test environments to review signature, timestamp, nonce, route middleware, peer secret, and command allowlist posture without mutating state.
