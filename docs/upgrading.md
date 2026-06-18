# Upgrading

Use this guide when moving a host application to a newer Laravel Talkto package copy.

## Before Updating

- Review the changelog and docs for changed config keys, public contracts, migrations, jobs, and route behavior.
- Use Git tags as package version boundaries; do not reintroduce a static `version` field in `composer.json`.
- Confirm host wrappers still own domain command names, data mapping, writes, and callback side effects.
- Back up any host-owned config changes before republishing package config.
- Compare published migrations with host-owned tables before enabling package migrations.

## Safe Upgrade Flow

1. Update the package copy or dependency in a branch.
2. Run package syntax checks.
3. Install package-local development dependencies only when the phase explicitly allows it, then run package tests.
4. Run host compatibility tests in a testing environment.
5. Run a local end-to-end exchange with non-production peers and secrets.
6. Review message, attempt, event, and callback behavior before staging rollout.

## Config Changes

Published config files are host-owned after publishing. When the package adds new config keys, copy only the new generic keys into the host config and keep host-specific peer names, handlers, and secrets in the host.

## Migration Changes

Package migrations are opt-in. Existing hosts should not enable new package migrations until table ownership and naming conflicts have been reviewed.

## Contract Changes

Public contracts should remain stable across patch releases. If a contract must change, add an adapter or compatibility layer in the host before removing old method signatures.

## Rollback

Keep a rollback path for queued jobs and in-flight messages. A host should be able to pause sends, pause receives, and retry or mark failed messages after restoring the previous package version.
