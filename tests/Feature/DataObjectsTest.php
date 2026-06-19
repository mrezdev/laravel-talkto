<?php

use Illuminate\Database\Eloquent\Model;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Data\TalktoEnvelopeData;
use Mrezdev\LaravelTalkto\Data\TalktoIncomingCommandResultData;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'target-service',
        'talkto.retry.incoming_enabled' => false,
        'talkto.outgoing.target-service' => [
            'url' => 'https://target.test',
            'secret' => 'secret',
            'endpoint' => '/api/talkto/receive',
        ],
    ]);
});

test('envelope data from array preserves expected envelope keys and values', function (): void {
    $envelope = p03EnvelopeArray();

    $data = TalktoEnvelopeData::fromArray($envelope);

    expect($data->toArray())->toBe($envelope)
        ->and($data->requiredSignatureFields())->toBe([
            'message_id' => 'message-1',
            'source' => 'source-service',
            'target' => 'target-service',
            'command' => 'domain.command',
            'payload_hash' => 'payload-hash',
        ]);
});

test('envelope data normalizes missing optional values safely', function (): void {
    $data = TalktoEnvelopeData::fromArray([
        'message_id' => 'message-2',
        'source' => 'source-service',
        'target' => 'target-service',
        'command' => 'domain.command',
        'protocol_version' => '',
        'schema_version' => '',
        'payload_hash' => 'payload-hash',
    ]);

    expect($data->toArray())->toBe([
        'protocol_version' => 2,
        'message_id' => 'message-2',
        'correlation_id' => null,
        'parent_message_id' => null,
        'source' => 'source-service',
        'target' => 'target-service',
        'command' => 'domain.command',
        'business_key' => null,
        'idempotency_key' => null,
        'schema_version' => 1,
        'created_at' => null,
        'payload_hash' => 'payload-hash',
        'payload' => null,
    ]);
});

test('envelope data defaults non positive version values and preserves positive versions', function (): void {
    foreach ([0, '0', false, -1] as $schemaVersion) {
        $data = TalktoEnvelopeData::fromArray(array_merge(p03MinimalEnvelopeArray(), [
            'schema_version' => $schemaVersion,
        ]));

        expect($data->toArray()['schema_version'])->toBe(1);
    }

    foreach ([0, '0', false, -1] as $protocolVersion) {
        $data = TalktoEnvelopeData::fromArray(array_merge(p03MinimalEnvelopeArray(), [
            'protocol_version' => $protocolVersion,
        ]));

        expect($data->toArray()['protocol_version'])->toBe(2);
    }

    $data = TalktoEnvelopeData::fromArray(array_merge(p03MinimalEnvelopeArray(), [
        'protocol_version' => 1,
        'schema_version' => 2,
    ]));

    expect($data->toArray()['protocol_version'])->toBe(1)
        ->and($data->toArray()['schema_version'])->toBe(2);
});

test('envelope data and builder preserve old schema version default for zero message values', function (): void {
    $message = p03EnvelopeMessage(['schema_version' => 0]);

    expect(TalktoEnvelopeData::fromMessage($message)->toArray()['schema_version'])->toBe(1)
        ->and(app(TalktoOutgoingEnvelopeBuilder::class)->buildEnvelope($message)['schema_version'])->toBe(1);
});

test('envelope data rejects clearly missing required fields', function (): void {
    expect(fn (): TalktoEnvelopeData => TalktoEnvelopeData::fromArray([
        'message_id' => 'message-3',
        'source' => 'source-service',
        'target' => 'target-service',
        'payload_hash' => 'payload-hash',
    ]))->toThrow(InvalidArgumentException::class);
});

test('envelope data from message matches outgoing envelope builder shape', function (): void {
    $message = p03EnvelopeMessage();
    $expected = [
        'protocol_version' => 2,
        'message_id' => 'message-1',
        'correlation_id' => 'correlation-1',
        'parent_message_id' => null,
        'source' => 'source-service',
        'target' => 'target-service',
        'command' => 'domain.command',
        'business_key' => 'business-key-123',
        'idempotency_key' => 'idempotency-key-123',
        'schema_version' => 1,
        'created_at' => $message->created_at->toIso8601String(),
        'payload_hash' => 'payload-hash',
        'payload' => ['resource_id' => 'resource-123'],
    ];

    expect(TalktoEnvelopeData::fromMessage($message)->toArray())->toBe($expected)
        ->and(app(TalktoOutgoingEnvelopeBuilder::class)->buildEnvelope($message))->toBe($expected);
});

test('incoming command result data from result matches concrete result array', function (): void {
    $result = TalktoIncomingCommandResult::succeeded(['processed' => true], ['attempt' => 1]);

    expect(TalktoIncomingCommandResultData::fromResult($result)->toArray())->toBe($result->toArray());
});

test('incoming command result data represents retryable final and skipped cases', function (): void {
    $retryable = TalktoIncomingCommandResult::failedRetryable('Temporary failure.', RuntimeException::class, ['code' => 'temporary']);
    $final = TalktoIncomingCommandResult::failedFinal('Final failure.', LogicException::class, ['code' => 'final']);
    $skipped = TalktoIncomingCommandResult::skipped('already processed');

    expect(TalktoIncomingCommandResultData::fromResult($retryable)->toArray())->toMatchArray([
        'succeeded' => false,
        'retryable' => true,
        'skipped' => false,
        'error_class' => RuntimeException::class,
        'error_message' => 'Temporary failure.',
        'meta' => ['code' => 'temporary'],
    ])->and(TalktoIncomingCommandResultData::fromResult($final)->toArray())->toMatchArray([
        'succeeded' => false,
        'retryable' => false,
        'skipped' => false,
        'error_class' => LogicException::class,
        'error_message' => 'Final failure.',
        'meta' => ['code' => 'final'],
    ])->and(TalktoIncomingCommandResultData::fromResult($skipped)->toArray())->toMatchArray([
        'succeeded' => true,
        'retryable' => false,
        'skipped' => true,
        'meta' => ['reason' => 'already processed'],
    ]);
});

test('incoming command result data can be restored from array', function (): void {
    $data = TalktoIncomingCommandResultData::fromArray([
        'succeeded' => true,
        'retryable' => false,
        'skipped' => false,
        'error_class' => null,
        'error_message' => null,
        'result' => ['processed' => true],
        'meta' => ['attempt' => 1],
    ]);

    expect($data->toArray())->toBe([
        'succeeded' => true,
        'retryable' => false,
        'error_class' => null,
        'error_message' => null,
        'result' => ['processed' => true],
        'meta' => ['attempt' => 1],
        'skipped' => false,
    ]);
});

test('incoming pipeline still processes success retryable final and skipped results', function (): void {
    $cases = [
        'success' => [TalktoIncomingCommandResult::succeeded(['processed' => true]), 'succeeded'],
        'retryable' => [TalktoIncomingCommandResult::failedRetryable('Temporary failure.', RuntimeException::class), 'failed_retryable'],
        'final' => [TalktoIncomingCommandResult::failedFinal('Final failure.', LogicException::class), 'failed_final'],
        'skipped' => [TalktoIncomingCommandResult::skipped('not needed'), 'skipped'],
    ];

    foreach ($cases as $name => [$result, $status]) {
        $message = p03IncomingMessage("p03-pipeline-{$name}");

        (new ProcessIncomingTalktoMessage($message->id))->handle(new P03FixedResultResolver($result));

        expect($message->fresh()->overall_status)->toBe($status)
            ->and($message->fresh()->destination_action_status)->toBe($status)
            ->and(TalktoAttempt::query()->where('message_id', $message->message_id)->latest('id')->value('status'))->toBe($status);
    }
});

function p03EnvelopeArray(): array
{
    return [
        'protocol_version' => 2,
        'message_id' => 'message-1',
        'correlation_id' => 'correlation-1',
        'parent_message_id' => null,
        'source' => 'source-service',
        'target' => 'target-service',
        'command' => 'domain.command',
        'business_key' => 'business-key-123',
        'idempotency_key' => 'idempotency-key-123',
        'schema_version' => 1,
        'created_at' => '2026-01-01T00:00:00+00:00',
        'payload_hash' => 'payload-hash',
        'payload' => ['resource_id' => 'resource-123'],
    ];
}

function p03MinimalEnvelopeArray(): array
{
    return [
        'message_id' => 'message-1',
        'source' => 'source-service',
        'target' => 'target-service',
        'command' => 'domain.command',
        'payload_hash' => 'payload-hash',
    ];
}

function p03EnvelopeMessage(array $attributes = []): Model
{
    $message = new class extends Model
    {
        public $timestamps = false;

        protected $guarded = [];
    };

    $message->forceFill(array_merge([
        'message_id' => 'message-1',
        'correlation_id' => 'correlation-1',
        'parent_message_id' => null,
        'source_service' => 'source-service',
        'target_service' => 'target-service',
        'command' => 'domain.command',
        'business_key' => 'business-key-123',
        'idempotency_key' => 'idempotency-key-123',
        'schema_version' => 1,
        'created_at' => now(),
        'payload_hash' => 'payload-hash',
        'payload' => ['resource_id' => 'resource-123'],
    ], $attributes));

    return $message;
}

function p03IncomingMessage(string $messageId): TalktoMessage
{
    $payload = ['resource_id' => $messageId];

    return TalktoMessage::query()->create([
        'message_id' => $messageId,
        'direction' => 'incoming',
        'source_service' => 'source-service',
        'target_service' => 'target-service',
        'command' => 'domain.command',
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1,
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'queued',
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
        'received_at' => now(),
    ]);
}

class P03FixedResultResolver
{
    public function __construct(private readonly TalktoIncomingCommandResult $result) {}

    public function resolve(TalktoMessage $message): TalktoIncomingCommandHandler
    {
        return new P03FixedResultHandler($this->result);
    }
}

class P03FixedResultHandler implements TalktoIncomingCommandHandler
{
    public function __construct(private readonly TalktoIncomingCommandResult $result) {}

    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        return $this->result;
    }
}
