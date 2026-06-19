# Release Process

Package versions come from Git tags. `composer.json` intentionally has no static `version` field, and there should be no version in composer.json.

## Release Flow

1. Confirm `composer.json` and `LICENSE.md` use the MIT license.
2. Confirm repository visibility and Packagist ownership are intentional.
3. Update `CHANGELOG.md`.
4. Run `composer validate --strict`.
5. Run `composer audit`.
6. Run `composer install --prefer-dist --no-interaction --no-progress` when dependencies are not installed.
7. Run `vendor/bin/pint --test`.
8. Run `vendor/bin/phpstan analyse`.
9. Run `vendor/bin/pest`.
10. Run `php artisan talkto:audit-security` in a host test app when applicable.
11. Review docs, repository metadata, and leakage checks.
12. Create a Git tag only after tests pass.
13. Verify a host application can require the tag in a non-production branch.

## Tag Names

Use semantic version tags:

- `v0.1.0`
- `v0.2.0`
- `v1.0.0`

Use pre-release tags only when the owner wants an explicit preview channel, for example `v0.2.0-alpha.1`.

## Public Release Gate

A public release requires the MIT license files, security disclosure contact, maintainer identity, public support policy, Packagist ownership, stable API review, passing CI, and complete docs.
