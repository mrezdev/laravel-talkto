# Release Checklist

Use this checklist before tagging or publishing Laravel Talkto.

## Code And Metadata

- Run `composer validate --strict`.
- Run `php -l` on changed PHP files.
- Run focused Pest tests for changed areas and core regressions.
- Run the full package test suite before creating a tag.
- Confirm `vendor/`, `node_modules/`, caches, and local ZIP artifacts are not included in the release.
- Confirm `composer.json` package name, description, license, autoload, dev autoload, and Laravel provider discovery are correct.

## Installation Surface

- Confirm config publishes with `laravel-talkto-config` and `talkto-config`.
- Confirm migrations publish with `laravel-talkto-migrations` and `talkto-migrations`.
- Confirm package discovery loads `LaravelTalktoServiceProvider`.
- Confirm routes and migrations remain opt-in by default.
- Confirm default config contains no production URLs, secrets, or host-only classes.

## Runtime Checks

- Confirm queue workers are configured for send and incoming processing jobs.
- Confirm scheduler entries for `talkto:retry-failed` and reporting are documented.
- Run a v1 signed send/receive test.
- Run a v2 signed send/receive test if enabling v2.
- Run duplicate `message_id` idempotency test.
- Run `talkto:retry-failed --dry-run`.
- Run `talkto:dlq-reprocess --dry-run` when DLQ is enabled.
- Run `talkto:report --json`.
- Run `talkto:security-audit` in a host test app when package config is published.
- Run `talkto:trace` on smoke messages where applicable and confirm output is sanitized.

## Release

- Review `CHANGELOG.md`, `UPGRADE.md`, `SECURITY.md`, and README install docs.
- Verify published docs and examples contain no real secrets, raw signatures, private credentials, or host-only language.
- Tag with the intended semantic version once release versioning is approved.
- Push the tag only after local tests pass, then verify CI passes.
- For Packagist or public release, confirm license, repository visibility, security contact, and package ownership first.
