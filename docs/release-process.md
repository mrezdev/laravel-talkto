# Release Process

Package versions come from Git tags. `composer.json` intentionally has no static `version` field, and there should be no version in composer.json.

## Private-First Release Flow

1. Confirm the repository is private.
2. Confirm the license remains proprietary unless the owner approved a different license.
3. Update `CHANGELOG.md`.
4. Run `composer validate --no-check-publish`.
5. Run `composer install --prefer-dist --no-interaction --no-progress` when dependencies are not installed.
6. Run `vendor/bin/pest`.
7. Review docs, repository metadata, and leakage checks.
8. Create a private Git tag only after tests pass.
9. Verify a host application can require the tag in a non-production branch.

## Tag Names

Use semantic version tags:

- `v0.1.0`
- `v0.2.0`
- `v1.0.0`

Use pre-release tags only when the owner wants an explicit preview channel, for example `v0.2.0-alpha.1`.

## Public Release Gate

A public release requires a license decision, security disclosure contact, maintainer identity, public support policy, Packagist decision, stable API review, passing CI, and complete docs.
