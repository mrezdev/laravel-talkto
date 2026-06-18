# Production Readiness

Talkto Reliable gives Laravel services a secure delivery layer, but each host application still owns its rollout plan, peer configuration, operational controls, and domain behavior.

## Package Release Checklist

- Confirm `composer.json` has the package name, description, license, authors, keywords, autoloading, Laravel provider discovery, and compatible PHP/Laravel constraints.
- Keep package versions out of `composer.json` for Packagist readiness; release versions should come from Git tags.
- Keep `phpunit.xml.dist`, `tests/TestCase.php`, and `tests/Pest.php` committed so a fresh package checkout has a documented test entry point.
- Run package syntax checks and package tests after installing package-local development dependencies.
- Run focused compatibility tests in each host application that consumes the local package copy.
- Verify package docs do not contain secrets, environment values, raw signatures, or host-only business terms.
- Review README, installation, configuration, security, testing, troubleshooting, and upgrading notes before tagging a release.
- Do not publish a public release until the license and repository location are intentionally selected.

## Release Metadata

Packagist should read versions from Git tags such as `v0.1.0`, not from a committed `version` field. Keep the package license conservative until the project owner approves a public license and repository metadata.

## Host Rollout Checklist

- Keep routes and migrations disabled unless the host has confirmed it needs the package-owned versions.
- Use non-production services, queues, and databases for local and staging verification.
- Configure one explicit outgoing peer and one explicit incoming source at a time.
- Store shared secrets only in environment-specific secret storage.
- Require signatures and timestamp checks unless a local-only test deliberately disables them.
- Require idempotency keys for commands that may be retried.
- Add monitoring around queued sends, queued receives, failed callbacks, retry counts, and stale pending messages.
- Keep host mappers, handlers, writes, and callback side effects inside the host application.

## Operational Signals

Track message status, attempt count, last error, payload hash mismatch events, invalid signature events, command allowlist failures, duplicate idempotency keys, callback failures, and queue latency.

## Before Enabling Real Traffic

Run a local end-to-end exchange, a staging exchange with test secrets, and a rollback drill. Confirm the host can pause sends, pause receives, retry failed jobs, and inspect message history without exposing secrets.
