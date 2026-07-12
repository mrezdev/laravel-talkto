# Result Callbacks

Result callbacks let the destination report command outcomes back to the source. Laravel Talkto provides a generic signed callback sender and receiver while keeping host business side effects in the host application.

Result callbacks are durable. The default `TalktoResultCallbackSender` no longer posts callback HTTP directly. `ResultCallbackSenderContract::sendResult()` creates or reuses an outgoing durable callback message in `talkto_messages`, records a queued event, and dispatches `SendTalktoMessage`. Actual HTTP delivery happens later through the normal outgoing send pipeline.

## Durable Sender

`ResultCallbackSenderContract` accepts a destination-side incoming message, an `IncomingCommandResultContract`, and optional options such as `callback_message_id`.

The default sender:

- creates or reuses an outgoing callback message
- stores it in `talkto_messages`
- uses the callback command, which defaults to `talkto.result`
- sets `parent_message_id` to the original incoming message id
- dispatches `SendTalktoMessage` for queued delivery
- returns `sent=false` and `queued=true` when it queues the durable callback

Callback messages use the configured callback endpoint on the target, usually `/api/talkto/callback`. Because they are normal outgoing Talkto messages, they are eligible for existing attempts, retry, DLQ, report, panel, trace, and reprocess behavior.

Callback result and meta payloads pass through the same outgoing one-time freeze boundary as ordinary commands. The durable callback row stores the frozen primitive tree, and direct `TalktoResultCallbackData` instances capture one immutable frozen payload snapshot that `toPayload()` and `toEnvelope()` reuse for repeatable callback hashes. The optional constructor snapshot array remains accepted for compatibility, but it is validated and frozen on entry; do not pass live resources, closures, generators, native `DateTimeInterface` values, circular structures, or mutable runtime objects that cannot be converted into JSON-safe primitives.

Destination apps must configure the original source service under `talkto.outgoing` with `base_url`, `receive_endpoint`, `callback_endpoint`, and `secret`, or with explicit full `receive_url` and `callback_url` values. The `url` and `endpoint` keys still work as aliases for `base_url` and `receive_endpoint`.

## Automatic Dispatch

Incoming processing auto-queues durable callbacks by default after a handler result has been applied to the incoming message.

```dotenv
TALKTO_CALLBACKS_ENABLED=true
TALKTO_CALLBACKS_AUTO_DISPATCH=true
```

Handlers no longer need to call `sendResult()` manually in normal cases. Return a stable result from the handler and let the package queue the callback after the incoming message becomes `succeeded`, `skipped`, `failed_retryable`, or `failed_final`.

```php
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

return TalktoIncomingCommandResult::succeeded([
    'processed' => true,
]);
```

Set `talkto.callbacks.auto_dispatch=false` only when the host intentionally wants to queue callbacks manually. If `talkto.callbacks.enabled=false`, callback creation and sending are disabled.

Existing manual `sendResult()` calls remain supported and duplicate-safe. When a handler manually calls `sendResult()` and the pipeline also auto-dispatches the same result, the sender reuses the deterministic callback message and avoids duplicate send jobs when it can see that callback delivery was already queued.

The default sender locks the durable callback message row before deciding whether to queue delivery. It checks existing `result_callback_queued` and `result_callback_queue_failed` events for the same callback, tags the callback row with a timestamped dispatch claim, records a queued event while holding the lock, and dispatches `SendTalktoMessage` after the transaction commits. Duplicate calls return `queued=false` with a duplicate summary when the callback is already queued or handled. If dispatch itself fails, the sender reloads the callback row, verifies the exact failed claim and expected pending callback state, records `result_callback_queue_failed`, and clears that claim in one Talkto transaction. If that failure event cannot be written, the transaction rolls back and the claim remains for stale recovery instead of being released without an audit event. If a process crashes after the claim commits but before dispatch, `talkto:recover-stale` can recover the old callback claim and queue the existing callback message once.

If a handler throws an unexpected exception before returning a result, the pipeline first applies the normal retry/failure state and then queues a durable failed callback derived from the stored message state. Retryable failures produce `failed_retryable`; exhausted final failures produce `failed_final`. The callback includes the exception class and a redacted, excerpted message, but no stack trace. For expected business errors, handlers should still return explicit `failedRetryable()` or `failedFinal()` results.

## Normal Lifecycle

1. The source service sends an outgoing command message.
2. The destination receive endpoint accepts it and the source-side outgoing message becomes `destination_received`.
3. The destination processes the incoming command.
4. The destination incoming message becomes `succeeded`, `skipped`, `failed_retryable`, or `failed_final`.
5. The destination auto-creates a durable outgoing callback message with command `talkto.result`.
6. The durable callback message points back to the original incoming message with `parent_message_id`.
7. `SendTalktoMessage` delivers the callback through the outgoing send pipeline.
8. The source callback receiver verifies the signed callback and updates the original outgoing message to `completed`, `failed_retryable`, or `failed_final` based on the callback result.

## Receiver

`ResultCallbackReceiverContract` accepts a signed callback envelope and headers. The default `TalktoResultCallbackReceiver` verifies the envelope, matches the original outgoing message, applies the callback status, and records callback events.

Source apps must configure the destination service under `talkto.incoming` and allow the callback command:

```php
'incoming' => [
    'destination-service' => [
        'secret' => env('TALKTO_FROM_DESTINATION_SECRET'),
        'allowed_commands' => [
            'talkto.result' => [
                'driver' => 'none',
            ],
        ],
        'allow_all_commands' => false,
    ],
],
```

Package callback routes depend on `talkto.routes.enabled` and `talkto.callbacks.enabled`; host-owned routes can call the receiver contract directly when package routes stay disabled.

## Result Contract

`IncomingCommandResultContract` describes the stable fields a callback needs:

- `isSucceeded()` for success or failure
- `isRetryable()` for temporary failures
- `isSkipped()` for intentionally skipped commands
- `errorClass()` and `errorMessage()` for redacted failure details
- `result()` for the result payload
- `meta()` for metadata

`TalktoIncomingCommandResult` implements this contract and keeps the existing static factories:

- `succeeded($result = [], $meta = [])`
- `failedRetryable($errorMessage, $errorClass = null, $meta = [])`
- `failedFinal($errorMessage, $errorClass = null, $meta = [])`
- `skipped($reason = null, $meta = [])`

## Boundary

Callback message creation, queue dispatch, envelope signing, verification, lifecycle transitions, and basic event recording are generic package behavior. Deciding what a result means for a host business process remains the host application's job.

Hosts can override `ResultCallbackSenderContract` or `ResultCallbackReceiverContract` when they need custom callback behavior. Most hosts should use the default durable sender and receiver.
