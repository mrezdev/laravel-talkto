# Bidirectional Callback Example

Use this shape when `website-service` sends a command to `inventory-service` and expects a signed result callback.

## Source App

Configure the outgoing target:

```php
'service' => env('TALKTO_SERVICE', 'website-service'),

'outgoing' => [
    'inventory-service' => [
        'base_url' => env('TALKTO_INVENTORY_URL'),
        'receive_endpoint' => '/api/talkto/receive',
        'secret' => env('TALKTO_TO_INVENTORY_SECRET'),
        'callback_endpoint' => '/api/talkto/callback',
    ],
],
```

Allow callbacks from the destination. The default callback command is `talkto.result`.

```php
'incoming' => [
    'inventory-service' => [
        'secret' => env('TALKTO_FROM_INVENTORY_SECRET'),
        'allowed_commands' => [
            'talkto.result' => [
                'driver' => 'none',
            ],
        ],
        'allow_all_commands' => false,
    ],
],
```

Send the original command:

```php
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;

$message = app(TalktoOutgoingMessageFactory::class)->create(
    target: 'inventory-service',
    command: 'catalog.reserve-stock',
    payload: ['sku' => 'sku-123', 'quantity' => 2],
    options: [
        'business_key' => 'cart-123',
        'idempotency_key' => 'website-service:catalog.reserve-stock:cart-123:v1',
    ],
);
```

## Target App

Configure the source and handler:

```php
'incoming' => [
    'website-service' => [
        'secret' => env('TALKTO_FROM_WEBSITE_SECRET'),
        'allowed_commands' => [
            'catalog.reserve-stock' => [
                'driver' => 'handler',
                'handler' => App\Talkto\Handlers\ReserveStockHandler::class,
                'idempotency' => 'required',
            ],
        ],
    ],
],

'outgoing' => [
    'website-service' => [
        'base_url' => env('TALKTO_WEBSITE_URL'),
        'receive_endpoint' => '/api/talkto/receive',
        'callback_endpoint' => '/api/talkto/callback',
        'secret' => env('TALKTO_TO_WEBSITE_SECRET'),
    ],
],
```

Return a result from the handler. The package applies the incoming status and then auto-queues the durable callback message.

```php
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

final class ReserveStockHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        return TalktoIncomingCommandResult::succeeded([
            'reserved' => true,
        ]);
    }
}
```

The durable callback is stored as an outgoing `talkto.result` message and delivered by `SendTalktoMessage`.

Manual sending is still supported for advanced flows:

```php
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;

app(ResultCallbackSenderContract::class)->sendResult($message, $result);
```

If manual sending and auto-dispatch both target the same original message/status, Laravel Talkto reuses the deterministic durable callback message and suppresses duplicate queueing where possible. Callback delivery failures still use the normal retry, DLQ, and reprocess paths for outgoing messages.

## Callback Security

Callbacks are signed envelopes. With v2 defaults, the callback has its own nonce. The source app verifies the callback signature, consumes the callback nonce, checks the source/target relationship, finds the original outgoing message, and updates the destination result/status.

A legitimate callback retry should keep the callback `message_id` relationship to the original message but use a fresh v2 nonce for each signed HTTP request.
