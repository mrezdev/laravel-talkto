<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Enums\TalktoAttemptStatus;
use Mrezdev\LaravelTalkto\Enums\TalktoDeadLetterStatus;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoReceiveController;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoNonce;
use Mrezdev\LaravelTalkto\Pipelines\ProcessIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'target-service',
        'talkto.dead_letter.enabled' => true,
        'talkto.dead_letter.auto_store_on_final_failure' => true,
        'talkto.retry.enabled' => true,
        'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.incoming_enabled' => false,
        'talkto.retry.max_attempts' => 5,
        'talkto.retry.backoff_seconds' => [10, 30, 60, 120, 300],
    ]);
});

test('internal lifecycle enums preserve persisted string values', function (): void {
    expect(array_column(TalktoMessageDirection::cases(), 'value'))->toBe([
        'incoming',
        'outgoing',
    ])->and(array_column(TalktoMessageStatus::cases(), 'value'))->toBe([
        'created',
        'queued',
        'pending',
        'processing',
        'waiting_to_send',
        'sending',
        'sent',
        'received',
        'destination_received',
        'succeeded',
        'succeeded_assumed',
        'completed',
        'skipped',
        'failed',
        'failed_retryable',
        'failed_final',
        'dead_lettered',
        'cancelled',
        'unknown',
    ])->and(array_column(TalktoAttemptStatus::cases(), 'value'))->toBe([
        'started',
        'processing',
        'sending',
        'sent',
        'succeeded',
        'skipped',
        'failed',
        'failed_retryable',
        'failed_final',
    ])->and(array_column(TalktoDeadLetterStatus::cases(), 'value'))->toBe([
        'open',
        'reprocessing',
        'reprocessed',
        'failed_reprocess',
        'ignored',
    ]);
});

test('model resolver preserves defaults custom overrides and safe fallback', function (): void {
    $resolver = app(TalktoModelResolver::class);

    expect($resolver->message())->toBe(TalktoMessage::class)
        ->and($resolver->attempt())->toBe(TalktoAttempt::class)
        ->and($resolver->event())->toBe(TalktoEvent::class)
        ->and($resolver->deadLetter())->toBe(TalktoDeadLetter::class)
        ->and($resolver->nonce())->toBe(TalktoNonce::class);

    config([
        'talkto.models.message' => Phase5CustomTalktoMessage::class,
        'talkto.models.attempt' => Phase5CustomTalktoAttempt::class,
        'talkto.models.event' => Phase5CustomTalktoEvent::class,
        'talkto.models.dead_letter' => Phase5CustomTalktoDeadLetter::class,
        'talkto.models.nonce' => Phase5CustomTalktoNonce::class,
    ]);

    expect($resolver->message())->toBe(Phase5CustomTalktoMessage::class)
        ->and($resolver->attempt())->toBe(Phase5CustomTalktoAttempt::class)
        ->and($resolver->event())->toBe(Phase5CustomTalktoEvent::class)
        ->and($resolver->deadLetter())->toBe(Phase5CustomTalktoDeadLetter::class)
        ->and($resolver->nonce())->toBe(Phase5CustomTalktoNonce::class);

    config([
        'talkto.models.message' => stdClass::class,
        'talkto.models.attempt' => 'missing-attempt-class',
        'talkto.models.event' => null,
        'talkto.models.dead_letter' => TalktoMessage::class,
        'talkto.models.nonce' => [],
    ]);

    expect($resolver->message())->toBe(TalktoMessage::class)
        ->and($resolver->attempt())->toBe(TalktoAttempt::class)
        ->and($resolver->event())->toBe(TalktoEvent::class)
        ->and($resolver->deadLetter())->toBe(TalktoDeadLetter::class)
        ->and($resolver->nonce())->toBe(TalktoNonce::class);
});

test('internal enum and resolver helpers stay outside documented public api', function (): void {
    foreach ([
        TalktoMessageDirection::class,
        TalktoMessageStatus::class,
        TalktoAttemptStatus::class,
        TalktoDeadLetterStatus::class,
        TalktoModelResolver::class,
    ] as $class) {
        expect((new ReflectionClass($class))->getDocComment() ?: '')->toContain('@internal');
    }

    $publicApiDocs = file_get_contents(__DIR__.'/../../docs/PUBLIC_API.md');

    expect($publicApiDocs)->not->toContain('TalktoMessageDirection')
        ->and($publicApiDocs)->not->toContain('TalktoMessageStatus')
        ->and($publicApiDocs)->not->toContain('TalktoAttemptStatus')
        ->and($publicApiDocs)->not->toContain('TalktoDeadLetterStatus')
        ->and($publicApiDocs)->not->toContain('TalktoModelResolver');
});

test('message helpers and outgoing factory preserve stored string behavior', function (): void {
    $message = new TalktoMessage([
        'direction' => 'incoming',
        'overall_status' => 'completed',
        'attempts' => 1,
        'max_attempts' => 3,
    ]);

    expect($message->isIncoming())->toBeTrue()
        ->and($message->isOutgoing())->toBeFalse()
        ->and($message->isCompleted())->toBeTrue()
        ->and($message->isRetryable())->toBeFalse();

    config([
        'talkto.service' => 'source-service',
        'talkto.outgoing.target-service' => [
            'url' => 'https://target.test',
            'secret' => 'fake-test-secret',
        ],
    ]);

    $outgoing = app(TalktoOutgoingMessageFactory::class)->create(
        target: 'target-service',
        command: 'domain.command',
        payload: ['id' => 'phase5-outgoing'],
        options: [
            'message_id' => 'phase5-outgoing',
            'correlation_id' => 'phase5-correlation',
        ]
    );

    expect($outgoing->direction)->toBe('outgoing')
        ->and($outgoing->source_action_status)->toBe('succeeded_assumed')
        ->and($outgoing->transport_status)->toBe('pending')
        ->and($outgoing->overall_status)->toBe('waiting_to_send');
});

test('receive and process flows persist the same lifecycle strings', function (): void {
    Queue::fake();
    config([
        'talkto.service' => 'target-service',
        'talkto.security.require_signature' => true,
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
        'talkto.incoming.source-service' => [
            'secret' => 'fake-test-secret',
            'allowed_commands' => [
                'domain.command' => [
                    'driver' => 'none',
                ],
            ],
        ],
    ]);

    $response = phase5Receive(
        phase5Envelope('phase5-receive', ['id' => 'phase5-receive']),
        phase5V2Headers('phase5-receive', ['id' => 'phase5-receive'])
    );
    $received = TalktoMessage::query()->where('message_id', 'phase5-receive')->firstOrFail();

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getData(true)['status'])->toBe('queued')
        ->and($received->direction)->toBe('incoming')
        ->and($received->destination_receive_status)->toBe('received')
        ->and($received->destination_action_status)->toBe('queued')
        ->and($received->overall_status)->toBe('queued');

    $pipeline = app(ProcessIncomingTalktoMessagePipeline::class);

    $succeeded = phase5IncomingMessage('phase5-process-succeeded');
    $pipeline->process($succeeded->id, new Phase5StaticResolver(TalktoIncomingCommandResult::succeeded(['ok' => true])));

    $retryable = phase5IncomingMessage('phase5-process-retryable');
    $pipeline->process($retryable->id, new Phase5StaticResolver(TalktoIncomingCommandResult::failedRetryable('Temporary failure.')));

    $final = phase5IncomingMessage('phase5-process-final');
    $pipeline->process($final->id, new Phase5StaticResolver(TalktoIncomingCommandResult::failedFinal('Final failure.')));

    expect($succeeded->fresh()->overall_status)->toBe('succeeded')
        ->and($succeeded->fresh()->destination_action_status)->toBe('succeeded')
        ->and($retryable->fresh()->overall_status)->toBe('failed_retryable')
        ->and($retryable->fresh()->destination_action_status)->toBe('failed_retryable')
        ->and($final->fresh()->overall_status)->toBe('failed_final')
        ->and($final->fresh()->destination_action_status)->toBe('failed_final')
        ->and(TalktoAttempt::query()->where('message_id', 'phase5-process-succeeded')->latest('id')->first()?->status)->toBe('succeeded')
        ->and(TalktoAttempt::query()->where('message_id', 'phase5-process-retryable')->latest('id')->first()?->status)->toBe('failed_retryable')
        ->and(TalktoAttempt::query()->where('message_id', 'phase5-process-final')->latest('id')->first()?->status)->toBe('failed_final')
        ->and(TalktoDeadLetter::query()->where('message_id', 'phase5-process-final')->first()?->status)->toBe('open');
});

test('retry and dead letter services preserve configured status strings', function (): void {
    $message = TalktoMessage::query()->create([
        'message_id' => 'phase5-retry-dlq',
        'direction' => 'outgoing',
        'source_service' => 'source-service',
        'target_service' => 'target-service',
        'command' => 'domain.command',
        'payload' => ['id' => 'phase5-retry-dlq'],
        'payload_hash' => app(TalktoPayloadHasher::class)->hash(['id' => 'phase5-retry-dlq']),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'failed_retryable',
        'overall_status' => 'failed_retryable',
        'attempts' => 1,
        'retry_count' => 1,
        'max_attempts' => 5,
    ]);

    $retryPolicy = app(TalktoRetryPolicy::class);
    $retryPolicy->markRetryableFailure($message, 'transport_status', 'Temporary failure.', 503);

    expect($message->fresh()->transport_status)->toBe('failed')
        ->and($message->fresh()->overall_status)->toBe('failed_retryable');

    $retryPolicy->markFinalFailure($message->fresh(), 'transport_status', 'Final failure.', 422);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message->fresh(), 'Final failure.');

    expect($message->fresh()->transport_status)->toBe('failed_final')
        ->and($message->fresh()->overall_status)->toBe('failed_final')
        ->and($deadLetter->status)->toBe('open');
});

function phase5Envelope(string $messageId, array $payload): array
{
    return [
        'message_id' => $messageId,
        'source' => 'source-service',
        'target' => 'target-service',
        'command' => 'domain.command',
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'payload' => $payload,
    ];
}

function phase5V2Headers(string $messageId, array $payload): array
{
    $timestamp = now()->toIso8601String();
    $nonce = 'nonce-'.$messageId;
    $payloadHash = app(TalktoPayloadHasher::class)->hash($payload);

    return [
        'X-Talkto-Signature-Version' => 'v2',
        'X-Talkto-Signature' => app(TalktoSigner::class)->signV2(
            $timestamp,
            $nonce,
            $messageId,
            'source-service',
            'target-service',
            'domain.command',
            $payloadHash,
            'fake-test-secret'
        ),
        'X-Talkto-Timestamp' => $timestamp,
        'X-Talkto-Message-Id' => $messageId,
        'X-Talkto-Payload-Hash' => $payloadHash,
        'X-Talkto-Nonce' => $nonce,
    ];
}

function phase5Receive(array $envelope, array $headers): JsonResponse
{
    $request = Request::create('/api/talkto/receive', 'POST', $envelope);

    foreach ($headers as $key => $value) {
        $request->headers->set($key, $value);
    }

    return app(TalktoReceiveController::class)->__invoke($request, app(TalktoSignatureVerifier::class));
}

function phase5IncomingMessage(string $messageId): TalktoMessage
{
    return TalktoMessage::query()->create([
        'message_id' => $messageId,
        'direction' => 'incoming',
        'source_service' => 'source-service',
        'target_service' => 'target-service',
        'command' => 'domain.command',
        'payload' => ['id' => $messageId],
        'payload_hash' => app(TalktoPayloadHasher::class)->hash(['id' => $messageId]),
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

class Phase5StaticResolver
{
    public function __construct(private readonly TalktoIncomingCommandResult $result) {}

    public function resolve(TalktoMessage $message): TalktoIncomingCommandHandler
    {
        return new Phase5StaticHandler($this->result);
    }
}

class Phase5StaticHandler implements TalktoIncomingCommandHandler
{
    public function __construct(private readonly IncomingCommandResultContract $result) {}

    public function handle(TalktoMessage $message): IncomingCommandResultContract
    {
        return $this->result;
    }
}

class Phase5CustomTalktoMessage extends TalktoMessage {}

class Phase5CustomTalktoAttempt extends TalktoAttempt {}

class Phase5CustomTalktoEvent extends TalktoEvent {}

class Phase5CustomTalktoDeadLetter extends TalktoDeadLetter {}

class Phase5CustomTalktoNonce extends TalktoNonce {}
