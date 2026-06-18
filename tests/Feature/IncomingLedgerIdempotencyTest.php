<?php

use Ibake\TalktoReliable\Contracts\TalktoIncomingCommandHandler;
use Ibake\TalktoReliable\Http\Controllers\TalktoReceiveController;
use Ibake\TalktoReliable\Jobs\ProcessIncomingTalktoMessage;
use Ibake\TalktoReliable\Models\TalktoAttempt;
use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Ibake\TalktoReliable\Services\TalktoIncomingCommandResult;
use Ibake\TalktoReliable\Services\TalktoPayloadHasher;
use Ibake\TalktoReliable\Services\TalktoSignatureVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.security.require_signature' => false,
        'talkto.security.require_timestamp' => false,
        'talkto.incoming.source' => [
            'allowed_commands' => [
                'domain.command' => [
                    'driver' => 'none',
                ],
            ],
        ],
        'talkto.models.message' => TalktoMessage::class,
    ]);

    IncomingLedgerRaceTalktoMessage::$hideFirstLookup = false;
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
        ->and(TalktoMessage::query()->where('idempotency_key', 'business-once')->count())->toBe(1);

    Queue::assertNotPushed(ProcessIncomingTalktoMessage::class);
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

function talktoEnvelope(string $messageId, ?string $idempotencyKey = null): array
{
    $payload = ['id' => $messageId];

    return array_filter([
        'message_id' => $messageId,
        'source' => 'source',
        'target' => 'testing',
        'command' => 'domain.command',
        'idempotency_key' => $idempotencyKey,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'payload' => $payload,
    ], fn (mixed $value): bool => $value !== null);
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

    public static function query()
    {
        if (self::$hideFirstLookup) {
            self::$hideFirstLookup = false;

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

    public function first(): mixed
    {
        return null;
    }
}
