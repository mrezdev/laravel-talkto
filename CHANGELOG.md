# Changelog

## Unreleased

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
- Added versioned signatures with backward-compatible v1 defaults and opt-in v2 signing.
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
