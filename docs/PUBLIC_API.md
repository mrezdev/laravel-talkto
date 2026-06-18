# Public API

This page lists the package surfaces intended for host applications to depend on. Internal pipelines and model implementation details may evolve behind these surfaces.

## Contracts

- `Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler`
- `Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract`
- `Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract`
- Callback contracts under `Mrezdev\LaravelTalkto\Contracts` for host-owned result callback implementations.

## App-Level Services

- `TalktoIncomingHandlerRegistry` for programmatic incoming handler registration.
- `TalktoOutgoingTargetRegistry` for programmatic outgoing target registration.
- `TalktoMetricsCollector` for read-only metrics snapshots and counts.
- `TalktoHealthChecker` for read-only health summaries.
- `TalktoOutgoingMessageFactory` and `TalktoFlowFactory` for creating outgoing messages.

## Commands

- `talkto:retry-failed` with `--direction`, `--limit`, and `--dry-run`.
- `talkto:dlq-reprocess` with `--id`, `--message-id`, `--direction`, `--limit`, `--dry-run`, and `--force`.
- `talkto:report` with `--hours`, `--from`, `--to`, `--json`, `--direction`, and `--limit`.

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
- Outgoing targets resolve from config, aliases, and `TalktoOutgoingTargetRegistryContract`.
- Signatures use backward-compatible v1 by default; v2 is opt-in for sending and accepted by default for receiving.
- Retry/backoff state is stored on `talkto_messages`.
- Dead letters use `talkto_dead_letters` when enabled and migrated.
- Observability reports are read-only and do not dispatch jobs or mutate rows.

Host applications should avoid depending on private helper methods or internal pipeline implementation details.
