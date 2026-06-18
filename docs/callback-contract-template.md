# Callback Contract Template

Use result callbacks when the destination service needs to report command outcome back to the source service.

## Callback Payload Structure

```json
{
  "message_id": "source-message-id",
  "source": "<destination-service>",
  "target": "<source-service>",
  "command": "example:sync-record",
  "status": "succeeded",
  "result": {
    "processed": true
  },
  "correlation_id": "correlation-id"
}
```

The callback should include enough data to match the original source message and update the source-side lifecycle. It should not include secrets or unredacted internal data.

## Result Statuses

Use a small status set:

- `succeeded`
- `failed`
- `rejected`
- `retryable`

Document exactly what each status means for the source service.

## Signature And Hash Verification

The source service must verify the callback signature, timestamp, message id, source service, target service, command, and payload hash before changing source-side state.

Reject callbacks with invalid signatures, stale timestamps, unknown source services, unknown message ids, or payload hash mismatches.

## Source Message Matching

Match callbacks to the original outgoing message by message id and expected peer service. The command and correlation id should also match when available.

If no source message exists, record a redacted failure event and return the host-approved response. Do not create a source message from an unexpected callback.

## Duplicate Callback Handling

Callbacks must be idempotent. A duplicate callback for the same final state should not run side effects twice. If a duplicate carries conflicting data, mark it for review.

## Callback Failure And Retry Handling

The destination may retry callback delivery for temporary failures. The source should return clear status codes and avoid exposing secrets in response bodies.

Classify failures:

- Retryable: timeout, temporary queue issue, temporary unavailable source.
- Review first: message not found, command mismatch, unexpected state.
- Do not retry automatically: invalid signature, payload hash mismatch, unknown peer.

## Redaction And Logging Rules

Log callback ids, message ids, peer services, command names, statuses, and redacted error codes. Do not log shared secrets, raw signature headers, full payloads, or sensitive result data.
