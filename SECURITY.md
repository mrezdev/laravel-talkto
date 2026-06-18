# Security Policy

Talkto Reliable is currently a private, proprietary package.

## Supported Versions

Supported versions are defined by the active private release line and Git tags. Before any public release, maintainers must define a public supported versions policy.

## Reporting Vulnerabilities

No public vulnerability disclosure address has been approved yet. Until then, report suspected vulnerabilities privately through the repository security advisory workflow or the approved internal project channel.

Do not include real shared secrets, production payloads, private credentials, raw signatures, or sensitive headers in issue text, pull requests, logs, screenshots, or documentation.

## Secret Handling

Keep peer secrets in environment variables or a secret manager. Do not commit real secrets in package config, docs, tests, examples, or reports.

## Signatures And Replay Protection

v1 signatures remain the default for backward compatibility. v2 signatures include version, timestamp, optional nonce, message ID, source, target, command, and payload hash. Enable v2 only after both peers have upgraded.

Signed requests always require `X-Talkto-Timestamp`. Keep `talkto.security.timestamp_tolerance_seconds` tight enough for replay resistance and loose enough for normal clock skew.

Replay protection relies on the existing `message_id` ledger and unique constraint. Duplicate message IDs should be treated as already received rather than executed again.
