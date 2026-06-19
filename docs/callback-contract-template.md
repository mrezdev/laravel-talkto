# Callback Runtime And Override Template

Use result callbacks when the destination service needs to report command outcome back to the source service.

Laravel Talkto now provides a generic signed result callback runtime. Hosts may use the default runtime or override `ResultCallbackSenderContract` and `ResultCallbackReceiverContract` with their own implementations.

The default callback command is:

```text
talkto.result
```

## Callback Envelope

Callbacks use the same signed Talkto envelope shape as command delivery. The callback envelope is sent from the destination service back to the source service.

The callback payload contains:

- `original_message_id`
- `original_command`
- `status`
- `succeeded`
- `retryable`
- `skipped`
- `error_class`
- `error_message`
- `result`
- `meta`

Example payload:

```json
{
  "original_message_id": "source-message-id",
  "original_command": "domain.command",
  "status": "succeeded",
  "succeeded": true,
  "retryable": false,
  "skipped": false,
  "error_class": null,
  "error_message": null,
  "result": {
    "processed": true
  },
  "meta": {}
}
```

## Valid Callback Statuses

The runtime accepts these callback statuses:

- `succeeded`
- `skipped`
- `failed_retryable`
- `failed_final`

Unknown statuses are rejected.

## Signature And Hash Verification

The source service verifies the callback signature, timestamp, message id, source service, target service, command, and payload hash before changing source-side state.

Callbacks with invalid signatures, stale timestamps, unknown source services, unknown message ids, command mismatches, parent message mismatches, or payload hash mismatches are rejected.

## Source Message Matching

The runtime matches callbacks to the original outgoing message by `payload.original_message_id` and expected peer services. The callback `payload.original_command` must match the original message command. When `parent_message_id` is present, it must match the original message id.

If no source message exists, the runtime returns a rejected response and does not create a source message from the callback.

## Duplicate Callback Handling

Callbacks are idempotent. A duplicate callback for the same final state is accepted as a duplicate and does not apply the same source-side state transition twice.

## Host Side Effects

The package updates Talkto message state after a valid callback is applied. Host apps still own any business side effects that happen after the callback state is applied.

## Redaction And Logging Rules

Log callback ids, message ids, peer services, command names, statuses, and redacted error codes. Do not log shared secrets, raw signature headers, full payloads, or sensitive result data.
