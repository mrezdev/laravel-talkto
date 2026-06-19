# New Service Onboarding

Use this checklist when adding Laravel Talkto to a new Laravel service. This is a template only; it does not create a third service and does not enable production traffic.

## New Service Checklist

1. Choose the service identity, for example `<source-service>` or `<destination-service>`.
2. Decide whether the service sends commands, receives commands, receives callbacks, or all three.
3. Install the package in the new host application.
4. Publish and review config before enabling routes or migrations.
5. Define peer services and shared secret names.
6. Decide route ownership.
7. Decide migration ownership.
8. Decide the queue strategy.
9. Register allowed commands.
10. Implement command handlers in the host.
11. Implement sender code in the host.
12. Implement callback sending or callback receiving in the host.
13. Define retry and recovery rules.
14. Define monitoring and readiness checks.
15. Prove a local HTTP end-to-end flow.
16. Add tests for sender, receiver, callback, retry, rollback, and redaction behavior.
17. Complete production rollout and rollback checklists.

## Install Package

Install the package in the host application:

```bash
composer require mrezdev/laravel-talkto
php artisan vendor:publish --tag=laravel-talkto-config
php artisan vendor:publish --tag=laravel-talkto-migrations
```

Review the published config before changing any traffic flag. Existing services may keep package routes and migrations disabled and use host-owned wrappers.

## Service Identity

Set one stable identity for the host. The identity must match peer configuration in every service that talks to it.

Example:

```env
TALKTO_SERVICE=<source-service>
```

Use lowercase service names with hyphens. Do not change the identity after messages exist unless you also plan migration and recovery behavior.

## Peer Services

Configure outgoing peers by service identity, URL, endpoint, and secret variable. Configure incoming peers by source identity, secret variable, allowed commands, and handler class.

Use placeholders until the owning team supplies real non-committed values:

- `<source-service>`
- `<destination-service>`
- `<local-test-secret>`
- `http://127.0.0.1:<port>`

## Secrets

Use different shared secrets per service pair and direction when practical. Keep secrets in environment management, never in committed docs, config, tests, or stubs. Rotate secrets with a planned overlap window if both old and new values must work during deploy.

## Route Ownership Decision

Choose one receive route owner:

- Package route: enable only when the host accepts the package receive endpoint.
- Host route: keep package routes disabled and call package services from a host controller.

Avoid duplicate receive endpoints for the same command flow.

## Migration Ownership Decision

Choose one table ownership pattern:

- Package migrations: enable when the host wants package-owned table definitions.
- Host migrations: keep package migrations disabled if the host already owns compatible tables or needs custom table names.

Do not run destructive database commands as part of onboarding.

## Queue Strategy

For local tests, use `QUEUE_CONNECTION=sync`. For staging and production, define queue names, worker ownership, retry limits, failed job handling, and alerting before enabling traffic.

## Command Registry

Every incoming command must be explicitly allowed under the source service. Start with one command, for example `example:sync-record`, and require idempotency for commands that can create or update state.

The registry should answer:

- Which source service may send the command?
- Which handler class owns it?
- Is idempotency required?
- Is the command enabled for this environment?
- What payload version is accepted?

## Generator-Based Workflow

For services that use the Talkto scaffolding generators, start with dry-run output and keep config edits manual:

```bash
php artisan talkto:make-outgoing inventory verify-invoice --dry-run
php artisan talkto:make-incoming inventory website.invoice-verified --dry-run
```

Then:

1. Configure the peer service in `config/talkto.php`.
2. Create outgoing scaffolding for commands this service sends.
3. Create incoming scaffolding for commands this service receives.
4. Copy and review the incoming config snippet before editing `config/talkto.php`.
5. Write domain logic in the payload builder, transactional source action, validator, and handler action.

See [scaffolding.md](scaffolding.md) and [transactional-outgoing.md](transactional-outgoing.md).

## Handler Implementation

Handlers are host code. They validate payloads, perform model lookups, enforce permissions, make safe writes, and return a package result object.

Keep handlers small:

- Validate first.
- Check idempotency and business uniqueness.
- Make one controlled state transition.
- Return a redacted success or failure result.

## Sender Implementation

Sender code is host code. It chooses the command name, payload, business key, idempotency key, target service, and source action behavior.

Use deterministic payloads. Use stable idempotency keys for retried logical commands. Do not put secrets or internal-only fields in payloads.

## Callback Strategy

Decide whether the destination sends a result callback and whether the source requires it to complete the source-side lifecycle. Callback handlers must verify signatures, match the original message, handle duplicates safely, redact logs, and be retry-safe.

## Retry And Recovery Strategy

Classify failures before retrying:

- Retryable: timeout, temporary connection failure, temporary queue failure.
- Review first: validation failure, command not allowed, payload hash mismatch.
- Do not retry automatically: invalid signature, unknown source, unsafe duplicate.

Recovery actions should operate on one message at a time and require an explicit confirmation flag.

## Monitoring Strategy

At minimum, expose counts by status, retryability, command, peer service, and age. Operators need message detail, attempt history, callback state, last error summary, and redacted payload metadata.

## Local HTTP E2E Strategy

Before staging, run a local two-service HTTP flow using testing databases, local URLs, local-only secrets, and synchronous queues. Cover send, receive, replay, tamper detection, callback delivery, and retry behavior.

See `local-http-e2e-template.md`.

## Production Rollout Gates

Do not enable production traffic until these gates pass:

- Config is published and reviewed.
- Routes and migrations ownership is decided.
- Secrets are present in environment management.
- Queues and failed job handling are ready.
- Monitoring and readiness checks are deployed.
- Rollback is written and rehearsed.
- A single controlled command smoke test is approved.

## Rollback Strategy

Rollback should be possible without losing message visibility. Prefer disabling send and receive flags first, then pausing workers if required. Keep data for investigation. Do not delete message tables or run destructive database commands during rollback.

## Security Checklist

- Use HTTPS outside local-only tests.
- Use per-peer shared secrets.
- Verify signatures and timestamp tolerance.
- Require idempotency for state-changing commands.
- Redact payloads, headers, and errors in logs.
- Restrict monitoring and recovery actions to trusted operators.
- Keep production URLs and secrets out of committed files.

## What Not To Copy From Existing Host Apps

Use existing hosts as references for transport shape only. Do not copy their domain handlers, model mapping, command payloads, database writes, feature flags, permissions, dashboards, or business-specific tests. A new service must design its own command contract and host-owned business behavior.

## Deferred Work

This template does not define repository metadata, CI release workflows, public licensing, Packagist publishing, or production rollout for existing services.
