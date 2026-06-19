# Public API

This page lists the package surfaces intended for host applications to depend on. Internal pipelines and model implementation details may evolve behind these surfaces.

## Contracts

- `Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler`
- `Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract`
- `Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract`
- `Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract`
- Callback contracts under `Mrezdev\LaravelTalkto\Contracts` for the generic result callback runtime and host overrides.

`IncomingCommandResultContract` exposes non-conflicting instance accessors: `isSucceeded()`, `isRetryable()`, `isSkipped()`, `errorClass()`, `errorMessage()`, `result()`, and `meta()`.

## App-Level Services

- `TalktoIncomingHandlerRegistry` for programmatic incoming handler registration.
- `TalktoOutgoingTargetRegistry` for programmatic outgoing target registration.
- `TalktoMetricsCollector` for read-only metrics snapshots and counts.
- `TalktoHealthChecker` for read-only health summaries.
- `TalktoTraceReporter` for read-only message and correlation trace snapshots.
- `TalktoSecurityRedactor` for centralized safe redaction of secret-like values, configured Talkto shared secrets, and sensitive headers.
- `TalktoSecurityAuditor` for read-only security posture snapshots.
- `TalktoOutgoingMessageFactory` and `TalktoFlowFactory` for creating outgoing messages.
- `TalktoIncomingCommandResult` for incoming handler outcomes through the `succeeded`, `failedRetryable`, `failedFinal`, and `skipped` factories.
- `TalktoResultCallbackSender`, `TalktoResultCallbackReceiver`, and `TalktoResultCallbackEnvelopeBuilder` for signed result callback runtime behavior.

## Data Objects

- `TalktoEnvelopeData` provides an immutable snapshot of the envelope array shape and can be created from an envelope array or compatible message model.
- `TalktoIncomingCommandResultData` provides an immutable snapshot of an `IncomingCommandResultContract`.
- `TalktoResultCallbackData` provides an immutable snapshot of the signed result callback envelope shape.
- `TalktoTraceSnapshot` provides a stable array shape for read-only trace reports.
- `TalktoRetryDecision` provides a stable array shape for retry eligibility and scheduling decisions.
- `TalktoSecurityFinding` and `TalktoSecurityAuditSnapshot` provide stable array shapes for read-only security audit output.

These data objects are additive. Existing array-based APIs remain supported and continue to return the same keys.

## Commands

- `talkto:retry-failed` with `--direction`, `--limit`, and `--dry-run`.
- `talkto:dlq-reprocess` with `--id`, `--message-id`, `--direction`, `--limit`, `--dry-run`, and `--force`.
- `talkto:report` with `--hours`, `--from`, `--to`, `--json`, `--direction`, and `--limit`.
- `talkto:trace` with `message_id`, `--correlation`, `--json`, `--limit`, and `--payload`.
- `talkto:audit-security` with `--json`.
- `talkto:security-audit` with `--json` and `--fail-on`.
- `TalktoDeadLetterQueue::markReprocessedForMessage()`, `markFailedReprocess()`, and `markIgnored()` for DLQ lifecycle integration.

## Config Areas

- `talkto.incoming`
- `talkto.outgoing`
- `talkto.aliases`
- `talkto.retry`
- `talkto.dead_letter`
- `talkto.security`
- `talkto.observability`
- `talkto.routes`
- `talkto.migrations`
- `talkto.models`
- `talkto.jobs`

## Main Behavior

- Incoming receive endpoint is opt-in through `talkto.routes.enabled`.
- Callback receive endpoint is opt-in through `talkto.routes.enabled` and `talkto.callbacks.enabled`, and uses `talkto.routes.callback_uri`.
- Outgoing targets resolve from config, aliases, and `TalktoOutgoingTargetRegistryContract`.
- Signatures use backward-compatible v1 by default; v2 is opt-in for sending and accepted by default for receiving.
- Retry/backoff state is stored on `talkto_messages`.
- Dead letters use `talkto_dead_letters` when enabled and migrated.
- Observability reports and traces are read-only and do not dispatch jobs or mutate rows.
- Security audits are read-only and report configuration findings without mutating host state.

Host applications should avoid depending on private helper methods or internal pipeline implementation details.
