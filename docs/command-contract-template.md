# Command Contract Template

Use this template before adding a command to `talkto.incoming`.

## Command Name Naming Guide

Use a stable, explicit name:

- Format: `area:verb-object`
- Example: `example:sync-record`
- Keep names lowercase.
- Do not encode environment, URL, or service secrets in the name.
- Do not rename a command after other services depend on it; create a versioned command instead.

## Payload Schema Guide

Define the payload shape in the host docs and tests:

```json
{
  "schema_version": 1,
  "record": {
    "external_id": "example-123",
    "status": "ready"
  },
  "requested_by": {
    "service": "<source-service>"
  }
}
```

Rules:

- Include a schema version.
- Use stable keys.
- Keep payloads minimal.
- Avoid secrets, raw credentials, and internal-only data.
- Redact sensitive fields in logs and callbacks.

## Idempotency Key Guide

Use one deterministic key for one logical command. A retry of the same logical command must reuse the same key. A new logical command must get a new key.

Suggested shape:

```text
<source-service>:example:sync-record:<business-key>:v1
```

## Correlation Id Guide

Use a correlation id to connect source logs, destination logs, attempts, callbacks, and monitoring views. The correlation id should not be a secret and should be safe to expose to operators.

## Handler Contract Example

```php
use Mrezdev\LaravelTalkto\Contracts\CommandHandlerContract;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

final class ExampleCommandHandler implements CommandHandlerContract
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        $payload = $message->payload ?? [];

        if (($payload['schema_version'] ?? null) !== 1) {
            return TalktoIncomingCommandResult::failed([
                'code' => 'unsupported_schema_version',
            ]);
        }

        return TalktoIncomingCommandResult::succeeded([
            'processed' => true,
        ]);
    }
}
```

## Success Result Example

```json
{
  "status": "succeeded",
  "result": {
    "processed": true,
    "external_id": "example-123"
  }
}
```

## Failure Result Example

```json
{
  "status": "failed",
  "result": {
    "code": "validation_failed",
    "message": "Payload did not pass command validation."
  }
}
```

## Validation Error Strategy

Return structured, redacted errors. Do not include raw payloads, secrets, stack traces, or database details. Classify whether the sender may retry after correcting input.

## Versioning Strategy

Add `schema_version` to the payload. For breaking changes, create a new command or accept both versions during a transition period.

## Backward Compatibility Strategy

Receivers should tolerate unknown optional fields and reject missing required fields with a clear failure result. Source services should keep sending the old shape until all destination services announce support for the new shape.
