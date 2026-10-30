# Reliable Result Callbacks

Reliable callbacks close the gap between saving an incoming command's result and creating its outgoing callback. In the normal callback flow, the result commits first and callback creation follows. A worker can stop between those operations, leaving a completed incoming message with no callback to deliver.

This optional mode saves the incoming result, its attempt and events, the complete frozen outgoing callback, and a durable queue-publication intent in one transaction. The handler runs outside that transaction. If the result commits, its callback delivery state commits with it. Recovery publishes the stored callback through the existing outgoing job and never invokes the original incoming handler.

The mode is disabled by default. Existing installations require no configuration or migration changes while it remains disabled.

## Scope And Limits

The guarantee applies to newly finalized automatic callbacks with all of `callbacks.reliable.enabled`, `callbacks.enabled` and `callbacks.auto_dispatch` enabled. Factory-only messages and historical results are not scanned or backfilled. Disabling callbacks or automatic dispatch retains the existing deliberately undispatched behavior.

This mode does not guarantee exactly-once external business execution. A handler can complete a payment, another database transaction or an HTTP action before Talkto saves its result. If that result never commits, Talkto cannot always reconstruct it safely. An interrupted protected processing attempt therefore requires investigation instead of automatic handler replay.

Queue publication, queue acknowledgement, worker execution, HTTP delivery, callback acceptance and final result application are separate observations. A broker can accept a job before the publisher loses its acknowledgement. Such `publishing` or `unknown` outcomes are not automatically repeated. Callback HTTP failures continue through the existing transport retry policy; an HTTP acknowledgement can also be lost after the source has applied the result. Receiver duplicate handling and terminal-state protection remain necessary.

The callback JSON, HMAC v2, endpoints, command configuration and handler result interface are unchanged. An older peer can exchange the same signed callback format, but test actual host interoperability before rollout.

## Storage And Queue Prerequisites

The package constraints remain PHP `^8.2` and Laravel components `^12.0|^13.0`; use a PHP version supported by the selected Laravel release.

No new callback migration is required. The implementation reuses:

- `talkto_messages` for the incoming state and complete outgoing callback payload;
- `talkto_attempts` and `talkto_events` for processing and delivery evidence;
- the existing `talkto_dispatch_intents` ledger for queue publication.

Reliable callbacks require [durable dispatch](durable-dispatch.md) to be explicitly enabled. If that ledger is not installed, review and publish its existing optional migration:

```bash
php artisan vendor:publish --tag=talkto-dispatch-intent-migrations
```

Inspect the published file and run it through the application's normal migration deployment. Composer installation does not run migrations. Keep model/table configuration and `TALKTO_DB_CONNECTION` consistent with that deployment.

Message, attempt, event, intent and dead-letter models must use the same actual Laravel connection and compatible transactional tables. Existing success/skip finalization consults dead-letter storage even when automatic dead-letter creation is disabled. Required storage and known unsupported configuration are checked before the handler starts. Incoming processing entered inside an already-open caller transaction is rejected so the processing protection can commit before handler execution; the internal result finalizers can still use nested transactions inside the atomic result transaction. Explicit manual callback persistence can participate in its caller's transaction and waits for its commit before publication.

Existing durable-dispatch model limits apply: message keys must be losslessly representable integers matching the effective legacy `id`; the intent model needs its standard unscoped incrementing integer key. UUID/noncanonical bindings require a compatible implementation. Different named connections are not assumed to share a transaction.

| Queue setup | Publication policy |
|---|---|
| Exact native Laravel DatabaseQueue on the same actual connection as Talkto | Queue insertion and intent acknowledgement commit together. A rolled-back enqueue can be recovered after its safe claim expires. All involved storage must be transactional. |
| DatabaseQueue on another connection | The publication boundary commits before queue I/O. Lost acknowledgement can leave `publishing` or `unknown`; no blind repeat. |
| Redis or SQS | Conservative external-publication policy. Live broker validation is required for the chosen host; database queue tests do not certify these brokers. |
| Sync, null or another unsupported driver | Rejected in reliable mode. Disabled reliable/durable modes retain their ordinary behavior. |

No Talkto transaction is kept open while waiting on a separate queue backend. The existing intent uses `purpose=result_callback`; this adds no column or second publication state machine. Ordinary initial-dispatch intents retain their existing purpose and behavior.

## Configuration

The supplied configuration adds only these callback options:

```php
'callbacks' => [
    // Existing callback options remain here.
    'reliable' => [
        'enabled' => env('TALKTO_RELIABLE_CALLBACKS_ENABLED', false),
        'recovery_enabled' => env('TALKTO_RELIABLE_CALLBACKS_RECOVERY_ENABLED', false),
    ],
],
```

For a controlled rollout, normal callbacks and durable creation must also be enabled:

```dotenv
TALKTO_CALLBACKS_ENABLED=true
TALKTO_CALLBACKS_AUTO_DISPATCH=true
TALKTO_DISPATCH_INTENTS_ENABLED=true
TALKTO_RELIABLE_CALLBACKS_ENABLED=true
TALKTO_DISPATCH_INTENTS_RECOVERY_ENABLED=false
TALKTO_RELIABLE_CALLBACKS_RECOVERY_ENABLED=false
```

These are separate switches. Reliable callbacks do not turn durable dispatch or recovery on automatically. Leave `talkto.dispatch_intents.publish_after_commit=true` for normal immediate publication unless an explicit alternative invocation strategy has been reviewed. If it is false, committed intents wait for a controlled recovery invocation. Apply the host's normal config-cache and worker restart procedures after changing configuration.

## Results And Callback Identity

Success, skipped results, retryable failures, final failures, retry exhaustion and unexpected exceptions retain their existing result semantics. The full result contract is captured while available and frozen using the package's payload freezer and hashing. Stateful custom result getters are sampled into one stable snapshot, then result persistence and callback creation use that snapshot.

A handler-reported `retryable` flag is distinct from the final status selected by the retry policy. For example, an exhausted explicitly retryable result retains its reported callback fields; an unexpected exception uses the existing sanitized result derived from the chosen failure state. No stack trace is added to the callback.

The first processing attempt keeps the existing callback ID (`cb-` plus the SHA-1 of the original message ID and status) and `talkto:callback:<original-id>:<status>` idempotency key. Legacy mode and manual results without processing attempts keep that identity too. Existing stored callbacks are never renamed.

From the second reliable processing attempt onward, each actual attempt has its own immutable callback. Its ID starts with `cb-a-`; its idempotency key starts with `talkto:callback:attempt:`. Both use the same SHA-256 digest of the original message ID, incoming table/key/record identity, and persisted processing-attempt table/key/record identity and number. The digest does not include the result, status or timestamp. Two legitimate attempts therefore remain distinct even when their timestamps, statuses and result bodies match. Changing an error message or metadata on a later attempt does not collide with an earlier callback or stop normal retry progression.

Persisting the same later attempt again reuses its callback and intent only if the complete frozen result, hash, routing and ownership still match. Different content or a different status for that same attempt is an integrity conflict. No previous callback, including one already delivered or with an unknown publication outcome, is overwritten or reset. The result, attempt/events, new callback and intent still commit in the same transaction; retry policy, counters, backoff and exhaustion behavior are unchanged.

An explicit `sendResult()` after a later reliable attempt uses the retained current processing-attempt evidence and must match its existing callback and intent. It cannot create a missing later-attempt callback. Missing, ambiguous or unverified evidence is rejected rather than falling back to a status identity or regenerating history. This also covers older results that reused a status-level callback and records removed by pruning. A custom `callback_message_id` cannot replace an established attempt identity. Manual calls without processing attempts retain their documented custom-ID behavior. Do not use a manual resend to invent or revise a historical result.

An old callback without verified reliable delivery evidence is legacy/unverified. It never acquires an executable intent merely because the feature was enabled. Existing reliable callbacks must match their payload hash, routing, ownership and original-message relationship before reuse.

Reliable callback history remains protected after reliable mode is disabled. For a later incoming attempt, the public factory checks retained coordination evidence for the requested status before creating a legacy callback. It reuses the exact existing reliable callback only when its identity, relationship, routing, fingerprint and full payload still match. Missing or conflicting records, or a replacement custom callback ID, are rejected instead of creating another notification. The normal sender then retains its existing protection against publishing that callback again. This lookup does not read the optional dispatch-intent table.

Ordinary legacy results without reliable history retain their existing identity and behavior. The first/no-attempt factory path adds no history query; later calls make one metadata `MAX` query, and a match hydrates only that event and its referenced callback. Automatic reliable processing uses its prepared result path instead. Legacy factory-only calls without reliable history keep their existing connection behavior; actual coordinated reliable evidence still requires the same connection. Preserve coordination events and quiesce workers before changing modes or bindings: a stale model from another process is not a safe basis for concurrent mode changes.

## Receiver Application And Ordering

The signed envelope schema and receiver behavior are unchanged, so the destination can deploy this change without requiring its source peers to upgrade simultaneously. Callback message IDs remain opaque identifiers to the receiver.

The receiver applies statuses, not an ordered journal of every processing attempt. After applying a `failed_retryable` callback, it acknowledges another `failed_retryable` callback as `duplicate`, even if that callback has a different ID, error or metadata. It records the duplicate ID but keeps the first received error and applied result metadata. If the second callback arrives first, its details are retained instead. Every attempt's complete result remains available in its immutable destination callback record; a duplicate acknowledgement does not mean those newer details were applied at the source.

A later success can complete the original outgoing message. A delayed failure cannot downgrade that success, and a retryable failure cannot replace a final failure. Sending callbacks in reverse order does not create a latest-attempt error history at the source. Applications needing that history require a separately designed ordering contract; this mode does not add required protocol fields or infer ordering from timestamps.

## Custom Senders, Factories And Jobs

Reliable automatic processing requires the default sender/factory behavior or subclasses that inherit that behavior unchanged. Overridden sender or callback-factory methods, unsupported outgoing factory behavior and incompatible contextual bindings are rejected before the handler where detectable. The public `ResultCallbackSenderContract` interface is unchanged; disabled mode continues to use custom senders normally. No adapter framework is provided.

Configure custom models through `talkto.models`; outgoing factories must inherit both protected `messageModelClass()` and `eventModelClass()` resolvers unchanged. A factory that redirects persistence through overridden resolvers could commit a callback on another database and is rejected before the handler.

Supported custom outgoing jobs extend `SendTalktoMessage` and inherit its integer-key constructor. They may use supported default queue/connection properties or their normal job execution implementation. An overridden constructor requires a compatible adapter: the future callback row key does not exist during preflight, so the package does not invoke arbitrary constructor code using a substitute key. Advanced queue features retain the existing durable-dispatch restrictions.

Handlers using reliable automatic callbacks should return an `IncomingCommandResultContract`. Calling `sendResult()` manually while that protected handler is still running is rejected: publishing a callback before the final result commits would reopen the very gap this mode closes. Existing manual callback behavior remains available in legacy mode. Explicit manual calls outside protected processing use the supplied result and durable publication when reliable mode is enabled; they do not reconstruct a missing historical result.

## Processing Protection And Persistence Failures

Before invoking the handler, Talkto commits a processing attempt with a unique `locked_by` value beginning with `reliable-callback:`. This is processing protection, not a queue-publication claim. Finalization checks the exact attempt and token, current ownership, identity and model/connection bindings before saving the result.

If result freezing, result persistence, callback persistence or intent creation fails after the handler ran, the result transaction rolls back. Talkto does not convert that infrastructure failure into a fabricated handler result or callback. The protected processing marker remains; a bounded `reliable_callback_investigation_required` event is recorded when storage is available. If even that write is unavailable, the previously committed marker remains the minimum evidence.

Retry, dead-letter reprocess, stale recovery and duplicate incoming jobs refuse this protected attempt, including after reliable mode is disabled. They cannot know whether a stopped handler completed an external business action. Do not clear the marker, reset the message to queued, or rerun the handler merely to obtain a callback. Investigate business records and the attempt evidence through an application-specific procedure.

Configuration, model, ownership or connection changes during processing also fail closed rather than redirecting result writes. A completed result is never invented from incomplete attempt metadata.

## Inspect And Recover Callback Publication

Use these operational distinctions when inspecting message, attempt, event and intent records; they are not new message statuses:

| Observation | Meaning and action |
|---|---|
| Callbacks or auto-dispatch deliberately disabled | Callback not required by automatic processing; do not manufacture an intent. |
| Outgoing callback plus reliable persistence event | Callback persisted; publication and delivery remain separate observations. |
| Ready or expired never-attempted callback intent | Dispatch ready after ownership/state checks and recovery authorization. |
| Publishing or unknown intent | Queue acceptance is uncertain; investigate without blind redispatch. |
| Completed outgoing callback with accepted delivery evidence | Delivered/acknowledged; do not reset it for initial recovery. |
| Failed-retryable callback | Existing outgoing transport retry applies; the incoming handler is not rerun. |
| Failed-final callback | Existing final-failure/DLQ review applies. |
| Reliable protected incoming processing attempt without committed callback | Uncommitted/uncertain handler outcome; investigation, not callback reconstruction. |
| Historical callback without verified reliable intent evidence | Legacy/unverified; no automatic backfill or publication. |

Use the existing read-only diagnostics for historical uncertainty:

```bash
php artisan talkto:recover-undispatched --direction=outgoing --min-age=30 --limit=20 --json
```

That command still cannot execute recovery or create intents. To inspect a known reliable callback, use its public callback message ID:

```bash
php artisan talkto:dispatch-intents --direction=outgoing --message-id="replace-with-callback-message-id" --min-age=30 --limit=1 --json
```

`--min-age` is in minutes, default 30, allowed 0..525600. The default limit is 100, allowed 1..1000. Inspection is read-only. The intent command currently returns JSON with or without `--json`.

After reviewing a safe candidate, explicitly enable both recovery switches in the executing service:

```dotenv
TALKTO_DISPATCH_INTENTS_RECOVERY_ENABLED=true
TALKTO_RELIABLE_CALLBACKS_RECOVERY_ENABLED=true
```

Durable creation and reliable callbacks must remain enabled too. Then use the same filters with explicit execution:

```bash
php artisan talkto:dispatch-intents --direction=outgoing --message-id="replace-with-callback-message-id" --min-age=30 --limit=1 --execute --json
```

Only recorded `ready` or expired never-attempted `claimed` work can pass locked revalidation. `publishing` and `unknown` are not automatically repeated. A published intent does not mean HTTP delivery completed. Already completed callbacks are never reset by recovery; genuine callback transport failures use the existing retry/DLQ operations. Recovery publishes the existing `SendTalktoMessage` job and never resolves the original incoming handler.

A successful command exit does not prove every candidate was dispatched. Review each reason, `dispatched` and `skipped`; callback recovery can be blocked while ordinary initial-dispatch recovery remains enabled. No recovery schedule is installed.

## Roll Out One Service

1. Deploy the code with reliable callbacks disabled and verify normal traffic, callbacks, retries and queues.
2. Review custom sender/factory/job bindings, handler manual-callback calls, models, connections and retention jobs.
3. Install the existing optional durable-dispatch migration if it is not already owned by the application.
4. Verify transactional tables, the actual queue connection/name and supported job behavior in a non-production integration environment.
5. Deploy consistent app and worker code for the selected service. Test signed callback exchange with its existing peers.
6. Enable durable dispatch and reliable callbacks in that service, keeping both executable-recovery switches disabled.
7. Process a new synthetic command. Confirm the incoming result, attempt/events, outgoing callback and callback intent commit together.
8. Confirm queue publication, real job execution, signed callback acceptance and source result application separately. Repeat the idempotent operation and verify no new callback or publication.
9. Inspect narrowly selected intents and test producer interruption/recovery in the host environment before authorizing controlled execution.
10. Monitor that service before opting in another service. Arrange any future automation explicitly through the host's reviewed operations process.

Do not backfill old results or deploy every independent service simultaneously. The signed format is unchanged, but that does not replace host compatibility testing.

## Monitoring, Retention And Rollback

Monitor protected processing attempts, investigation events, callback identity conflicts, ready-intent backlog/age, expired claims, publishing/unknown incidents, actual deliveries and source acknowledgements. Use IDs and bounded reason codes; keep payloads, credentials, signatures and raw exception contents out of operator logs.

Keep the callback command and target configuration stable while callbacks are pending or already queued. A changed command is rejected before intent publication, but an already queued standard job still uses the host's current outgoing configuration. Quiesce and review pending work before changing that configuration.

The complete callback body lives in its existing outgoing message. The intent and new coordination events contain bounded metadata. Existing result attempt/event storage is preserved; no additional full result journal is introduced.

Retain incoming/callback records, attempts, coordination events and intents while any related processing or delivery outcome is unresolved. Keep the current later processing-attempt record for as long as manual callback resends may be needed: its identity is required to verify the resend. Removing reliable coordination events can also remove disabled-mode publication protection. Review existing `talkto:prune` or host cleanup schedules before rollout: generic age-based event/attempt cleanup does not understand every reliable-callback dependency. No new pruning or automatic retention task is installed. An absent old queue row or deleted event is not evidence that delivery never happened.

For rollback, stop new reliable work, quiesce in-flight workers, disable executable callback recovery and then reliable creation using normal config-cache/restart procedures. Keep the patched processing guards while protected attempts require investigation. Do not drop the intent table or erase delivery evidence during routine rollback, and do not invoke a legacy dispatcher for a callback that may already have been published. Avoid concurrent manual calls using stale model instances while changing mode.

## Troubleshooting

| Symptom | Safe checks |
|---|---|
| Missing table or durable dispatch disabled | Keep reliable callbacks disabled until the existing Talkto and intent storage is reviewed and installed. There is no silent legacy fallback for an opted-in eligible result. |
| Custom sender/factory rejected | Inspect the actual contextual dependencies and overridden methods. Keep legacy mode or provide a separately reviewed compatible implementation. Do not replace it silently with the default sender. |
| Custom job or driver rejected | Use a supported job with the inherited constructor and supported routing. Validate the real backend, not only its driver label. |
| Connection or caller-transaction error | All coordinated records need the same actual connection. Invoke the processing job after the caller transaction resolves. Do not treat two databases as one transaction. |
| Protected processing remains | The handler may have run without a committed result. Preserve evidence and investigate; callback recovery cannot reconstruct an unknown result or safely rerun the handler. |
| Callback identity conflict | Inspect the already frozen callback and the new result. Do not overwrite the old payload, change IDs or manufacture a recovery intent for a legacy row. |
| No executable callback intent | Check ownership, callback public ID, age in minutes, status and all four creation/recovery switches. Historical or deliberately disabled callbacks are not candidates. |
| Publishing/unknown outcome | Investigate broker, worker and source state. Lease expiry alone is not permission to redispatch. |
| Published but not completed | Inspect the normal outgoing callback attempt, queue worker, HTTP response and retry/DLQ state. Publication acknowledgement and callback acceptance are distinct. |

See [result callbacks](result-callbacks.md), [durable dispatch](durable-dispatch.md) and [recovery and monitoring](recovery-monitoring-template.md) for the underlying contracts and commands.
