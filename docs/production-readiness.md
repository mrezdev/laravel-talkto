# Production Readiness

Laravel Talkto gives Laravel services a secure delivery layer, but each host application still owns its rollout plan, peer configuration, operational controls, and domain behavior.

## Package Release Checklist

- Confirm `composer.json` has the package name, description, license, authors, keywords, autoloading, Laravel provider discovery, and compatible PHP/Laravel constraints.
- Keep package versions out of `composer.json` for Packagist readiness; release versions should come from Git tags.
- Keep `phpunit.xml.dist`, `tests/TestCase.php`, and `tests/Pest.php` committed so a fresh package checkout has a documented test entry point.
- Run package quality checks after installing package-local development dependencies: `composer validate --strict`, `composer audit`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, and `vendor/bin/pest`.
- Run focused compatibility tests in each host application that consumes the local package copy.
- Verify package docs do not contain secrets, environment values, raw signatures, or host-only business terms.
- Review README, installation, configuration, security, testing, troubleshooting, and upgrading notes before tagging a release.
- Confirm the MIT license, repository location, and Packagist package ownership before publishing a public release.

## Release Metadata

Packagist should read versions from Git tags such as `v0.1.0`, not from a committed `version` field. The package license is MIT; keep repository metadata aligned with the published package identity.

## Host Rollout Checklist

- Keep routes and migrations disabled unless the host has confirmed it needs the package-owned versions.
- If services share one Talkto database, give every service a stable `TALKTO_SERVICE` value and keep current-service storage enforcement enabled.
- Use non-production services, queues, and databases for local and staging verification.
- Configure one explicit outgoing peer and one explicit incoming source at a time.
- Store shared secrets only in environment-specific secret storage.
- Require signatures and timestamp checks unless a local-only test deliberately disables them.
- Require idempotency keys for commands that may be retried.
- Add monitoring around queued sends, queued receives, failed callbacks, retry counts, and stale pending messages.
- Keep host mappers, handlers, writes, and callback side effects inside the host application.

## Operational Signals

Track message status, attempt count, last error, payload hash mismatch events, invalid signature events, command allowlist failures, duplicate idempotency keys, callback failures, and queue latency.

## Optional Talkto Panel

- Keep the panel disabled unless operators need it.
- Protect the panel with authenticated middleware and a narrow authorization gate.
- Keep payload and response display disabled in production unless there is a specific operational need.
- Prefer active health checks only for safe, explicit health endpoints.
- Keep `TALKTO_PANEL_CURRENT_SERVICE_ONLY=true` unless the panel is a trusted central observer.
- Do not expose secrets in active health URLs.
- Publish panel views if the host needs UI customization or production-specific wording.

## Before Enabling Real Traffic

Run a local end-to-end exchange, a staging exchange with test secrets, and a rollback drill. Confirm the host can pause sends, pause receives, retry failed jobs, and inspect message history without exposing secrets.
