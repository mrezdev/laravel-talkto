# Host Integration Template

Use this template when adding Laravel Talkto to a Laravel application.

This page is the map. The detailed onboarding kit is split by concern:

- `new-service-onboarding.md`
- `local-http-e2e-template.md`
- `command-contract-template.md`
- `callback-contract-template.md`
- `recovery-monitoring-template.md`
- `production-rollout-template.md`
- `testing.md`
- `troubleshooting.md`

Copy/paste starter files live in `stubs/host/`. Treat them as examples, not generated host files.

## Host Inputs

- service name
- peer service names
- outgoing peer URLs and endpoints
- secret environment variable names
- allowed command names
- handler class names
- idempotency requirements
- route and migration ownership

## Package Responsibilities

- message, attempt, and event lifecycle
- envelope creation
- signing and verification
- payload hashing
- source and command allowlists
- idempotency and duplicate checks
- queued send and receive processing
- generic contracts for handlers and callbacks

## Host Responsibilities

- business command names
- payload mapping
- model lookup and writes
- write safety flags
- handler implementations
- callback side effects
- monitoring presentation
- production rollout decisions

## Rollout Steps

1. Publish config.
2. Keep package routes and migrations disabled for existing hosts.
3. Configure peers with non-production secrets.
4. Bind host handlers.
5. Run package tests.
6. Run focused host tests.
7. Run local-only end-to-end tests.
8. Enable package-owned routes or migrations only after conflict review.

## Safety Boundaries

- Keep domain logic, model mapping, and writes in the host.
- Keep real secrets outside committed files.
- Keep production traffic disabled until readiness, recovery, rollback, and monitoring are proven.
- Prefer one command and one peer at first; expand only after the local and staging checks are boring.
