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

Preferred outgoing target URL keys are `base_url`, `receive_endpoint`, `callback_endpoint`, `receive_url`, and `callback_url`. The `url` and `endpoint` aliases remain supported for compatibility.

Incoming and outgoing peer shared secrets can use `secret`; `signing_secret` is also supported as a shared signing secret key where host config prefers that name.

Outgoing HTTP TLS verification is enabled by default through `talkto.http.verify_ssl`. Hosts that use private certificate authorities should copy the new `ca_bundle` settings and keep verification enabled in production.

## V2 Security Defaults

New installs should use v2 signatures, required v2 nonces, and replay protection:

```dotenv
TALKTO_SIGNATURE_VERSION=v2
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
TALKTO_REQUIRE_V2_NONCE=true
TALKTO_REPLAY_PROTECTION_ENABLED=true
```

Use v1 only for a documented legacy compatibility window.

## Durable Result Callbacks

Result callbacks are durable and queued. `ResultCallbackSenderContract::sendResult()` creates or reuses an outgoing `talkto.result` message and dispatches `SendTalktoMessage`; it does not perform immediate callback HTTP delivery.

Incoming handlers usually return a `TalktoIncomingCommandResult` and let automatic callback dispatch queue delivery. Manual `sendResult()` remains supported for advanced flows and duplicate queue attempts for the same original message/status reuse the durable callback message where possible.

## Migration Changes

Package migrations are opt-in. Existing hosts should not enable new package migrations until table ownership and naming conflicts have been reviewed.

## Contract Changes

Public contracts should remain stable across patch releases. If a contract must change, add an adapter or compatibility layer in the host before removing old method signatures.

## Rollback

Keep a rollback path for queued jobs and in-flight messages. A host should be able to pause sends, pause receives, and retry or mark failed messages after restoring the previous package version.
