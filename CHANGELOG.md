# Changelog

## Unreleased

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
