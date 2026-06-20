# Outgoing-Only Example

Use this shape when `website-service` sends a command to `inventory-service` and does not need a result callback.

## Source App Config

```php
'service' => env('TALKTO_SERVICE', 'website-service'),

'outgoing' => [
    'inventory-service' => [
        'url' => env('TALKTO_INVENTORY_URL'),
        'endpoint' => '/api/talkto/receive',
        'secret' => env('TALKTO_TO_INVENTORY_SECRET'),
        'timeout' => 20,
    ],
],
```

Recommended security env:

```dotenv
TALKTO_SERVICE=website-service
TALKTO_SIGNATURE_VERSION=v2
TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2
TALKTO_REQUIRE_V2_NONCE=true
```

## Sending Code

```php
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;

$message = app(TalktoOutgoingMessageFactory::class)->create(
    target: 'inventory-service',
    command: 'catalog.reserve-stock',
    payload: [
        'sku' => 'sku-123',
        'quantity' => 2,
    ],
    options: [
        'business_key' => 'cart-123',
        'idempotency_key' => 'website-service:catalog.reserve-stock:cart-123:v1',
    ],
);
```

The factory creates a durable outgoing message. A queue worker sends it and retry policy handles retryable failures.

```bash
php artisan queue:work
php artisan talkto:retry-failed --dry-run
php artisan talkto:trace <message-id>
```

The host app decides when to create local business records and how to map them to `business_key` or `idempotency_key`.
