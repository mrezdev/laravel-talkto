# Smoke Tests

Laravel Talkto includes local smoke coverage that simulates two services in one Testbench process. The smoke test does not start external servers, use real URLs, use real secrets, or require two physical Laravel applications.

The local two-service smoke test verifies:

- v2 source-to-target signing with timestamp, payload hash, signature, and nonce headers.
- target-side signature, timestamp, payload hash, command allowlist, and nonce replay checks.
- incoming message storage, handler execution, idempotent duplicate handling, replay rejection, and tamper rejection.
- signed result callback delivery back to the source where the current package callback APIs support it.
- callback nonce consumption, callback replay rejection, and protection against replayed older callback status regression.

Run the focused smoke suite from the package root:

```bash
vendor/bin/pest tests/Feature/LocalTwoServiceE2ESmokeTest.php
```

The test uses placeholder services named `website-service` and `inventory-service`, placeholder secrets, `.test` URLs intercepted in memory, and generic `catalog:sync-product` payloads only. It must remain network-free and production-secret-free.
