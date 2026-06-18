# Contributing

Keep the package reusable across Laravel applications.

## Boundaries

- Package code may own signing, verification, payload hashing, message lifecycle, attempts, events, idempotency checks, queues, and generic contracts.
- Host applications own command names, payload mapping, model lookup, writes, callbacks with domain side effects, dashboards, and rollout decisions.
- Do not add host-only business terms, database names, secrets, raw signatures, or environment values to package source, tests, or docs.

## Local Checks

```bash
cd packages/talkto-reliable
composer install
vendor/bin/pest
```

`phpunit.xml.dist` is committed for fresh checkouts. If the package-local `vendor/` directory is absent, install development dependencies in the package directory before running tests.

## Documentation

Update README.md and the relevant file under `docs/` when a public API, config key, security behavior, or install step changes.
