# Upgrade Guide

The detailed Laravel Talkto upgrade guide lives at [docs/upgrading.md](docs/upgrading.md).

Review that guide before updating a host application. It covers safe rollout steps, v2 signature and nonce defaults, durable queued result callback behavior, migration review, rollback planning, and package compatibility checks.

The atomic redispatch claiming patch is an internal reliability hardening for retry dispatch, DLQ reprocess, stale recovery, durable callbacks, and panel redispatch actions. It does not require a migration or public API/config change. After deployment, a queued-but-not-started redispatch may temporarily show `locked_by` beginning with `dispatch-claim:` and a populated `locked_at`; queue workers replace that marker with the normal worker lock when they start, and `talkto:recover-stale` can recover old orphaned dispatch claims if a process crashes before queue dispatch. Callback queue-dispatch failure cleanup is now atomic: the package verifies the exact callback claim, records `result_callback_queue_failed`, and clears that claim in one Talkto transaction. DLQ paths that touch both resources lock the original `TalktoMessage` before the `TalktoDeadLetter`.

Deploy this patch to all app, scheduler, and worker processes for the service together. Mixed old/new redispatch command or panel processes are not a supported steady state because old processes do not participate in the dispatch-claim protocol.

For the float payload hash hardening patch, deploy the updated package to both sender and receiver services before reprocessing old `payload_hash_mismatch` failures. Restart PHP-FPM and long-running queue workers so every process uses the deterministic encoder. Old failed outgoing rows are not repaired automatically; inspect and repair one row explicitly with `php artisan talkto:repair-payload-hash <message-id> --confirm --reason="..."`, then use the existing retry or DLQ reprocess flow deliberately.

When adopting HTTP SSL options, keep `TALKTO_HTTP_VERIFY_SSL=true` in production and use `TALKTO_HTTP_CA_BUNDLE` or per-target `ca_bundle` for private CAs. Custom HTTP transports should implement `TalktoHttpClientWithOptions` when they need to receive these package-managed options.
