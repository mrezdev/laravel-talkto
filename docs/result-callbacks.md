# Result Callbacks

Result callbacks let the destination report command outcomes back to the source. Laravel Talkto provides a generic signed callback sender and receiver while keeping host business side effects in the host application.

## Sender

`ResultCallbackSenderContract` accepts a destination-side incoming message, an `IncomingCommandResultContract`, and optional transport settings. The default `TalktoResultCallbackSender` builds a signed callback envelope and posts it to the source service callback endpoint.

Destination apps must configure the source service under `talkto.outgoing` with `url`, `secret`, and `callback_endpoint`.

## Receiver

`ResultCallbackReceiverContract` accepts a signed callback envelope and headers. The default `TalktoResultCallbackReceiver` verifies the envelope, matches the original outgoing message, applies the callback status, and records callback events.

Source apps must configure the destination service under `talkto.incoming` and allow the callback command. The default callback command is `talkto.result`.

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

Callback envelopes, signing, verification, lifecycle transitions, and basic event recording are generic package behavior. Deciding what a result means for a host business process remains the host application's job. Hosts can override `ResultCallbackSenderContract` or `ResultCallbackReceiverContract` when they need custom transport or custom side-effect handling.
