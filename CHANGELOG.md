# Changelog

## Unreleased

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

### Private Release Readiness Summary

- Aligned public contracts, README examples, host stubs, and docs with the real incoming result and outgoing factory APIs.
- Added immutable data objects for envelopes, incoming command results, and result callback envelopes.
- Added the generic signed result callback sender/receiver runtime while preserving host override contracts.
- Added read-only trace reporting, retry/DLQ hardening, dead-letter lifecycle helpers, and focused retry policy diagnostics.
- Added centralized redaction, text redaction hardening, and the read-only `talkto:security-audit` command.
- Added final private release polish for CI, package metadata, release docs, security/support guidance, and repository audit tests.

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
- Added actual private package extraction docs, first private repository commit and tag plans, and the host VCS migration next-step plan.
- Documented that P.49A2 creates a package-only seed with no production behavior change.
- Removed the static package `version` field so future package versions can come from Git tags.
- Added focused standalone tests for config defaults and security services.
- Added package install-experience documentation, production readiness notes, troubleshooting notes, and upgrading guidance.
- Added `phpunit.xml.dist` for fresh package checkouts.
- Added a generic install-experience test for provider loading, safe defaults, and host-class independence.
- Added neutral package metadata and contributor/license placeholders.
- Added private repository metadata, CI workflow template, release docs, and repository metadata tests.
- Added production release hardening docs, upgrade guidance, release checklist, publish tag aliases, and package release smoke tests.

## 0.1.0-alpha

- Initial package skeleton.
- Added service provider.
- Added default config skeleton.
- Added placeholder package directories.
- Added migration loading toggle for safer installation into existing apps.
- Added installation notes for apps with local Talkto prototypes.
