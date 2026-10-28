# Durable Initial Dispatch

Durable dispatch records a message's intended first queue publication in a separate database ledger. It helps with a specific Laravel failure window: the message transaction commits, then the PHP process stops before its after-commit callback pushes a queue job. A committed intent that has not reached the publication boundary can be recovered by a controlled command.

The feature is disabled by default. Enabling it does not schedule recovery, backfill historical messages or guarantee that every queue failure is recoverable. Read this guide and test the host application's models, builders, jobs and queue before enabling it in one service.

## Scope And Requirements

The package requires PHP `^8.2` and Laravel components `^12.0|^13.0`, as declared in `composer.json`. Use a PHP version supported by the selected Laravel release; the package test matrix is PHP 8.2/8.3/8.4 with Laravel 12 and PHP 8.3/8.4 with Laravel 13.

Durable mode applies to newly created outgoing `TalktoFlowBuilder::send()` / `run()` messages and newly validated incoming messages. Message and intent commit in the same actual database transaction. Existing idempotency reuse does not create another intent. `TalktoOutgoingMessageFactory::create()` remains a persist-only API and creates no intent. Recovery publishes the existing configured `SendTalktoMessage` or `ProcessIncomingTalktoMessage` job; the job runs the normal pipeline and Handler.

This initial-publication ledger does not resolve unknown external broker outcomes, guarantee exactly-once arbitrary business execution, coordinate external source-action transactions, repair lost result callbacks or recover historical messages that have no durable intent. Transport retry, stale-processing recovery and callback delivery keep their existing lifecycles. See [recovery and monitoring](recovery-monitoring-template.md) for those operations.

Required storage conditions are:

- Transactional storage for messages, events and intents; MySQL 8.x with InnoDB is the production integration-test backend. SQLite is also exercised by package tests.
- Message and intent models resolving to the same actual Laravel connection object. Event storage must satisfy the existing message/event connection requirements.
- A compatible configured message model with a losslessly representable integer primary key equal to its effective legacy `id` attribute. A renamed numeric key can work when that `id` behavior matches. UUID, nonintegral, mismatched or sensitive key bindings need a separate compatible job/pipeline implementation and are rejected by this feature.
- An intent model using the migrated, unscoped, incrementing integer `id`. Configure the class through `talkto.models.dispatch_intent`, table through `talkto.database.tables.dispatch_intents` and connection through `talkto.database.connection`.
- Stable service identity, model bindings and queue routing. Publication revalidates current ownership, state and stored routing; configuration drift does not authorize silently publishing a different job.

`run()` additionally requires the Laravel default source-action connection to be the same actual connection used by Talkto. An open transaction on another managed connection is rejected. Do not use this as a distributed transaction for a second database, raw external connection, HTTP request, payment or other external side effect.

## Install The Additive Migration

Installing or updating the Composer package does not execute migrations. The intent migration is separate from the existing message-table migrations and does not alter them.

Review the migration and publish it with its own tag:

```bash
php artisan vendor:publish --tag=talkto-dispatch-intent-migrations
```

Inspect the published file, confirm the intended Talkto connection/table configuration, then run it through the application's normal deployment procedure:

```bash
php artisan migrate
```

The default table is `talkto_dispatch_intents`. Set `TALKTO_DB_CONNECTION` and, if needed, `TALKTO_DISPATCH_INTENTS_TABLE` before migration deployment and keep the runtime configuration consistent. No foreign key assumes the type or lifetime of a custom message table. The intent stores bounded identity/routing/state metadata, not message payloads, secrets or serialized jobs.

Alternatively, an application that deliberately loads package migrations directly can set `talkto.dispatch_intents.migrations_enabled` to `true` in its PHP configuration. That makes the migration available to Laravel's migration runner; it does not run it at boot. Choose one migration ownership approach and avoid publishing duplicate copies. The existing `TALKTO_MIGRATIONS_ENABLED` option does not load this separate intent migration.

If durable mode is enabled before its required table is present, creation fails clearly. It does not fall back to legacy queue dispatch.

## Configuration

Keep these defaults in the host's published `config/talkto.php` until rollout prerequisites are complete:

```php
'dispatch_intents' => [
    'enabled' => env('TALKTO_DISPATCH_INTENTS_ENABLED', false),
    'recovery_enabled' => env('TALKTO_DISPATCH_INTENTS_RECOVERY_ENABLED', false),
    'publish_after_commit' => true,
    'lease_seconds' => 60,
    'migrations_enabled' => false,
],
```

| Option | Default | Meaning |
|---|---|---|
| `enabled` | `false` | Create intents for new dispatch-promising flows and validated incoming messages. Disabled paths retain normal dispatch behavior and require no intent table. |
| `recovery_enabled` | `false` | Allow the recovery command's explicit `--execute` mode. Durable creation must also be enabled. |
| `publish_after_commit` | `true` | Attempt publication after the outer message transaction commits. If `false`, new intents wait for an explicitly arranged recovery invocation. |
| `lease_seconds` | `60` | Claim lease duration, bounded by the service to 10..3600 seconds. Expiry alone never makes a `publishing` outcome safe to repeat. |
| `migrations_enabled` | `false` | Expose the separate package migration to the application's migration runner. It does not enable dispatch or execute a migration. |

Only the first two options have environment variables in the supplied config. Change the other three in PHP configuration; there are no supplied environment variables for them. Apply the host's usual config-cache and long-running worker restart procedure after changing configuration.

## Queue Guarantees

Queue acknowledgement, job execution, Handler execution and final business completion are different observations. A `published` intent records acknowledgement of publication; it does not prove that a worker or Handler ran.

| Queue setup | Behavior |
|---|---|
| Exact native Laravel `DatabaseQueue`, using the same actual connection as Talkto | Queue insertion and intent acknowledgement share a transaction through Laravel's Queue API. Failed/rolled-back enqueue leaves no committed job from that transaction and can retain a safely recoverable claim. All involved tables must be transactional. |
| Database queue using another connection | The `publishing` marker commits before queue I/O. An exception or process death can leave an accepted job with an unacknowledged intent; this is not automatically repeated. A second connection to the same server still uses this conservative policy. |
| A compatible `DatabaseQueue` subclass | Does not receive the exact-native atomic optimization. Treat arbitrary overridden behavior as a separate integration requiring host tests. |
| Redis or SQS | The implementation uses a conservative external-publication policy. A broker may accept a job immediately before an exception or process death; the outcome remains `publishing` or `unknown` and requires investigation. Live Redis/SQS validation is not part of the available package execution evidence for this change. Test the chosen broker in the host environment before rollout. |
| Sync, null or another driver | Unsupported in durable mode and rejected. Disabled durable mode retains the application's normal queue behavior, including Sync. |

No application database transaction is held while waiting on a separate database queue or external broker. There is no universal exactly-once delivery or exactly-once external-side-effect guarantee. An empty queue view does not prove a previous publication never happened.

## Builders, Factories And Custom Jobs

With durable mode disabled, existing builder overrides continue through the legacy extension points. With durable mode enabled, a builder that overrides either protected `sendJobClass()` or `dispatchSendJob()` is rejected before message persistence or the `run()` source action. This includes overrides inherited from an intermediate parent or supplied by a trait. An ordinary subclass that inherits both methods unchanged remains compatible.

The preflight does not invoke an overridden method to discover its behavior. Such code can perform routing, arbitrary side effects or transaction work that a later recovery process cannot reproduce from the stored intent. The publisher's stored-job and configuration-drift checks remain in force.

For simple custom job selection, configure the supported job class instead of overriding builder dispatch methods:

```php
'jobs' => [
    'send_message' => App\Jobs\CustomSendTalktoMessage::class,
    'process_incoming' => App\Jobs\CustomProcessIncomingTalktoMessage::class,
],
```

The outgoing class must extend `Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage`; the incoming class must extend `Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage`. Keep the existing single integer message-key constructor contract. Ordinary supported connection, queue and delay properties are recorded and revalidated. Do not assume that configurable routing means every custom job feature is supported.

Custom `queue()` methods, unique jobs, chains, middleware, message grouping/deduplication, Laravel queue routing attributes and queue route mappings require a compatible durable adapter and are rejected. No adapter framework is supplied. An outgoing factory overriding public `create()` also requires a compatible implementation; builder preflight rejects it before persistence or the `run()` source action, and the factory keeps its existing durable guard. Review these customizations before enabling the feature. Do not remove validation or edit intent routing to work around rejection.

The early preflight covers these unsupported builder/factory extensions. Existing configured-job constructor, routing and backend validation still applies when the intent is recorded. A later rejection rolls the message/intent transaction back, but it cannot undo external effects performed by a source action. Validate jobs and backends before rollout and keep external business effects idempotent.

| Scenario | Compatibility | Conditions |
|---|---|---|
| Durable disabled, standard builder | SUPPORTED | Legacy behavior; no intent migration needed. |
| Durable disabled, custom builder | SUPPORTED | Existing extension and queue contracts apply. New preflight does not run. |
| Durable enabled, standard builder | SUPPORTED WITH CONDITIONS | Required schema, storage and queue/job prerequisites above. |
| Durable enabled, subclass without dispatch overrides | SUPPORTED WITH CONDITIONS | Both protected dispatch methods must be inherited unchanged, including through multilevel inheritance. |
| Durable enabled, configured custom job | SUPPORTED WITH CONDITIONS | Supported existing job subclass, constructor and routing; stable configuration through recovery. |
| Durable enabled, `sendJobClass()` override | NOT SUPPORTED | Fails before persistence/source action. Move simple selection to `talkto.jobs.send_message` and use an unchanged dispatch implementation. |
| Durable enabled, `dispatchSendJob()` override or both overrides | NOT SUPPORTED | Fails before persistence/source action; arbitrary custom dispatch needs a compatible adapter. |
| Durable enabled, custom outgoing factory | SUPPORTED WITH CONDITIONS | Inherit public `create()` unchanged. Overrides are rejected by preflight before persistence/source action and need a compatible implementation. |
| Durable enabled, custom message model | SUPPORTED WITH CONDITIONS | Stable model/table binding and supported integer/effective-id behavior; UUID/noncanonical keys are rejected. |
| Durable enabled, custom database connection | SUPPORTED WITH CONDITIONS | Message/event/intent transaction requirements; `run()` also needs the same source/default connection. |
| Durable enabled, native DatabaseQueue | SUPPORTED WITH CONDITIONS | Actual same-connection atomicity or conservative separate-connection policy, as described above. |
| Durable enabled, Redis | NOT TESTED | Conservative publication implementation; live broker integration must be validated before use. |
| Durable enabled, SQS | NOT TESTED | Conservative publication implementation; live broker integration must be validated before use. |
| Durable enabled, SyncQueue | NOT SUPPORTED | Explicit rejection; use the legacy mode if synchronous dispatch is required. |

## Intent States And Recovery

| State | Meaning | Recovery action |
|---|---|---|
| `ready` | Intent committed; publication has not crossed its boundary. | Eligible for locked revalidation. |
| `claimed` | A publisher owns a fenced lease; queue publication has not begun. | Wait for an active lease. An expired lease can be reclaimed after revalidation. |
| `publishing` | Publication may be in progress or accepted without local acknowledgement. | Investigate; never recycle merely because a lease expired. |
| `published` | Queue publication acknowledged. | No initial redispatch; monitor normal job/message processing. |
| `unknown` | Publication may have reached the backend. | Investigate; no automatic or command override redispatch. |
| `cancelled` | Binding, ownership or message state became ineligible. | Investigate the message's existing lifecycle; do not create fresh initial work. |

The usual path is `ready → claimed → publishing → published`. A failed native same-connection enqueue rolls its publication transaction back, retaining the preceding claim; after expiry it can be revalidated safely. For separate/external publication, failures or process death after the boundary remain ambiguous. Claim tokens fence a stale publisher from publishing or acknowledging a newer claim.

The `publication_attempts` field counts crossings of the local publication boundary. It does not count accepted jobs, worker executions or business effects. A rolled-back native atomic transaction also rolls this increment back.

### Read-Only Message Diagnostics

The existing command remains read-only:

```bash
php artisan talkto:recover-undispatched --direction=outgoing --min-age=30 --limit=20 --json
```

It observes possible message dispatch gaps without creating intents or jobs. Historical messages without an intent are legacy/unverified; age and a queued-looking status are not proof of safe recovery. Its `--execute` option is unsupported and exits with failure without querying or dispatching.

### Inspect Recorded Intents

After the migration and durable mode are enabled, inspect a bounded set:

```bash
php artisan talkto:dispatch-intents --direction=outgoing --min-age=30 --limit=20 --json
```

The command is read-only by default. It reports only existing intents owned by the current service and returns sanitized JSON, including without `--json`. An inspection's `recovery_eligible` result is provisional; publication rechecks current message state and routing under locks.

| Option | Meaning |
|---|---|
| `--execute` | Attempt publication of safely claimable intents. Requires both `enabled` and `recovery_enabled`. |
| `--direction=incoming` or `--direction=outgoing` | Optional direction filter. |
| `--message-id=...` | Optional exact public message ID, not the database row key. |
| `--min-age=30` | Minimum intent age in **minutes**, default 30; allowed 0..525600. |
| `--limit=100` | Maximum selected intents, default 100; allowed 1..1000. |
| `--json` | Accepted output option; the intent command currently returns JSON in both modes. |

### Execute Controlled Recovery

First inspect one known public message ID, replacing the example value:

```bash
php artisan talkto:dispatch-intents --message-id="replace-with-the-public-message-id" --min-age=30 --limit=1 --json
```

After verifying the candidate and separately enabling `TALKTO_DISPATCH_INTENTS_RECOVERY_ENABLED`, use the same filters with explicit execution:

```bash
php artisan talkto:dispatch-intents --message-id="replace-with-the-public-message-id" --min-age=30 --limit=1 --execute --json
```

Execution selects `ready` and expired `claimed` intents, then revalidates each one. It reports publication results and skip reasons. It does not select `unknown`/`publishing` records, create missing intents, change business status or run the Handler directly. A successful command exit does not mean every candidate was published; review `dispatched`, `skipped` and each candidate's result. Repeated recovery must not produce another initial job for an acknowledged intent.

## Roll Out One Service At A Time

1. Deploy the package code with durable dispatch disabled. Update the service's app, scheduler and worker code consistently using its normal deployment procedure.
2. Verify current Talkto traffic, queues, retries and callbacks remain healthy.
3. Review and publish the additive migration with `talkto-dispatch-intent-migrations`.
4. Run the reviewed migration through the application's normal deployment process.
5. Verify the intent table, configured models/table names, transactional storage and actual queue connection.
6. Audit the configured builder hierarchy, traits and outgoing factory for unsupported overrides.
7. Validate the chosen queue driver, configured job subclasses and routing in a non-production integration environment, including the real broker if using Redis/SQS.
8. Enable `TALKTO_DISPATCH_INTENTS_ENABLED=true` in one controlled service, applying its normal config-cache and process restart procedure.
9. Keep `TALKTO_DISPATCH_INTENTS_RECOVERY_ENABLED=false` initially. Leave `publish_after_commit=true` for normal immediate publication unless an explicit alternative invocation strategy has been reviewed.
10. Create a new synthetic message through an opted-in outgoing flow or an authenticated, validated incoming request using the application's configured command and peer.
11. Confirm the new message and intent committed together. A factory-only control should have no intent.
12. Confirm the expected existing job class, connection and queue are used and the real job processes the committed message correctly.
13. Repeat the intended idempotent operation and confirm there is no extra message/intent/publication.
14. Run read-only intent inspection with narrow direction/public-ID/age/limit filters.
15. Review intent states, command results, ordinary message events and redacted operational logs; separately observe job execution and business completion.
16. After validating safe recovery in the host environment, enable recovery for explicitly controlled command invocations. The package installs no recurring schedule.
17. Monitor that service before considering another one. Test exchanges with older peers as part of host rollout even though the signed protocol is unchanged.

Do not use historical queued-message age as a recovery list. Do not switch every independent service at once or apply an unreviewed ambiguous-publication override.

## Monitoring And Retention

Watch the count and oldest age of `ready` intents, active/expired `claimed` leases, and any `publishing` or `unknown` outcomes. Review bounded `last_error_code` values and command skip reasons for deferred publication, routing drift or ineligible messages. Compare acknowledged publication with actual worker processing and final message state; they are separate counts.

Record controlled recovery results, including candidate ID, `dispatched`/`skipped` counts and reasons. Correlate duplicate-prevention observations with existing idempotency responses, job/message lifecycle events and fenced-publication results; the ledger does not introduce a universal duplicate-prevention event stream. See [message tracing](recovery-monitoring-template.md#trace-one-flow) for the normal lifecycle.

The ledger grows by one row per new intended initial dispatch and stores no body copy. No automatic pruning is installed. Define retention for old acknowledged/cancelled evidence with the host's audit and queue lifetime requirements. Preserve active and ambiguous evidence; deleting a ledger row is not authorization to create another dispatch.

## Rollback

Disable executable recovery, then durable intent creation, applying the host's config-cache and worker restart procedure. Review any active, ready or ambiguous records before reverting code. Keep the additive table and its evidence during routine rollback. Older code can coexist with that extra table, but host compatibility tests and careful handling of in-flight work are still necessary. Do not manually invoke a legacy dispatcher for a message that may already have publication evidence. Table removal is a separate, explicit database decision after all evidence is resolved.

## Troubleshooting

| Symptom | Check and safe action |
|---|---|
| Missing intent table | Confirm the service's actual connection/table configuration, publish the separate migration and run the reviewed migration. Keep the feature disabled until ready. |
| Incompatible message or intent model | Check the configured class/table/key and connection. Existing jobs require supported integer/effective-id message behavior; the intent needs standard unscoped integer `id`. Do not coerce UUIDs or change historical IDs. |
| Incompatible custom FlowBuilder | Inspect direct, inherited and trait overrides of both dispatch methods. Use unchanged methods with `talkto.jobs.send_message` for simple job selection, or keep durable mode disabled until a compatible implementation is reviewed. Preflight rejection creates no message, intent, job or failed-source audit and does not invoke `run()`'s source action. |
| Unsupported queue driver | Durable mode accepts the database/Redis/SQS policy described above and rejects Sync/other drivers. Verify the actual queue object and host integration, not only its configured driver label. |
| Database connection mismatch | Compare actual message, event, intent, source/default and queue connections. `run()` cannot coordinate independent source databases. Do not assume two named connections share a transaction. |
| `publishing` or `unknown` outcome | Preserve evidence and investigate broker/worker/message state. A timeout, old lease or absent visible queue row does not prove nonpublication. There is no automatic ambiguous override. |
| Recovery disabled | Read-only inspection is still available when durable mode/schema are ready. Explicit execution needs both creation and recovery flags enabled in the executing process's cached config. |
| No eligible intents | Check current service ownership, public ID, direction, age in minutes and limit. Only recorded ready/expired-claimed work is executable. Factory-only, historical, terminal, processing, retried or ambiguous work is not fresh initial work. |
| Custom job/routing incompatibility | Review base class, integer-key constructor, advanced queue features and runtime config changes. Keep stable routing while intents remain pending. Do not edit stored routing or bypass validation to force publication. |

Command errors are intentionally sanitized and may summarize several configuration/schema/filter failures. Inspect the host configuration and redacted application exception logs separately rather than printing payloads, shared secrets, raw signatures or credentials. Restrict executable recovery and ledger access to trusted operators.
