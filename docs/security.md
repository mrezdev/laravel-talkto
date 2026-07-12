# Security

Laravel Talkto protects service-to-service commands with explicit peer configuration, HMAC signatures, timestamp checks, payload hashes, command allowlists, message id idempotency, and v2 nonce replay protection.

## Recommended Production Defaults

New installs default to v2-only signing and v2 nonce requirements:

```dotenv
TALKTO_REQUIRE_SIGNATURE=true
TALKTO_SIGNATURE_VERSION=v2
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
TALKTO_REQUIRE_V2_NONCE=true
TALKTO_REPLAY_PROTECTION_ENABLED=true
```

v1 is legacy/manual opt-in only. New projects should not start with v1.

## What V2 Verifies

v2 signed requests require:

- `X-Talkto-Timestamp`
- `X-Talkto-Payload-Hash`
- `X-Talkto-Signature`
- `X-Talkto-Signature-Version`
- `X-Talkto-Nonce`

The package signs canonical message fields, including the timestamp, payload hash, source, target, command, message id, and nonce. Changing the nonce invalidates the signature.

Incoming verification rejects requests when the signature is invalid, the timestamp is outside tolerance, the source is unknown, the target service does not match, the command is not allowed, the payload hash differs, or nonce replay protection fails.

## Envelope Field Validation

Talkto rejects protocol-level envelope identifiers and Talkto header values that contain ASCII control characters `U+0000` through `U+001F`, `U+007F`, or Unicode line and paragraph separators `U+2028` and `U+2029`. Envelope identifier strings also reject invalid UTF-8. This narrow value rule prevents ambiguous canonical strings, malformed headers, hidden identifier manipulation, multiline log confusion, and misleading trace output without imposing an ASCII-only identifier format.

HTTP header names use a stricter RFC token rule: ``^[!#$%&'*+\-.^_`|~0-9A-Za-z]+$``. Configured signature-version and nonce header names, built-in Talkto headers, target custom headers, programmatically registered target headers, and direct verifier header arrays are rejected if a name is empty or contains spaces, tabs, CR/LF, NUL, colon, slash, `DEL`, or Unicode. The raw unsafe header name is never included in errors.

The value rule applies to signed and routed metadata such as message ids, source service, target service, command, correlation id, parent message id, callback identity fields, timestamp header values, nonce values, signature-version values, and payload hashes. All values in a multi-value header array are inspected. Protocol headers that must be singular, including timestamp, signature, signature version, nonce, payload hash, and message id, reject duplicate logical values as `invalid_header_value_count` instead of selecting the first value. Talkto does not trim, normalize, replace, or sanitize values; invalid metadata is rejected explicitly with only the safe field name in responses and event metadata.

Payload string values are intentionally outside this rule. Business payloads may contain newlines, tabs, empty strings, leading/trailing spaces, Persian, Arabic, accented, CJK, emoji, and other normal user content, subject to the separate JSON-safe payload freeze and payload hash rules.

## Payload Hash Encoding

Talkto payload hashes are calculated from deterministic JSON bytes. The encoder sorts associative keys for hashing, preserves list order, leaves Unicode and slashes unescaped, keeps valid JSON numeric values as numbers, and rejects unsupported non-finite float values such as `NAN`, `INF`, and `-INF`.

The default HTTP transport also uses deterministic JSON for the final request body. This prevents valid float payloads from being signed under one PHP `serialize_precision` setting and sent or verified under another. Payload hash checks are still strict: Talkto does not skip hash verification or accept arbitrary hashes.

Host applications may still choose decimal strings for money or quantity contracts that require exact scale. That is a domain contract choice; the Talkto transport itself safely supports valid JSON floats.

## Outgoing Payload Freeze Boundary

Outgoing message creation freezes the host-supplied logical payload before hashing or persistence. Each supported object instance is converted once per freeze operation and repeated references reuse the same final primitive result. After that point, the package uses the stored primitive tree for the `payload_hash`, `talkto_messages.payload`, signed envelopes, default HTTP JSON body, retry sends, DLQ storage/reprocess, durable result callback messages, and payload-hash repair.

The freeze boundary accepts JSON primitives, arrays, top-level scalars, Laravel collections, Laravel `Arrayable` values, `JsonSerializable` values, backed enums, Carbon and other JSON-serializable date objects, `stdClass`, and userland DTOs with public properties. Top-level scalar payloads keep the existing `['value' => ...]` wrapper. Carbon and other `JsonSerializable` date objects keep their own JSON serialization, including microseconds where their JSON output includes them. Native `DateTimeInterface` values are rejected unless the host formats them explicitly as strings.

Unsupported values fail before a message row is created: resources or streams, closures, generators, traversable objects without an explicit supported serialization contract, internal hidden-state objects, callable/invokable objects without a supported serialization contract, native `DateTimeInterface`, pure `UnitEnum` values, implicit `Stringable` objects, non-finite floats, invalid UTF-8 strings, circular references, and excessively nested payloads. Exception messages identify the path and error code without dumping payload values and remain catchable as `InvalidArgumentException`.

This boundary does not change route behavior, signatures, header names, config, migrations, commands, or the deterministic hash algorithm for already-primitive JSON payloads.

## Raw JSON Body Verification

Package receive and callback routes verify JSON requests from the raw HTTP body, not from Laravel's parsed input bags. This matters because normal Laravel middleware such as `TrimStrings` and `ConvertEmptyStringsToNull` can change parsed JSON values before a controller runs. Signed Talkto JSON is decoded from `Request::getContent()`, then the same decoded envelope is used for validation, payload hash verification, signature verification, storage, dispatch, and callback application.

JSON requests must use a JSON content type such as `application/json`, `application/json; charset=UTF-8`, or an `application/*+json` media type. Malformed, empty, scalar, list-root, invalid UTF-8, or excessively nested JSON requests fail closed with `invalid_json`; the package does not fall back to parsed input for malformed JSON. Clearly non-JSON requests keep the previous parsed-input compatibility path.

## Nonce Replay Protection

v2 nonce replay protection is separate from `message_id` idempotency:

- `message_id` idempotency prevents duplicate business execution for the same logical message.
- Nonce replay protection prevents reuse of a signed HTTP request.

Legitimate retries should use the same `message_id` and a new nonce. The nonce is signed, so a captured request cannot be changed to use a different nonce.

The nonce ledger stores nonce hashes/fingerprints with source, target, signature version, timestamp, message id, and expiry metadata. It does not store raw nonce values, payloads, or responses.

## Timestamp Window

`talkto.security.timestamp_tolerance_seconds` defaults to 300 seconds. Keep the window tight enough to reduce replay risk while allowing normal clock skew between services.

## Safe Defaults And Dangerous Manual Settings

| Area | Safe default | Dangerous/manual setting |
| --- | --- | --- |
| Signatures | `TALKTO_REQUIRE_SIGNATURE=true` | `TALKTO_REQUIRE_SIGNATURE=false` |
| Signature version | `TALKTO_SIGNATURE_VERSION=v2` | `TALKTO_SIGNATURE_VERSION=v1` for new integrations |
| Accepted versions | `TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2` | accepting `v1` without a migration reason |
| V2 nonce | `TALKTO_REQUIRE_V2_NONCE=true` | `TALKTO_REQUIRE_V2_NONCE=false` |
| Replay protection | `TALKTO_REPLAY_PROTECTION_ENABLED=true` | replay protection disabled |
| Command authorization | explicit `allowed_commands` | missing `allowed_commands` |
| Broad commands | `allow_all_commands=false` | `allow_all_commands=true` in production |
| Panel access | panel disabled or protected by auth/admin gate | panel enabled without auth/admin middleware or gate |
| Payload visibility | payload/response hidden | payload/response visibility enabled in production |

Do not use the dangerous/manual settings in normal production deployments.

## Legacy Compatibility

Use this only for a temporary migration where at least one peer cannot send v2 yet:

```dotenv
TALKTO_SIGNATURE_VERSION=v1
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v1,v2
TALKTO_REQUIRE_V2_NONCE=false
```

This is not recommended for new projects or normal production use. Keep the migration window short, document the reason, and return to v2-only after all peers are upgraded.

## Command Allowlists

Every incoming source should define explicit commands:

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

Missing or empty `allowed_commands` rejects all commands for that source. That is intentional.

## Secrets And Redaction

Store peer secrets in environment variables or a secret manager. Do not place real secrets in docs, tests, config committed to Git, logs, issues, screenshots, or support requests.

Laravel Talkto redacts common secret-like keys, configured Talkto shared secrets, and sensitive headers such as `Authorization`, `Cookie`, `Set-Cookie`, `X-Talkto-Signature`, and `X-Talkto-Nonce`. Add host-specific keys with `talkto.security.redacted_keys`.

## Routes And Panel

Package routes are disabled by default. When enabled, keep HMAC verification, timestamp checks, nonce replay protection, source config, and command allowlists in place. Rate limiting is useful, but it is not authentication.

The optional panel is disabled by default. If enabled, keep it behind host-owned auth/admin middleware and the configured gate. Keep payload and response visibility disabled unless operators are explicitly authorized to view that data.

## Security Audit Commands

Use the detailed read-only audit before production:

```bash
php artisan talkto:security-audit
php artisan talkto:security-audit --json
php artisan talkto:security-audit --fail-on=error
```

The compatibility PASS/WARN/FAIL audit command is also registered:

```bash
php artisan talkto:audit-security
php artisan talkto:audit-security --json
```

Both commands are read-only. They do not change config, database rows, cache entries, routes, queues, or files.
