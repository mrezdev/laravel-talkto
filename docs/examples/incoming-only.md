# Incoming-Only Example

Use this shape when `inventory-service` receives commands from `website-service`.

## Target App Config

```php
'service' => env('TALKTO_SERVICE', 'inventory-service'),

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
        'allow_all_commands' => false,
    ],
],
```

Recommended security env:

```dotenv
TALKTO_SERVICE=inventory-service
TALKTO_REQUIRE_SIGNATURE=true
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
TALKTO_REQUIRE_V2_NONCE=true
TALKTO_REPLAY_PROTECTION_ENABLED=true
```

## Handler

```php
<?php

namespace App\Talkto\Handlers;

use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

final class ReserveStockHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        $payload = $message->payload ?? [];

        if (! isset($payload['sku'], $payload['quantity'])) {
            return TalktoIncomingCommandResult::failedFinal('Missing stock reservation fields.');
        }

        return TalktoIncomingCommandResult::succeeded([
            'reserved' => true,
            'sku' => $payload['sku'],
        ]);
    }
}
```

The package verifies the envelope, applies nonce replay protection, checks `message_id` idempotency, stores the inbox row, and runs the configured handler.

Run a worker if incoming processing is queued:

```bash
php artisan queue:work
```
