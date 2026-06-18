# Handling Commands

The destination application receives a signed envelope, verifies the source, validates the payload hash, checks the command allowlist, stores the incoming message, and queues command processing.

## Handler Contract

Handlers implement `CommandHandlerContract` or the legacy-compatible `TalktoIncomingCommandHandler`.

```php
use Ibake\TalktoReliable\Contracts\CommandHandlerContract;
use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Services\TalktoIncomingCommandResult;

final class DomainCommandHandler implements CommandHandlerContract
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        return TalktoIncomingCommandResult::succeeded(['processed' => true]);
    }
}
```

## Host Responsibility

The package does not know host models, permissions, or write rules. The handler should validate the host payload, perform business work safely, and return a generic result.

## Idempotency

Set `idempotency` to `required` for commands that must reject or deduplicate replayed work.
