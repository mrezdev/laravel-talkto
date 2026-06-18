# Sending Commands

Talkto Reliable creates outgoing message records before transport. This gives the source application a durable lifecycle for status, retries, attempts, and callbacks.

## Flow Builder

Use `TalktoFlowFactory` when a source action must run before the command is sent.

```php
$message = app(\Ibake\TalktoReliable\Services\TalktoFlowFactory::class)
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
$message = app(\Ibake\TalktoReliable\Services\TalktoOutgoingMessageFactory::class)
    ->create('target-service', 'domain.command', ['id' => 123], [
        'business_key' => 'business-key-123',
        'idempotency_key' => 'command-123',
    ]);
```

The queued send job builds a signed envelope and sends it to the configured peer endpoint.
