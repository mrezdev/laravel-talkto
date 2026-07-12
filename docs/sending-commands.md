# Sending Commands

Laravel Talkto creates outgoing message records before transport. This gives the source application a durable lifecycle for status, retries, attempts, and callbacks.

## Flow Builder

Use `TalktoFlowFactory` when a source action must run before the command is sent.

```php
$message = app(\Mrezdev\LaravelTalkto\Services\TalktoFlowFactory::class)
    ->flow('source-action-name')
    ->to('target-service')
    ->command('domain.command')
    ->businessKey('business-key-123')
    ->idempotencyKey('command-123')
    ->run(fn () => [
        'payload' => ['id' => 123],
        'result' => ['source_action' => 'completed'],
    ]);
```

## Direct Message Factory

Use `TalktoOutgoingMessageFactory` when the host already has a payload and does not need source-action wrapping.

```php
$message = app(\Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory::class)
    ->create('target-service', 'domain.command', ['id' => 123], [
        'business_key' => 'business-key-123',
        'idempotency_key' => 'command-123',
    ]);
```

The queued send job builds a signed envelope and sends it to the configured peer endpoint.

## Payload Boundary

`TalktoOutgoingMessageFactory` freezes the payload before it calculates `payload_hash` or writes `talkto_messages.payload`. Repeated references to the same supported object are converted once per freeze operation. The same frozen primitive tree is then reused by envelope building, signing, the default HTTP body, retries, DLQ rows, durable callbacks, and payload-hash repair.

Pass arrays, JSON primitives, Laravel collections, Laravel `Arrayable` values, `JsonSerializable` values, backed enums, Carbon/JSON-serializable date objects, `stdClass`, or simple public-property objects. Pass strings explicitly instead of relying on `Stringable`, and format native `DateTimeInterface` values explicitly before sending. Do not pass resources, closures, generators, traversable/internal hidden-state objects, pure enums, non-finite floats, invalid UTF-8, circular references, or deeply recursive structures.

If an `idempotency_key` points to an existing outgoing message, the factory returns that row before freezing a newly supplied payload because the idempotency fingerprint is metadata-only.

## Generated Outgoing Scaffolding

For generated outgoing clients, send actions, payload builders, and command enums, see [scaffolding.md](scaffolding.md). For transactional source-side creation, see [transactional-outgoing.md](transactional-outgoing.md).
