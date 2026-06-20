# Architecture

Laravel Talkto is an outbox/inbox transport package. Host apps own their business transactions and handlers; the package owns signed envelopes, message storage, retries, callbacks, and operational visibility.

## Outgoing Command Flow

```mermaid
flowchart LR
    A["Source host business code"] --> B["Optional local DB transaction"]
    B --> C["Host business record"]
    B --> D["Talkto outgoing message"]
    D --> E["Queue worker after commit"]
    E --> F["Signed v2 HTTP request"]
    F --> G["Target receive route"]
    G --> H["Verify signature, timestamp, hash, nonce"]
    H --> I["Store incoming message"]
    I --> J["Dispatch or run handler"]
```

The source transaction may create a host business record and the Talkto outbox row together. Remote delivery happens after commit through the worker/retry flow; do not treat local message creation as synchronous remote success.

## Incoming Command Flow

```mermaid
flowchart LR
    A["Signed command envelope"] --> B["Receive controller or host wrapper"]
    B --> C["Verify source and target"]
    C --> D["Verify timestamp, payload hash, signature"]
    D --> E["Consume v2 nonce"]
    E --> F["Check message_id idempotency"]
    F --> G["Check command allowlist"]
    G --> H["Store inbox message"]
    H --> I["Dispatch ProcessIncoming job"]
    I --> J["Host handler returns result"]
```

Verification is fail-closed. Unknown sources, target mismatches, invalid signatures, missing v2 nonces, reused nonces, and disallowed commands are rejected before handler execution.

## Result Callback Flow

```mermaid
flowchart LR
    A["Target handler result"] --> B["ResultCallbackSenderContract"]
    B --> C["Build callback envelope"]
    C --> D["Sign callback with v2 nonce"]
    D --> E["Source callback route"]
    E --> F["Verify callback signature and nonce"]
    F --> G["Find original outgoing message"]
    G --> H["Validate callback relationship"]
    H --> I["Update destination result/status"]
    I --> J["Record callback events"]
```

Callbacks are ordinary signed Talkto messages with the callback command, which defaults to `talkto.result`. The source app must configure the destination as an incoming source and allow the callback command.

## Retry And DLQ Flow

```mermaid
flowchart LR
    A["Send or handler failure"] --> B{"Retryable?"}
    B -->|"Yes"| C["Store retry state and next_retry_at"]
    C --> D["talkto:retry-failed or worker"]
    D --> E["Dispatch retry job"]
    E --> A
    B -->|"No or exhausted"| F["Final failure"]
    F --> G["Store dead letter when enabled"]
    G --> H["Operator reviews DLQ"]
    H --> I["talkto:dlq-reprocess --dry-run"]
    I --> J["Optional reprocess"]
```

Retry policy can be configured globally and overridden by direction, peer, and command. DLQ reprocessing is an operator action and should be reviewed before dispatching.
