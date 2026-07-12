# Changelog

## Unreleased

### Migration Publishing And README Badges

- Switched package migration publishing to Laravel's `publishesMigrations()` API so new host-published Talkto migration copies use Laravel-standard current sequential timestamps when the host has `database.migrations.update_date_on_publish=true`.
- Kept both existing migration publish tags, `laravel-talkto-migrations` and `talkto-migrations`, and left optional direct package migration loading through `TALKTO_MIGRATIONS_ENABLED=true` unchanged.
- Documented the Laravel host database configuration key, existing-installation behavior, and the warning not to repeatedly publish Talkto migrations into apps that already own reviewed migration files.
- Simplified the README badge block to four essential badges: Latest Version on Packagist, Tests, PHP Version, and License.

### Envelope Control-Character Validation

- Added centralized validation for Talkto envelope identifiers and Talkto protocol headers so ASCII controls `U+0000` through `U+001F`, `U+007F`, and Unicode line/paragraph separators `U+2028` and `U+2029` are rejected before signing, routing, persistence, nonce storage, HTTP transport, callback application, or handler execution.
- Added RFC-token validation for Talkto HTTP header names, including configured signature-version/nonce header names, built-in protocol headers, custom target headers, programmatically registered target headers, and direct verifier header arrays.
- Hardened header value validation so all values are inspected, unsafe secondary values are not ignored, and singular protocol headers such as timestamp, signature, signature version, nonce, payload hash, and message id reject duplicate logical values.
- Preserved ordinary identifiers, including spaces, underscores, hyphens, colons, dots, slashes, Persian, Arabic, accented, CJK, and emoji characters; payload strings remain unrestricted by this envelope rule and may still contain newlines, tabs, trailing spaces, empty strings, and multiline user content.
- Added safe `invalid_envelope_field` receiver/callback rejections with field names only, keeping unsafe raw values out of responses, exception messages, attempts, events, and panel-facing error detail.
- Hardened historical outgoing rows with unsafe stored identifiers so retries fail locally as final, non-retryable review-required transport failures instead of sending ambiguous signed requests or entering a retry loop.
- Kept public APIs, config, routes, migrations, signature versions, canonical strings, payload hashes, HTTP bodies, and valid v1/v2 signatures unchanged for existing valid identifiers.

### One-Time Outgoing Payload Freezing

- Added an internal outgoing payload freezer so host-supplied payloads are converted into a JSON-safe primitive tree before payload hashing, persistence, envelope creation, HTTP transport, retry, DLQ, callback creation, and hash repair paths use them.
- Corrected the freezer to memoize each supported object instance during one freeze operation, so repeated references to the same `Arrayable`, `JsonSerializable`, Eloquent model, collection item, or public DTO reuse the same final primitive result instead of re-running conversion.
- Updated result callback data to capture one immutable frozen payload snapshot; repeated `toPayload()` and `toEnvelope()` calls now reuse the same payload, hash, and timestamp. Caller-supplied callback snapshots passed through the optional constructor snapshot parameter are now validated and frozen before storage instead of being trusted as already primitive.
- Preserved public outgoing APIs, routes, commands, migrations, config keys, signature headers, and the deterministic payload hash algorithm for already-primitive JSON payloads.
- Supported common Laravel payload inputs at the boundary, including arrays, top-level scalars, collections, `Arrayable`, `JsonSerializable`, backed enums, Carbon/JSON-serializable date objects, `stdClass`, and userland public-property DTOs, while rejecting resources, closures, generators, traversable/internal hidden-state objects, native `DateTimeInterface`, pure enums, non-finite floats, invalid UTF-8, circular references, and excessive nesting before persistence.
- Made unsupported payload failures catchable as `InvalidArgumentException` while preserving the package-specific `TalktoUnsupportedPayloadValueException` class and bounded path/error-code diagnostics.
- Moved duplicate idempotency lookup ahead of payload freezing when the metadata-only fingerprint already identifies an existing outgoing message.
- Added focused regression coverage for shared object memoization, explicit callback snapshot validation, callback snapshot repeatability, `fromEnvelope()` primitive preservation, exception compatibility, Carbon/native DateTime policy, unsupported internals/traversables, DTOs, collections, Eloquent models, primitive hash compatibility, retry/DLQ reuse, hash repair, rollback, custom message models, and separate Talkto database connections.

### Atomic Redispatch Claiming

- Added an internal durable dispatch-claim service for retry redispatch, dead-letter reprocess, stale lock recovery, result callback queueing, and panel redispatch actions.
- Hardened retry and DLQ commands so eligible rows are locked, rechecked, moved out of their retry/reprocess candidate pools, and tagged with a dispatch claim before jobs are queued.
- Corrected dispatch claims to carry a recoverable `locked_at` timestamp, expanded stale recovery to requeue orphaned `dispatch-claim:*` rows, and prevented `--force` DLQ reprocess from stealing active `reprocessing` work.
- Hardened dispatch-failure compensation so only rows still carrying the same claim token are restored, avoiding stranded `reprocessing` dead letters and stale recovered rows when queue dispatch fails.
- Updated callback queueing to tag the callback message while dispatch is pending and, when queue dispatch fails, verify the exact callback claim in one Talkto transaction before recording `result_callback_queue_failed` and clearing the claim.
- Standardized dual message/dead-letter transactions on the canonical `TalktoMessage` then `TalktoDeadLetter` lock order for DLQ claim, compensation, store/refresh, and successful reprocess finalization paths.
- Added worker-side duplicate defense so stale duplicate outgoing jobs no-op after terminal advancement instead of writing misleading skipped attempts.
- Added focused regression coverage for retry, DLQ reprocess, stale recovery, result callback queueing, panel-compatible claim state, dispatch failure compensation, wrong-service rows, duplicate jobs, deterministic actor interleavings, canonical DLQ lock ordering, and two Laravel SQLite connections pointing at the same database.

### Connection-Aware Transactions

- Hardened Talkto-owned transactions and row locks so message, attempt, event, dead-letter, retry, callback, stale recovery, repair, prune, and panel mutation writes run on the resolved Talkto model connection instead of Laravel's default transaction connection.
- Added an internal connection compatibility guard for atomic Talkto writes; incompatible custom Talkto model connections now fail clearly before partial message/event writes.
- Added focused regression coverage for default storage, separate Talkto storage, callback dispatch locking, rollback on event-write failure, custom model connection overrides, and incompatible custom event connections.

### Panel Usability

- Added a Talkto Panel completion-state filter beside the existing detailed status filter, with `Completed` showing canonical successful final states and `Not completed` showing pending, in-progress, failed, dead-lettered, and other non-success states.

### Float Payload Hash Hardening

- Added deterministic Talkto JSON encoding for payload hashing and the default HTTP transport so valid JSON floats such as `79.95` and `77.95` are stable across PHP `serialize_precision` settings.
- Added a sender-side integrity guard that fails stale stored payload hashes locally as `stored_payload_hash_mismatch` before any HTTP request is sent.
- Added `talkto:repair-payload-hash` for explicit, single-message, dry-run-first repair of old failed outgoing rows with payload hash mismatch evidence.
- Hardened `talkto:repair-payload-hash` so it only repairs stopped outgoing rows in `failed_final` or `dead_lettered`, refuses `failed_retryable`, and reports deterministic JSON encoding failures without leaking exception details.
- Documented the root cause, safe recovery flow, deployment order, and the defense-in-depth recommendation to keep PHP `serialize_precision=-1`.

### HTTP SSL Options

- Added audit and panel visibility for outgoing HTTP SSL verification options, including disabled verification, custom CA bundle status, and ignored CA bundle warnings.
- Documented secure defaults, per-target CA bundle overrides, and custom HTTP client support for package-managed SSL options.

### Durable Result Callbacks

- Documented durable queued result callback delivery, including automatic callback queueing after incoming processing, retry/DLQ-compatible callback messages, and `TALKTO_CALLBACKS_AUTO_DISPATCH`.
- Clarified that `ResultCallbackSenderContract::sendResult()` now creates or reuses an outgoing durable callback message instead of performing immediate callback HTTP delivery.
- Hardened durable callback queue dispatch deduplication by locking the callback message row and checking queued/queue-failed events before dispatching `SendTalktoMessage`.
- Updated callback examples, testing guidance, troubleshooting, public API notes, and upgrade notes for the queued callback lifecycle.

### Final Cleanup And Callback State Safety

- Normalized release hygiene by keeping generated phase ZIP/manifest artifacts ignored and cleaning public maintainer-note wording.
- Added callback stale/out-of-order safety so fresh but delayed result callbacks cannot downgrade completed/succeeded or final-failure states.
- Moved remaining panel action result messages into publishable English translations and marked optional panel support/services as internal implementation details.
- Added focused tests for callback stale handling, panel action translations, internal annotations, and repository metadata hygiene.

### Panel Localization And Filters

- Added English panel translation lines under the `talkto::panel` namespace, with publish tags `laravel-talkto-translations` and `talkto-translations`.
- Updated package panel views to use translation keys for operator-facing labels while keeping raw technical identifiers as data.
- Improved message filters with direction/status selects, datetime-local created range fields, safer filter normalization, and focused localization/filter-control tests.

### Release Readiness

- Added the final pre-tag review checkpoint covering package metadata, secure defaults, public API/docs consistency, CI/release gates, and generated artifact hygiene.
- Ignored generated phase review ZIP/manifest artifacts and removed the stale uppercase smoke-test doc in favor of `docs/smoke-tests.md`.
- Confirmed no runtime behavior or security defaults changed during the final review.

### Testing

- Added a local two-service E2E smoke test covering v2 signing, nonce replay protection, idempotency, payload tamper rejection, and signed result callback replay safety without external servers.
- Added `docs/smoke-tests.md` with the focused smoke test command and local-only safety expectations.

### Internal Refactor

- Added internal backed enums for Talkto lifecycle statuses and directions, plus a shared internal model resolver, while keeping persisted database values and public APIs unchanged.
- Added focused status compatibility and model resolver tests covering default/custom model resolution and touched send, receive, process, retry, and dead-letter flows.

### Public API Boundary

- Defined the documented public API boundary for contracts, DTOs, commands, config, publish tags, extension points, exceptions, and internal implementation categories.
- Added `@internal` annotations to representative implementation-only controllers, jobs, pipelines, and runtime services while keeping documented contracts and public factories unmarked.
- Clarified the documented v2 nonce setting as `talkto.security.replay_protection.require_nonce_for_v2`.

### Documentation

- Reworked the README into a concise public entry point for what Laravel Talkto does, when to use it, Packagist installation with `composer require mrezdev/laravel-talkto`, secure v2 configuration, sending/receiving commands, callbacks, retry/DLQ, observability, and support links.
- Added a cleaner documentation map and expanded production-oriented installation, configuration, security, and production hardening guidance.
- Added architecture diagrams and outgoing-only, incoming-only, and bidirectional callback examples using the package's current public APIs and contracts.
- Expanded troubleshooting guidance with safe fixes for install, publishing, migration, signature, nonce, idempotency, queue, callback, DLQ, panel, and Packagist issues.
- Aligned security, support, and upgrade guidance with v2 defaults, required v2 nonces, nonce hash storage, and v1 legacy/manual opt-in compatibility.

### Changed

- Expanded GitHub Actions release validation to cover explicit PHP/Laravel/Testbench combinations for PHP 8.2/8.3/8.4 on Laravel 12 and PHP 8.3/8.4 on Laravel 13.
- Hardened repository line-ending attributes and added a focused Windows Pint job to catch CRLF formatting drift.
- Added Composer release validation scripts and updated release readiness documentation/checklists for local gates, CI matrix coverage, and manual GitHub/Packagist follow-up steps.
- Changed package license to MIT for public Composer/Packagist distribution.
- Because Laravel Talkto is still pre-1.0 and has no known production adopters, this release intentionally hardens the default security profile: new installs now use v2 signatures only and require v2 nonce replay protection by default. v1 remains available only as an explicit legacy/manual opt-in path.
- Added an independent nonce replay ledger and migration that stores nonce hashes/fingerprints instead of raw nonces, payloads, or responses.

### Reliability Fixes

- Hardened incoming idempotency, retry lock clearing, and callback success state cleanup.

### Scaffolding Generators And Optional Panel

- Added Artisan scaffolding generators for outgoing commands, transactional outgoing commands, incoming commands, and integration shortcut flows.
- Added the optional Talkto Panel with message dashboard, message detail and trace views, safe retry and dead-letter reprocess actions, connection health, optional active health checks, and publishable Blade/Tailwind views.
- Kept the panel disabled by default and active health checks opt-in only.
- Hardened panel JSON responses so payloads and responses are hidden by default and sensitive values are redacted when display is explicitly enabled.
- Made panel route loading route-cache friendly.

### Release Readiness Summary

- Aligned public contracts, README examples, host stubs, and docs with the real incoming result and outgoing factory APIs.
- Added immutable data objects for envelopes, incoming command results, and result callback envelopes.
- Added the generic signed result callback sender/receiver runtime while preserving host override contracts.
- Added read-only trace reporting, retry/DLQ hardening, dead-letter lifecycle helpers, and focused retry policy diagnostics.
- Added centralized redaction, text redaction hardening, and the read-only `talkto:security-audit` command.
- Added final release polish for CI, package metadata, release docs, security/support guidance, and repository audit tests.

- Corrected the incoming command result contract to use non-conflicting instance accessors and aligned host stubs/docs with the real result API.
- Hardened incoming idempotency around `message_id` using the existing message ledger.
- Added retry/backoff state, due retry command behavior, and conservative HTTP retry classification.
- Added Dead Letter Queue storage and `talkto:dlq-reprocess`.
- Added incoming handler registry support for config and programmatic handlers.
- Added outgoing target registry support for config, aliases, legacy target keys, and programmatic targets.
- Moved receive, incoming processing, and outgoing send orchestration into pipelines without changing public commands or routes.
- Added versioned signatures. Defaults now use v2-only signing, with v1 available only as explicit legacy/manual opt-in.
- Added read-only observability metrics, health summaries, and `talkto:report`.
- Hardened release docs, publish tags, CI validation, public API inventory, and compatibility tests.
- Added package extraction and release preparation notes for maintainers without changing production behavior.
- Removed the static package `version` field so future package versions can come from Git tags.
- Added focused standalone tests for config defaults and security services.
- Added package install-experience documentation, production readiness notes, troubleshooting notes, and upgrading guidance.
- Added `phpunit.xml.dist` for fresh package checkouts.
- Added a generic install-experience test for provider loading, safe defaults, and host-class independence.
- Added neutral package metadata and contributor/license placeholders.
- Added repository metadata, CI workflow template, release docs, and repository metadata tests.
- Added production release hardening docs, upgrade guidance, release checklist, publish tag aliases, and package release smoke tests.

## 0.1.0-alpha

- Initial package skeleton.
- Added service provider.
- Added default config skeleton.
- Added placeholder package directories.
- Added migration loading toggle for safer installation into existing apps.
- Added installation notes for apps with local Talkto prototypes.
