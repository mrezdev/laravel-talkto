# Security Policy

Laravel Talkto is open-sourced software licensed under the MIT license.

## Supported Versions

Supported versions are defined by the active release line and Git tags. Pre-1.0 releases may include security-hardening changes that require host configuration review.

## Reporting Vulnerabilities

Report suspected vulnerabilities through the repository security advisory workflow or the maintainer-approved public security contact for the published package.

Do not include real shared secrets, production payloads, private credentials, raw signatures, raw nonce values, authorization headers, cookies, private host names, or sensitive headers in issues, pull requests, logs, screenshots, or documentation.

## Safe Production Defaults

New installs default to the recommended v2 profile:

```dotenv
TALKTO_REQUIRE_SIGNATURE=true
TALKTO_SIGNATURE_VERSION=v2
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
TALKTO_REQUIRE_V2_NONCE=true
TALKTO_REPLAY_PROTECTION_ENABLED=true
```

v2 signatures include timestamp, payload hash, signature version, message fields, and nonce support. The nonce is signed, and changing it invalidates the signature. The nonce ledger stores hashes/fingerprints only; raw nonce values are not stored.

v1 is legacy/manual opt-in only for rare compatibility windows. New projects should use v2-only signing and verification.

## Production Warnings

Do not use these settings in normal production deployments:

- `TALKTO_REQUIRE_SIGNATURE=false`
- `TALKTO_REPLAY_PROTECTION_ENABLED=false`
- `TALKTO_REQUIRE_V2_NONCE=false`
- accepting `v1` without a documented migration reason
- `allow_all_commands=true`
- missing or empty `allowed_commands`
- panel routes without auth/admin middleware and gate protection
- payload/response visibility enabled for operators who should not see that data

Run a read-only audit in host test environments before production:

```bash
php artisan talkto:security-audit
php artisan talkto:audit-security
```
