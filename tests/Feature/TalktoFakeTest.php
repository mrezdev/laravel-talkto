<?php

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClientWithOptions;
use Mrezdev\LaravelTalkto\Facades\Talkto;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\LaravelTalktoHttpClient;
use Mrezdev\LaravelTalkto\Services\TalktoFlowFactory;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    expect($this->artisan('migrate')->run())->toBe(0);
    talktoFakeConfigure();
    TalktoFakeUnrelatedJob::$handled = 0;
});

test('talkto transport remains the default implementation without explicit fake activation', function (): void {
    expect(app(TalktoHttpClient::class))->toBeInstanceOf(LaravelTalktoHttpClient::class)
        ->and(Talkto::isFake())->toBeFalse();
});

test('talkto fake replaces only its own transport binding and supports both transport signatures', function (): void {
    $http = Http::getFacadeRoot();
    $queue = Queue::getFacadeRoot();

    Talkto::fake();

    $client = app(TalktoHttpClient::class);
    expect(Talkto::isFake())->toBeTrue()
        ->and($client)->toBeInstanceOf(TalktoHttpClientWithOptions::class)
        ->and(Http::getFacadeRoot())->toBe($http)
        ->and(Queue::getFacadeRoot())->toBe($queue);

    foreach ([
        $client->post('https://inventory.test', [], [], 10),
        $client->postWithOptions('https://inventory.test', [], [], 10, ['verify' => true]),
    ] as $response) {
        expect($response->status())->toBe(202)
            ->and($response->successful())->toBeTrue()
            ->and($response->json('received'))->toBeTrue()
            ->and($response->json('accepted'))->toBeTrue();
    }

    Talkto::assertNothingSent();
});

test('talkto assertions require explicit fake activation', function (Closure $assertion): void {
    expect($assertion)->toThrow(LogicException::class, 'Call Talkto::fake() before using Talkto assertions.');
    expect(app(TalktoHttpClient::class))->toBeInstanceOf(LaravelTalktoHttpClient::class);
})->with([
    'sent' => [fn () => Talkto::assertSent('inventory', 'stock.reserve')],
    'not sent' => [fn () => Talkto::assertNotSent('inventory', 'stock.reserve')],
    'nothing sent' => [fn () => Talkto::assertNothingSent()],
    'sent times' => [fn () => Talkto::assertSentTimes('inventory', 'stock.reserve', 0)],
]);

test('factory creation remains persisted and assertable before dispatching a send job', function (): void {
    Talkto::fake();
    $message = app(TalktoOutgoingMessageFactory::class)->create(
        target: 'inventory', command: 'stock.reserve', payload: ['sku' => 'ABC', 'quantity' => 2]
    );

    Talkto::assertSent(target: 'inventory', command: 'stock.reserve');
    Talkto::assertSentTimes('inventory', 'stock.reserve', 1);
    expect($message->fresh()->payload)->toBe(['sku' => 'ABC', 'quantity' => 2])
        ->and($message->fresh()->overall_status)->toBe('waiting_to_send')
        ->and($message->fresh()->transport_status)->toBe('pending')
        ->and($message->fresh()->attempts)->toBe(0)
        ->and($message->attempts()->count())->toBe(0)
        ->and($message->events()->sole()->event_type)->toBe('message_created');
});

test('queued talkto send runs the existing pipeline without calling laravel http', function (): void {
    Http::shouldReceive('withHeaders')->never();
    Talkto::fake();

    $message = app(TalktoFlowFactory::class)->flow('reserve-stock')
        ->to('inventory')->command('stock.reserve')->payload(['sku' => 'ABC'])->send();

    Talkto::assertSent('inventory', 'stock.reserve');
    expect($message->fresh()->transport_status)->toBe('sent')
        ->and($message->fresh()->overall_status)->toBe('destination_received')
        ->and($message->fresh()->destination_action_status)->toBe('accepted')
        ->and($message->fresh()->last_http_status)->toBe(202)
        ->and($message->fresh()->attempts)->toBe(1)
        ->and($message->attempts()->sole()->status)->toBe('sent')
        ->and($message->events()->pluck('event_type')->all())->toBe([
            'message_created', 'message_sending_started', 'message_sent',
        ]);
});

test('unrelated laravel http requests retain the host configured behavior', function (): void {
    Http::fake(['https://host.test/report' => Http::response(['host' => 'unchanged'], 201)]);
    Http::preventStrayRequests();
    $http = Http::getFacadeRoot();
    Talkto::fake();

    $response = Http::get('https://host.test/report');
    app(TalktoFlowFactory::class)->flow('reserve-stock')
        ->to('inventory')->command('stock.reserve')->send();

    expect(Http::getFacadeRoot())->toBe($http)
        ->and($response->status())->toBe(201)
        ->and($response->json('host'))->toBe('unchanged');
    Http::assertSent(fn ($request) => $request->url() === 'https://host.test/report');
    Http::assertSentCount(1);
    Talkto::assertSent('inventory', 'stock.reserve');
});

test('unrelated laravel queue jobs execute through the existing queue', function (): void {
    $queue = Queue::getFacadeRoot();
    Talkto::fake();
    TalktoFakeUnrelatedJob::dispatch();

    expect(Queue::getFacadeRoot())->toBe($queue)
        ->and(TalktoFakeUnrelatedJob::$handled)->toBe(1);
    Talkto::assertNothingSent();
});

test('negative nothing and zero count assertions pass for no outgoing messages', function (): void {
    Talkto::fake();
    Talkto::assertNotSent('inventory', 'stock.release');
    Talkto::assertNothingSent();
    Talkto::assertSentTimes('inventory', 'stock.reserve', 0);
});

test('wrong target or command produces a useful assertion failure', function (string $target, string $command): void {
    Talkto::fake();
    app(TalktoOutgoingMessageFactory::class)->create('inventory', 'stock.reserve');

    expect(fn () => Talkto::assertSent($target, $command))->toThrow(
        AssertionFailedError::class,
        "Expected Talkto message [{$command}] to be sent to [{$target}] at least once, but found 0 matching messages."
    );
})->with([
    ['billing', 'stock.reserve'],
    ['inventory', 'stock.release'],
    ['unconfigured', 'stock.reserve'],
]);

test('negative and count failures show the expected and actual matches', function (Closure $assertion, string $failure): void {
    Talkto::fake();
    app(TalktoOutgoingMessageFactory::class)->create('inventory', 'stock.reserve');

    expect($assertion)->toThrow(AssertionFailedError::class, $failure);
})->with([
    'not sent' => [fn () => Talkto::assertNotSent('inventory', 'stock.reserve'),
        'Expected no Talkto message [stock.reserve] to be sent to [inventory], but found 1 matching messages.'],
    'nothing sent' => [fn () => Talkto::assertNothingSent(),
        'Expected no outgoing Talkto messages, but found 1 messages.'],
    'zero count' => [fn () => Talkto::assertSentTimes('inventory', 'stock.reserve', 0),
        'Expected Talkto message [stock.reserve] to be sent to [inventory] 0 times, but found 1 matching messages.'],
    'two count' => [fn () => Talkto::assertSentTimes('inventory', 'stock.reserve', 2),
        'Expected Talkto message [stock.reserve] to be sent to [inventory] 2 times, but found 1 matching messages.'],
]);

test('sent count supports one and multiple distinct durable messages', function (int $times): void {
    Talkto::fake();
    for ($index = 0; $index < $times; $index++) {
        app(TalktoOutgoingMessageFactory::class)->create('inventory', 'stock.reserve', ['index' => $index]);
    }

    Talkto::assertSentTimes('inventory', 'stock.reserve', $times);
})->with([1, 3]);

test('predicates distinguish messages within the same target and command', function (): void {
    Talkto::fake();
    $factory = app(TalktoOutgoingMessageFactory::class);
    $factory->create('inventory', 'stock.reserve', ['sku' => 'ABC', 'quantity' => 2]);
    $factory->create('inventory', 'stock.reserve', ['sku' => 'DEF', 'quantity' => 1]);
    $factory->create('inventory', 'stock.release', ['sku' => 'ABC', 'quantity' => 2]);
    $factory->create('billing', 'invoice.issue', ['sku' => 'ABC', 'quantity' => 2]);

    $predicate = fn (TalktoMessage $message): bool => $message->payload['sku'] === 'ABC'
        && $message->payload['quantity'] === 2;
    Talkto::assertSent('inventory', 'stock.reserve', callback: $predicate);
    Talkto::assertSentTimes('inventory', 'stock.reserve', 2);
    Talkto::assertSentTimes('inventory', 'stock.reserve', 1, $predicate);
    Talkto::assertSent('inventory', 'stock.release', $predicate);
    Talkto::assertSent('billing', 'invoice.issue', $predicate);
    Talkto::assertNotSent('inventory', 'stock.reserve', fn (TalktoMessage $message) => $message->payload['sku'] === 'MISSING');
    Talkto::assertSentTimes('billing', 'stock.reserve', 0);
    expect(fn () => Talkto::assertNotSent('inventory', 'stock.reserve', $predicate))
        ->toThrow(AssertionFailedError::class, 'found 1 matching messages');
});

test('nonmatching predicate fails positive and count assertions', function (): void {
    Talkto::fake();
    app(TalktoOutgoingMessageFactory::class)->create('inventory', 'stock.reserve', ['sku' => 'ABC']);
    $predicate = fn (TalktoMessage $message): bool => $message->payload['sku'] === 'DEF';

    expect(fn () => Talkto::assertSent('inventory', 'stock.reserve', $predicate))
        ->toThrow(AssertionFailedError::class, '[stock.reserve] to be sent to [inventory] at least once, but found 0');
    expect(fn () => Talkto::assertSentTimes('inventory', 'stock.reserve', 1, $predicate))
        ->toThrow(AssertionFailedError::class, '1 times, but found 0 matching messages');
});

test('fake preserves aliases correlation idempotency payload hash and event metadata', function (): void {
    config(['talkto.aliases.stock' => 'inventory']);
    Talkto::fake();
    $factory = app(TalktoOutgoingMessageFactory::class);
    $options = [
        'correlation_id' => 'correlation-1', 'parent_message_id' => 'parent-1',
        'business_key' => 'order-1', 'idempotency_key' => 'reserve-1', 'schema_version' => 2,
        'flow_name' => 'reserve-stock', 'source_result' => ['order' => 1], 'source_meta' => ['actor' => 'test'],
    ];
    $first = $factory->create('stock', 'stock.reserve', ['sku' => 'ABC', 'quantity' => 2], $options);
    $duplicate = $factory->create('inventory', 'stock.reserve', ['sku' => 'DEF'], $options);

    expect($duplicate->id)->toBe($first->id);
    Talkto::assertSentTimes('stock', 'stock.reserve', 1);
    Talkto::assertSent('inventory', 'stock.reserve', function (TalktoMessage $message) use ($first): bool {
        $event = $message->events()->sole();

        return $message->id === $first->id
            && $message->target_service === 'inventory'
            && $message->correlation_id === 'correlation-1'
            && $message->parent_message_id === 'parent-1'
            && $message->business_key === 'order-1'
            && $message->idempotency_key === 'reserve-1'
            && $message->schema_version === 2
            && $message->payload === ['sku' => 'ABC', 'quantity' => 2]
            && $message->payload_hash === app(TalktoPayloadHasher::class)->hash($message->payload)
            && $event->meta['flow_name'] === 'reserve-stock'
            && $event->meta['source_result'] === ['order' => 1]
            && $event->meta['source_meta'] === ['actor' => 'test'];
    });
});

test('fake excludes earlier messages including idempotent reuse and resets on another activation', function (): void {
    $factory = app(TalktoOutgoingMessageFactory::class);
    $prior = $factory->create('inventory', 'stock.reserve', [], ['idempotency_key' => 'prior']);
    Talkto::fake();
    expect($factory->create('inventory', 'stock.reserve', [], ['idempotency_key' => 'prior'])->id)->toBe($prior->id);
    Talkto::assertNothingSent();

    $factory->create('inventory', 'stock.reserve');
    Talkto::assertSentTimes('inventory', 'stock.reserve', 1);
    Talkto::fake();
    Talkto::assertNothingSent();
    $factory->create('inventory', 'stock.reserve');
    Talkto::assertSentTimes('inventory', 'stock.reserve', 1);
});

test('assertions exclude incoming records and rolled back outgoing records', function (): void {
    Talkto::fake();
    $factory = app(TalktoOutgoingMessageFactory::class);
    $factory->create('inventory', 'stock.reserve')->forceFill(['direction' => 'incoming'])->save();
    Talkto::assertNothingSent();

    DB::beginTransaction();
    try {
        $factory->create('inventory', 'stock.reserve');
        Talkto::assertSentTimes('inventory', 'stock.reserve', 1);
    } finally {
        DB::rollBack();
    }
    Talkto::assertNothingSent();
});

test('fake retains after commit dispatch timing', function (): void {
    Http::shouldReceive('withHeaders')->never();
    Talkto::fake();
    DB::beginTransaction();
    try {
        $message = app(TalktoFlowFactory::class)->flow('reserve-stock')
            ->to('inventory')->command('stock.reserve')->send();
        Talkto::assertSent('inventory', 'stock.reserve');
        expect($message->fresh()->attempts)->toBe(0);
        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
    expect($message->fresh()->attempts)->toBe(1)
        ->and($message->fresh()->transport_status)->toBe('sent');
});

test('fake acknowledges durable callbacks through the existing callback send path', function (): void {
    Http::shouldReceive('withHeaders')->never();
    Talkto::fake();
    $message = app(TalktoOutgoingMessageFactory::class)->create('inventory', 'talkto.result', [
        'original_message_id' => 'original-1', 'original_command' => 'stock.reserve', 'status' => 'succeeded',
    ]);
    SendTalktoMessage::dispatch($message->id);

    Talkto::assertSent('inventory', 'talkto.result', fn (TalktoMessage $message) => $message->payload['original_message_id'] === 'original-1');
    expect($message->fresh()->transport_status)->toBe('sent')
        ->and($message->fresh()->overall_status)->toBe('completed')
        ->and($message->fresh()->destination_action_status)->toBe('accepted')
        ->and($message->attempts()->sole()->meta['result_callback_delivery'])->toBeTrue()
        ->and(TalktoDeadLetter::query()->count())->toBe(0);
});

test('configured message models connection and table remain supported by fake assertions', function (): void {
    config([
        'database.connections.talkto_fake_storage' => config('database.connections.sqlite'),
        'talkto.database.connection' => 'talkto_fake_storage',
        'talkto.database.tables.messages' => 'fake_test_messages',
        'talkto.models.message' => TalktoFakeCustomMessage::class,
    ]);
    $this->loadMigrationsFrom([
        '--path' => __DIR__.'/../../database/migrations',
        '--database' => 'talkto_fake_storage',
    ]);
    Talkto::fake();
    $message = app(TalktoOutgoingMessageFactory::class)->create('inventory', 'stock.reserve');

    Talkto::assertSent('inventory', 'stock.reserve', fn (TalktoFakeCustomMessage $message) => $message->getConnectionName() === 'talkto_fake_storage');
    expect($message)->toBeInstanceOf(TalktoFakeCustomMessage::class)
        ->and(DB::connection('talkto_fake_storage')->table('fake_test_messages')->count())->toBe(1)
        ->and(DB::connection('sqlite')->table('talkto_messages')->count())->toBe(0);
});

test('fake transport and assertion state are cleared when laravel recreates the application', function (): void {
    Talkto::fake();
    app(TalktoOutgoingMessageFactory::class)->create('inventory', 'stock.reserve');
    Talkto::assertSent('inventory', 'stock.reserve');
    $fake = Talkto::getFacadeRoot();

    $this->refreshApplication();

    expect(Talkto::isFake())->toBeFalse()
        ->and(app(TalktoHttpClient::class))->toBeInstanceOf(LaravelTalktoHttpClient::class);
    expect(fn () => Talkto::assertNothingSent())->toThrow(LogicException::class);
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    expect($this->artisan('migrate')->run())->toBe(0);
    talktoFakeConfigure();
    Talkto::fake();
    expect(Talkto::getFacadeRoot())->not->toBe($fake);
    Talkto::assertNothingSent();
});

test('negative expected count is rejected with a clear error', function (): void {
    Talkto::fake();
    expect(fn () => Talkto::assertSentTimes('inventory', 'stock.reserve', -1))
        ->toThrow(InvalidArgumentException::class, 'Talkto message count must be zero or greater.');
});

function talktoFakeConfigure(): void
{
    config([
        'queue.default' => 'sync',
        'talkto.service' => 'testing',
        'talkto.outgoing.inventory' => ['base_url' => 'https://inventory.test', 'secret' => 'test-secret'],
        'talkto.outgoing.billing' => ['base_url' => 'https://billing.test', 'secret' => 'test-secret'],
    ]);
}

class TalktoFakeUnrelatedJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;
    }
}

class TalktoFakeCustomMessage extends TalktoMessage {}
