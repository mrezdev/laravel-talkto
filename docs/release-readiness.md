# Release Readiness

Use this checklist before tagging a package release or publishing to Composer/Packagist.

## Package Quality Gate

Run these from the package repository root:

```bash
composer validate --strict
composer audit
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
```

If dependencies are not installed yet:

```bash
rm -f composer.lock
composer update --prefer-dist --no-interaction --no-progress --with-all-dependencies
```

This library package does not commit `composer.lock`; dependency resolution should follow Composer constraints for the PHP/Laravel-compatible matrix being tested.

Fix formatting with:

```bash
vendor/bin/pint
```

Composer helper scripts are available for the same local workflow:

```bash
composer test
composer analyse
composer format:test
composer check:composer
composer check:audit
composer run release:check
```

## CI Gate

Confirm GitHub Actions runs the supported package matrix:

- PHP 8.2, PHP 8.3, and PHP 8.4 with Laravel 12 components and Orchestra Testbench 10.
- PHP 8.3 and PHP 8.4 with Laravel 13 components and Orchestra Testbench 11.
- The focused Windows Pint job on `windows-latest`.

The workflow should run Composer validation, dependency resolution with matrix constraints, Composer audit, Pint, PHPStan, and Pest. The Windows job is intentionally focused on `vendor/bin/pint --test` equivalent formatting validation.

## Security And Production Gate

In a host test application or staging environment, verify:

- `php artisan talkto:audit-security` reports no deployment-blocking failures.
- New integrations use the strict v2 receive profile where possible.
- `TALKTO_REQUIRE_SIGNATURE=true`.
- `TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2` for new peers after rollout.
- `TALKTO_REQUIRE_V2_NONCE=true` after every peer sends v2 nonces.
- Package receive routes are disabled unless the host intentionally enables them.
- Enabled package routes include throttle or equivalent rate-limit middleware.
- The panel is disabled, or protected by authenticated/admin middleware and a narrow gate.
- Payload and response visibility are disabled unless operators are explicitly allowed to inspect them.

## Operations Gate

Before production traffic, verify:

- Queue workers are running for outgoing and incoming jobs.
- Operators know how to pause sends and receives through host-owned controls.
- Retry and dead-letter runbooks are documented.
- `php artisan talkto:recover-stale --dry-run` is reviewed before any recovery run.
- `php artisan talkto:prune --dry-run` is reviewed before production pruning.
- Retention windows match the host's operational and compliance needs.
- Message trace/report commands are safe for operator use and redact expected secrets.

## Release Metadata Gate

Confirm:

- `composer.json` has no static `version` field.
- `composer.json` and `LICENSE.md` use the MIT license.
- `CHANGELOG.md` is current.
- `README.md`, [Public API](PUBLIC_API.md), [Security](security.md), [Production hardening](production-hardening.md), [Panel](panel.md), and [Extending](extending.md) are current.
- Repository metadata has no credentials, private support addresses, production URLs, or host-only business terms.
- Packagist ownership and repository visibility are intentional.

## Manual External Release Steps

These actions are external and must not be represented as completed by CI or Codex:

- Create the GitHub Release for the intended tag.
- Verify Packagist auto-update points to the intended tag.
- Verify Packagist package metadata after release.
- Set or verify GitHub topics: `laravel`, `php`, `hmac`, `service-to-service`, `webhook`, `outbox`, `inbox`, `idempotency`, `retry`, `dead-letter`, `dlq`, `observability`, `laravel-package`, and `distributed-systems`.

Create a Git tag only after the quality, CI, security, operations, and metadata gates pass.
