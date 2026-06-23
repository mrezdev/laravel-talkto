# Local HTTP E2E Template

Use this template to prove a local two-service Talkto flow before staging or production.

## Two-Service Local Setup Pattern

Run two Laravel services locally:

- Source service: `127.0.0.1:PORT_A`
- Destination service: `127.0.0.1:PORT_B`

Use separate testing databases and local-only config. Do not point local E2E tests at production URLs.

## Environment Placeholders

Use local-only values:

```env
APP_ENV=testing
DB_DATABASE=<service_testing_db>
QUEUE_CONNECTION=sync
TALKTO_SECRET=<local-test-secret>
```

For URL fields, use:

```env
TALKTO_DESTINATION_URL=http://127.0.0.1:PORT_B
TALKTO_SOURCE_URL=http://127.0.0.1:PORT_A
```

## Health Endpoint Pattern

Each service may expose a local-only testing health endpoint:

- `GET /api/testing/talkto/health`
- Return service identity, environment, queue mode, and whether Talkto config is loaded.
- Do not return secrets.

## Send Endpoint Pattern

The source service may expose a local-only endpoint that sends one neutral command:

- `POST /api/testing/talkto/send-example`
- Builds `example:sync-record`.
- Uses a deterministic idempotency key.
- Sends to `<destination-service>`.
- Returns the created message id and status.

## Callback Endpoint Pattern

The source service should expose the callback endpoint it expects the destination to call:

- `POST /api/talkto/callback`
- Verify signature and timestamp.
- Match the callback to the original source message.
- Handle duplicate callbacks safely.
- Redact payload and error details in logs.

## Replay And Idempotency Test

Send the same logical command twice with the same idempotency key. The destination should not run duplicate unsafe work. The response should prove whether the message was accepted as a replay, ignored, or rejected according to host policy.

## Tamper Test

Change one payload field after the payload hash is calculated. The destination must reject the envelope because the hash no longer matches. Do not run real business side effects during this test unless the test explicitly exists to prove a safe side effect.

## Callback Test

After the destination handler returns a result, confirm the destination auto-queues a durable outgoing callback message. Local tests that run jobs manually should simulate three job steps:

1. source command `SendTalktoMessage`
2. destination `ProcessIncomingTalktoMessage`
3. destination callback `SendTalktoMessage`

Do not assume `sendResult()` sends callback HTTP immediately. Callback HTTP delivery happens when the queued callback message is sent through `SendTalktoMessage`. The source should verify the callback, match it to the original message, update source-side status, and treat duplicate callbacks as safe.

## No Production URL Rule

Local E2E tests must use `127.0.0.1`, `localhost`, or isolated test network addresses only. Never use production URLs, production queues, production databases, or production secrets.

## No Real Business Side Effect Rule

Use a neutral command such as `example:sync-record`. Avoid real domain writes unless the purpose of the test is explicitly to verify a safe host-owned write in a testing database.

## Minimum Assertions

- Source creates an outgoing message.
- Source signs the envelope.
- Destination verifies signature and payload hash.
- Destination stores incoming message state.
- Destination handler returns success or failure.
- Destination creates an outgoing durable callback message when configured.
- Destination sends the callback when the queued callback `SendTalktoMessage` job runs.
- Source verifies and records the callback.
- Replay, tamper, retry, redaction, and rollback paths are covered.
