# Upgrade Guide

The detailed Laravel Talkto upgrade guide lives at [docs/upgrading.md](docs/upgrading.md).

Review that guide before updating a host application. It covers safe rollout steps, v2 signature and nonce defaults, durable queued result callback behavior, migration review, rollback planning, and package compatibility checks.

For the float payload hash hardening patch, deploy the updated package to both sender and receiver services before reprocessing old `payload_hash_mismatch` failures. Restart PHP-FPM and long-running queue workers so every process uses the deterministic encoder. Old failed outgoing rows are not repaired automatically; inspect and repair one row explicitly with `php artisan talkto:repair-payload-hash <message-id> --confirm --reason="..."`, then use the existing retry or DLQ reprocess flow deliberately.

When adopting HTTP SSL options, keep `TALKTO_HTTP_VERIFY_SSL=true` in production and use `TALKTO_HTTP_CA_BUNDLE` or per-target `ca_bundle` for private CAs. Custom HTTP transports should implement `TalktoHttpClientWithOptions` when they need to receive these package-managed options.
