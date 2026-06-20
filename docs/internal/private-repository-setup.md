# Private Repository Setup

This package is prepared for a future private repository, but P.49A does not create that repository or push code anywhere.

## Setup Steps

1. Create a private repository using the owner-approved repository name and namespace.
2. Copy the contents of `packages/laravel-talkto` into the new repository root.
3. Do not copy host application files, host routes, host config, host database files, generated files, review exports, or local environment files.
4. Verify `composer.json`, `README.md`, `LICENSE.md`, and `CHANGELOG.md` are present.
5. Run `composer validate --strict`.
6. Run `composer install --prefer-dist --no-interaction --no-progress`.
7. Run `vendor/bin/pest`.
8. Enable branch protection when available.
9. Keep the owner-approved MIT license unless the owner approves a different license.
10. Tag releases only after Composer validation, package tests, docs review, and leakage checks pass.

## Repository Scope

The private repository should contain only the package root. Host applications keep command mapping, payload construction, model lookup, writes, traffic gates, operational runbooks, and deployment decisions.

## Before The First Tag

- Confirm the package name and namespace are owner-approved.
- Confirm `composer.json` has no static `version` field.
- Confirm release notes are current.
- Confirm repository metadata contains no real credentials or private support addresses.
- Confirm docs use placeholders for private repository URLs and credentials.
