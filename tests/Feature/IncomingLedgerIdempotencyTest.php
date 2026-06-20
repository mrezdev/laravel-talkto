<?php

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoReceiveController;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.security.require_signature' => false,
        'talkto.security.require_timestamp' => false,
        'talkto.security.accept_versions' => ['v1'],
        'talkto.incoming.source' => [
            'allowed_commands' => [
                'domain.command' => [
                    'driver' => 'none',
                ],
                'domain.other-command' => [
                    'driver' => 'none',
                ],
            ],
        ],
        'talkto.incoming.source-b' => [
            'allowed_commands' => [
                'domain.command' => [
                    'driver' => 'none',
                ],
            ],
        ],
        'talkto.models.message' => TalktoMessage::class,
    ]);

    IncomingLedgerRaceTalktoMessage::$hideFirstLookup = false;
    IncomingLedgerRaceTalktoMessage::$hideLookupNumber = null;
    IncomingLedgerRaceTalktoMessage::$lookupCount = 0;
});

test('message with idempotency key stores idempotency fingerprint', function (): void {
    $message = talktoIncomingMessage('incoming-fingerprint', [
        'idempotency_key' => 'fingerprint-key',
    ]);

    expect($message->idempotency_fingerprint)->toBe(TalktoMessage::idempotencyFingerprint(
        'incoming',
        'source',
        'testing',
        'domain.command',
        'fingerprint-key'
    ));
});

test('message without idempotency key stores null idempotency fingerprint', function (): void {
    $message = talktoIncomingMessage('incoming-no-fingerprint');

    expect($message->idempotency_fingerprint)->toBeNull();
});

test('duplicate message id receive returns duplicate and dispatches once', function (): void {
    Queue::fake();

    $first = talktoReceive(talktoEnvelope('incoming-duplicate'));
    $second = talktoReceive(talktoEnvelope('incoming-duplicate'));

    expect($first->getStatusCode())->toBe(202)
        ->and($second->getStatusCode())->toBe(200)
        ->and($second->getData(true))->toMatchArray([
            'received' => true,
            'duplicate' => true,
            'status' => 'already_received',
            'message_id' => 'incoming-duplicate',
        ])
        ->and(TalktoMessage::query()->where('message_id', 'incoming-duplicate')->count())->toBe(1);

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 1);
});

test('duplicate key race during receive is handled as already received', function (): void {
    Queue::fake();

    talktoIncomingMessage('incoming-race');

    config(['talkto.models.message' => IncomingLedgerRaceTalktoMessage::class]);
    IncomingLedgerRaceTalktoMessage::$hideFirstLookup = true;

    $response = talktoReceive(talktoEnvelope('incoming-race'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toMatchArray([
            'received' => true,
            'duplicate' => true,
            'status' => 'already_received',
            'message_id' => 'incoming-race',
        ])
        ->and(TalktoMessage::query()->where('message_id', 'incoming-race')->count())->toBe(1);

    Queue::assertNotPushed(ProcessIncomingTalktoMessage::class);
});

test('duplicate key detection uses the configured message table', function (): void {
    $method = new ReflectionMethod(ReceiveIncomingTalktoMessagePipeline::class, 'isDuplicateMessageIdException');
    $exception = new QueryException(
        'sqlite',
        'insert into custom_talkto_messages',
        [],
        new RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: custom_talkto_messages.message_id')
    );

    expect($method->invoke(app(ReceiveIncomingTalktoMessagePipeline::class), $exception, 'custom_talkto_messages'))->toBeTrue()
        ->and($method->invoke(app(ReceiveIncomingTalktoMessagePipeline::class), $exception, 'other_messages'))->toBeFalse();
});

test('database unique idempotency fingerprint prevents duplicate stored messages', function (): void {
    $existing = talktoIncomingMessage('incoming-fingerprint-race-original', [
        'idempotency_key' => 'fingerprint-race',
        'overall_status' => 'queued',
    ]);

    expect(fn () => talktoIncomingMessage('incoming-fingerprint-race-new', [
        'idempotency_key' => 'fingerprint-race',
    ]))->toThrow(QueryException::class);

    expect(TalktoMessage::query()->where('idempotency_fingerprint', $existing->idempotency_fingerprint)->count())->toBe(1);
});

test('idempotency key after success returns already processed and does not dispatch', function (): void {
    Queue::fake();

    talktoIncomingMessage('incoming-processed', [
        'idempotency_key' => 'business-once',
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);

    $response = talktoReceive(talktoEnvelope('incoming-new-message', 'business-once'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toMatchArray([
            'received' => true,
            'duplicate' => true,
            'status' => 'already_processed',
            'message_id' => 'incoming-processed',
        ])
        ->and(TalktoMessage::query()->where('idempotency_key', 'business-once')->count())->toBe(1)
        ->and(TalktoMessage::query()->whereNotNull('idempotency_fingerprint')->count())->toBe(1);

    Queue::assertNotPushed(ProcessIncomingTalktoMessage::class);
});

test('idempotency key protects active incoming states without dispatching again', function (): void {
    Queue::fake();

    foreach (['queued', 'processing', 'failed_retryable'] as $status) {
        $key = "business-once-{$status}";

        talktoIncomingMessage("incoming-active-{$status}", [
            'idempotency_key' => $key,
            'destination_action_status' => $status,
            'overall_status' => $status,
        ]);

        $response = talktoReceive(talktoEnvelope("incoming-active-duplicate-{$status}", $key));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toMatchArray([
                'received' => true,
                'duplicate' => true,
                'status' => 'already_accepted',
                'message_id' => "incoming-active-{$status}",
            ])
            ->and(TalktoMessage::query()->where('idempotency_key', $key)->count())->toBe(1);
    }

    Queue::assertNotPushed(ProcessIncomingTalktoMessage::class);
});

test('idempotency key after completed or succeeded incoming work returns already processed', function (): void {
    Queue::fake();

    foreach (['completed', 'succeeded'] as $status) {
        $key = "business-done-{$status}";

        talktoIncomingMessage("incoming-done-{$status}", [
            'idempotency_key' => $key,
            'destination_action_status' => $status,
            'overall_status' => $status,
            'completed_at' => now(),
        ]);

        $response = talktoReceive(talktoEnvelope("incoming-done-duplicate-{$status}", $key));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toMatchArray([
                'received' => true,
                'duplicate' => true,
                'status' => 'already_processed',
                'message_id' => "incoming-done-{$status}",
            ])
            ->and(TalktoMessage::query()->where('idempotency_key', $key)->count())->toBe(1);
    }

    Queue::assertNotPushed(ProcessIncomingTalktoMessage::class);
});

test('idempotency key is scoped by source target and command', function (): void {
    Queue::fake();

    talktoIncomingMessage('incoming-scope-command', [
        'idempotency_key' => 'scope-command',
        'command' => 'domain.command',
    ]);

    talktoIncomingMessage('incoming-scope-source', [
        'idempotency_key' => 'scope-source',
        'source_service' => 'source-a',
    ]);

    talktoIncomingMessage('incoming-scope-target', [
        'idempotency_key' => 'scope-target',
        'target_service' => 'target-a',
    ]);

    $commandResponse = talktoReceive(talktoEnvelope('incoming-scope-command-new', 'scope-command', [
        'command' => 'domain.other-command',
    ]));

    $sourceResponse = talktoReceive(talktoEnvelope('incoming-scope-source-new', 'scope-source', [
        'source' => 'source-b',
    ]));

    config(['talkto.service' => 'target-b']);

    $targetResponse = talktoReceive(talktoEnvelope('incoming-scope-target-new', 'scope-target', [
        'target' => 'target-b',
    ]));

    expect($commandResponse->getStatusCode())->toBe(202)
        ->and($sourceResponse->getStatusCode())->toBe(202)
        ->and($targetResponse->getStatusCode())->toBe(202)
        ->and(TalktoMessage::query()->where('idempotency_key', 'scope-command')->count())->toBe(2)
        ->and(TalktoMessage::query()->where('idempotency_key', 'scope-source')->count())->toBe(2)
        ->and(TalktoMessage::query()->where('idempotency_key', 'scope-target')->count())->toBe(2)
        ->and(TalktoMessage::query()->where('idempotency_key', 'scope-command')->distinct()->count('idempotency_fingerprint'))->toBe(2)
        ->and(TalktoMessage::query()->where('idempotency_key', 'scope-source')->distinct()->count('idempotency_fingerprint'))->toBe(2);

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 3);
});

test('processing the same incoming job twice executes handler only once', function (): void {
    $message = talktoIncomingMessage('incoming-job-once');
    $handler = new IncomingLedgerCountingHandler;
    $resolver = new IncomingLedgerFixedResolver($handler);

    (new ProcessIncomingTalktoMessage($message->id))->handle($resolver);
    (new ProcessIncomingTalktoMessage($message->id))->handle($resolver);

    expect($handler->calls)->toBe(1)
        ->and(TalktoMessage::query()->where('message_id', 'incoming-job-once')->value('overall_status'))->toBe('succeeded')
        ->and(TalktoAttempt::query()->where('message_id', 'incoming-job-once')->count())->toBe(1);
});

test('terminal and non queued statuses are skipped without handler execution', function (): void {
    $handler = new IncomingLedgerCountingHandler;
    $resolver = new IncomingLedgerFixedResolver($handler);
    $statuses = ['processing', 'succeeded', 'completed', 'failed_final', 'cancelled', 'skipped'];

    foreach ($statuses as $status) {
        $message = talktoIncomingMessage("incoming-terminal-{$status}", [
            'destination_action_status' => $status,
            'overall_status' => $status,
        ]);

        (new ProcessIncomingTalktoMessage($message->id))->handle($resolver);
    }

    expect($handler->calls)->toBe(0)
        ->and(TalktoAttempt::query()->where('stage', 'destination_processor')->count())->toBe(0);
});

function talktoReceive(array $envelope): JsonResponse
{
    $request = Request::create('/api/talkto/receive', 'POST', $envelope);

    return app(TalktoReceiveController::class)->__invoke($request, app(TalktoSignatureVerifier::class));
}

function talktoEnvelope(string $messageId, ?string $idempotencyKey = null, array $overrides = []): array
{
    $payload = ['id' => $messageId];

    return array_filter(array_merge([
        'message_id' => $messageId,
        'source' => 'source',
        'target' => 'testing',
        'command' => 'domain.command',
        'idempotency_key' => $idempotencyKey,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'payload' => $payload,
    ], $overrides), fn (mixed $value): bool => $value !== null);
}

function talktoIncomingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'direction' => 'incoming',
        'source_service' => 'source',
        'target_service' => 'testing',
        'command' => 'domain.command',
        'payload' => ['id' => $messageId],
        'payload_hash' => 'hash',
        'schema_version' => 1,
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'queued',
        'received_at' => now(),
    ], $attributes));
}

class IncomingLedgerFixedResolver
{
    public function __construct(private readonly TalktoIncomingCommandHandler $handler) {}

    public function resolve(TalktoMessage $message): TalktoIncomingCommandHandler
    {
        return $this->handler;
    }
}

class IncomingLedgerCountingHandler implements TalktoIncomingCommandHandler
{
    public int $calls = 0;

    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        $this->calls++;

        return TalktoIncomingCommandResult::succeeded();
    }
}

class IncomingLedgerRaceTalktoMessage extends TalktoMessage
{
    public static bool $hideFirstLookup = false;

    public static ?int $hideLookupNumber = null;

    public static int $lookupCount = 0;

    public static function query()
    {
        self::$lookupCount++;

        if (self::$hideFirstLookup || self::$hideLookupNumber === self::$lookupCount) {
            self::$hideFirstLookup = false;
            self::$hideLookupNumber = null;

            return new IncomingLedgerNullQuery;
        }

        return parent::query();
    }
}

class IncomingLedgerNullQuery
{
    public function where(mixed ...$arguments): self
    {
        return $this;
    }

    public function whereIn(mixed ...$arguments): self
    {
        return $this;
    }

    public function first(): mixed
    {
        return null;
    }
}
