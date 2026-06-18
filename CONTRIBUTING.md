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

For focused work, run the smallest relevant feature test first, then the nearby regressions. Examples:

```bash
vendor/bin/pest tests/Feature/SecurityV2Test.php
vendor/bin/pest tests/Feature/ObservabilityReportTest.php
vendor/bin/pest tests/Feature/PipelineArchitectureTest.php
```

Run `php -l` on changed PHP files. Do not run destructive database commands such as `migrate:fresh`, `db:wipe`, `migrate:reset`, or seeders against shared data.

## Compatibility

- Keep public config keys, command names, route names, contracts, and namespaces backward-compatible unless a major release explicitly approves a change.
- Prefer additive publish tags and aliases over replacing existing installation surfaces.
- Do not add project-specific logic or host-only business terms.
- Keep runtime behavior changes out of documentation-only or release-hardening patches.

## Review Artifacts

When a phase asks for a changes-only ZIP, include only changed or created project files. Do not include `vendor/`, `node_modules/`, caches, or full-project archives.

## Documentation

Update README.md and the relevant file under `docs/` when a public API, config key, security behavior, or install step changes.
