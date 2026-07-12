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

## One-Time Outgoing Payload Freezing

This patch freezes new outgoing payloads before hashing and persistence, with repeated supported object instances converted once per freeze operation. It is a code-only hardening: no migrations, config keys, commands, routes, signature headers, or hash algorithm changes are required. Already-primitive JSON payloads keep their existing deterministic payload hashes.

Deploy the patch to app, scheduler, and queue-worker processes together. Restart long-running workers so all new outgoing rows, durable callback rows, retry sends, DLQ reprocesses, and repair checks use the same frozen stored payload behavior.

Review host payload builders for runtime-only values. Arrays, JSON primitives, collections, `Arrayable`, `JsonSerializable`, backed enums, Carbon/JSON-serializable date objects, `stdClass`, and public-property DTOs are supported. Native `DateTimeInterface` values should be formatted explicitly by the host. Resources, closures, generators, traversable/internal hidden-state objects, pure enums, implicit `Stringable` objects, invalid UTF-8, non-finite floats, circular references, and excessive nesting now fail before a message row is created instead of failing later or producing split hash/body behavior.

Rollback is straightforward for package code because no schema or config state is introduced. If a rollback is necessary, pause sends and workers first; rows created by the patched version contain ordinary JSON-safe payloads and remain readable by the previous package version.

## Float Payload Hash Hardening

This patch makes Talkto-controlled payload hashing and the default HTTP transport independent of the ambient PHP `serialize_precision` setting. Deploy the patched package to both sender and receiver services before retrying or DLQ-reprocessing old rows that failed with `payload_hash_mismatch`.

Mixed versions have a limitation: a patched service calculates deterministic hashes, while an unpatched peer may still calculate hashes with its local `serialize_precision`. For messages containing decimal floats such as `79.95`, either mixed direction can still reject if one side is unpatched and the processes use different precision settings. The safe rollout is:

1. Pause retries or DLQ reprocess for affected float-bearing messages.
2. Deploy the patched package to receivers and senders.
3. Restart PHP-FPM and long-running queue workers.
4. Confirm new float messages verify successfully.
5. Repair old stale outgoing rows one at a time with `talkto:repair-payload-hash`.
6. Reprocess deliberately through `talkto:retry-failed` or `talkto:dlq-reprocess`.

Use `serialize_precision=-1` in PHP configuration as defense in depth, but do not rely on matching INI settings as the primary correctness mechanism.

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
