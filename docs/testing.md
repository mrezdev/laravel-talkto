# Testing

Package tests use Orchestra Testbench and Pest. The committed test entry points are:

- `phpunit.xml.dist`
- `tests/TestCase.php`
- `tests/Pest.php`

## Testing Application Integrations

Import `Mrezdev\LaravelTalkto\Facades\Talkto` in PHPUnit or Pest tests. Configure normal outgoing targets and shared secrets, and prepare the existing Talkto tables in your testing database before activating the fake.

```php
use Mrezdev\LaravelTalkto\Facades\Talkto;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;

Talkto::fake();

app(TalktoOutgoingMessageFactory::class)->create(
    target: 'inventory',
    command: 'stock.reserve',
    payload: ['sku' => 'ABC', 'quantity' => 2],
    options: ['correlation_id' => 'order-123', 'idempotency_key' => 'reserve-order-123'],
);

Talkto::assertSent(target: 'inventory', command: 'stock.reserve');
Talkto::assertSent(
    'inventory',
    'stock.reserve',
    callback: fn (TalktoMessage $message) =>
        $message->payload['sku'] === 'ABC'
        && $message->payload['quantity'] === 2
        && $message->correlation_id === 'order-123'
        && $message->idempotency_key === 'reserve-order-123',
);
Talkto::assertSentTimes('inventory', 'stock.reserve', 1);
Talkto::assertNotSent('inventory', 'stock.release');
```

For an action that creates two messages, assert the exact count. Counts also accept a predicate:

```php
Talkto::assertSentTimes('inventory', 'stock.reserve', 2);
Talkto::assertSentTimes('inventory', 'stock.reserve', 1,
    fn (TalktoMessage $message) => $message->payload['sku'] === 'ABC'
);
```

For an action that should create nothing:

```php
Talkto::fake();
// Perform the application action.
Talkto::assertNothingSent();
Talkto::assertSentTimes('inventory', 'stock.reserve', 0);
```

`Talkto::fake()` prevents Talkto remote delivery only. It does not globally fake Laravel queues or HTTP requests.

The fake replaces the `TalktoHttpClient` container binding. Jobs still run through the normal pipeline, including envelope validation, signing, attempts, events, and status updates. Delivery gets a synthetic HTTP 202 acknowledgment with `received=true`, `accepted=true`, and `status=accepted`; remote handlers and incoming result callbacks are not simulated. Use a synchronous/local test queue for jobs that should execute in the same application process. Jobs executing in a separate worker do not inherit the fake. Activate it before resolving send services; existing objects holding a different transport are not replaced.

Here, **sent means a persisted outgoing message was created after the latest `fake()` call**, regardless of its delivery status. The fake records the current maximum message primary key at activation and queries newer outgoing rows for assertions. It retains the configured model, connection, and table; keep storage configuration stable and do not truncate or reset primary keys while the fake is active. Assertions work inside test transactions and omit rolled-back or deleted messages. Earlier messages, including earlier records reused by idempotency, are excluded. Idempotent reuse of a message created after activation counts once; repeated delivery attempts do not increase the count. Factory `create()` remains persistence-only and does not dispatch a job.

Predicates receive the current persisted `TalktoMessage` (or configured subclass), so payload, correlation, idempotency, status, and related event metadata remain inspectable. `assertSent()` requires at least one match; `assertNotSent()` requires zero matches. Their optional predicate, and the predicate on `assertSentTimes()`, filters only messages with the requested target and command. Target aliases are supported. `assertNothingSent()` checks all new outgoing rows, including durable result callback messages. Calling assertions before `fake()` throws a clear `LogicException`.

Calling `fake()` again starts a new assertion scope. Laravel application recreation restores the ordinary transport binding and discards fake state; no manual reset is needed between normal Laravel tests. The implementation class in `Testing` is internal; use the facade.

## Standalone Package Tests

Run tests from the package directory after package-local development dependencies are installed:

```bash
cd packages/laravel-talkto
composer install
vendor/bin/pest
```

PHPUnit can also use the committed default config:

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

If `vendor/bin/pest` and `vendor/bin/phpunit` are missing, the package-local dependencies have not been installed. Host phases should record that state instead of running Composer install/update unless the phase explicitly allows it.

## Package Coverage

- provider boot and config merge
- install experience and safe defaults
- routes and migrations disabled by default
- model and service resolution
- configured model subclasses
- signing and verification
- deterministic payload hashing
- public contracts and exceptions
- source leakage checks for host business terms

## Host Compatibility Coverage

Host applications should add focused tests for their wrappers, handlers, callback senders, callback receivers, recovery commands, monitoring endpoints, and local HTTP end-to-end flows.

Use testing databases and local-only queues. Do not test against production traffic.

## Local End-To-End Strategy

1. Start two local Laravel services with testing configuration.
2. Use local URLs and non-production shared secrets.
3. Configure one outgoing peer and one incoming source.
4. Send a small generic command with an idempotency key.
5. Confirm the destination records the incoming message, attempts, and events.
6. Run or fake the source command `SendTalktoMessage` job.
7. Run or fake the destination `ProcessIncomingTalktoMessage` job.
8. Confirm the destination auto-creates an outgoing durable callback message.
9. Run or fake the destination callback `SendTalktoMessage` job.
10. Confirm the source receives the signed result callback.
11. Inspect queues and failed jobs before repeating the flow.

Do not assume `ResultCallbackSenderContract::sendResult()` sends callback HTTP immediately. It queues durable callback delivery; local E2E tests should run the queued callback `SendTalktoMessage` job when they want the source-side status to update in the same test.

The package provides the transport and lifecycle records. The host application verifies its own command handler behavior.
