# Public Release Readiness

The package is licensed under MIT and can be prepared for public Composer/Packagist distribution once the release checklist passes.

## Public Release Blockers

- Security disclosure contact.
- Maintainer identity.
- Public support policy.
- Packagist package ownership.
- CI pass on supported PHP versions.
- Complete installation, upgrade, security, and release docs.
- Stable public API review.
- Host business leakage review.
- Secret and credential review.

## Public Checklist

- License is MIT in `composer.json` and `LICENSE.md`.
- Maintainers publish security and support contacts.
- Public package name is final.
- Packagist package ownership is confirmed.
- Documentation is reviewed for private details.
- Release readiness checks pass: `composer validate --strict`, `composer audit`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, and `vendor/bin/pest`.
- Host/staging checks pass for `php artisan talkto:audit-security`, route throttling, panel protection, stale recovery dry-runs, and pruning dry-runs.
