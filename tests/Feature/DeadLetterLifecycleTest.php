<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.retry.enabled' => true,
        'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.incoming_enabled' => true,
        'talkto.retry.max_attempts' => 5,
        'talkto.retry.backoff_seconds' => [10, 30, 60],
        'talkto.dead_letter.enabled' => true,
        'talkto.dead_letter.auto_store_on_final_failure' => true,
        'talkto.dead_letter.allow_reprocess' => true,
        'talkto.outgoing.peer' => [
            'url' => 'https://peer.test',
            'secret' => 'secret',
            'endpoint' => '/api/talkto/receive',
        ],
    ]);
});

test('mark reprocessed for message only marks matching reprocessing rows and records event', function (): void {
    $message = p06DlqOutgoingMessage('p06-dlq-reprocessed');
    $otherMessage = p06DlqOutgoingMessage('p06-dlq-open-untouched');
    $open = p06DlqRow($otherMessage, TalktoDeadLetterQueue::STATUS_OPEN);
    $reprocessing = p06DlqRow($message, TalktoDeadLetterQueue::STATUS_REPROCESSING);

    $marked = app(TalktoDeadLetterQueue::class)->markReprocessedForMessage($message);

    expect($marked?->id)->toBe($reprocessing->id)
        ->and($marked->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSED)
        ->and($open->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_OPEN)
        ->and(TalktoEvent::query()->where('message_id', $message->message_id)->where('event_type', 'dead_letter_reprocessed')->exists())->toBeTrue();
});

test('successful outgoing reprocess marks matching dead letter reprocessed', function (): void {
    Http::fake(['*' => Http::response(['received' => true, 'status' => 'queued'], 200)]);
    $message = p06DlqOutgoingMessage('p06-outgoing-reprocess-success', [
        'overall_status' => 'waiting_to_send',
        'transport_status' => 'pending',
    ]);
    $deadLetter = p06DlqRow($message, TalktoDeadLetterQueue::STATUS_REPROCESSING);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect($deadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSED)
        ->and(TalktoEvent::query()->where('message_id', $message->message_id)->where('event_type', 'dead_letter_reprocessed')->exists())->toBeTrue();
});

test('successful and skipped incoming reprocess mark matching dead letters reprocessed', function (): void {
    $success = p06DlqIncomingMessage('p06-incoming-reprocess-success');
    $skipped = p06DlqIncomingMessage('p06-incoming-reprocess-skipped');
    $successDeadLetter = p06DlqRow($success, TalktoDeadLetterQueue::STATUS_REPROCESSING);
    $skippedDeadLetter = p06DlqRow($skipped, TalktoDeadLetterQueue::STATUS_REPROCESSING);

    (new \Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage($success->id))->handle(new P06ResultResolver('succeeded'));
    (new \Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage($skipped->id))->handle(new P06ResultResolver('skipped'));

    expect($successDeadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSED)
        ->and($skippedDeadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSED);
});

test('retryable and final incoming failures do not mark dead letter reprocessed', function (): void {
    $retryable = p06DlqIncomingMessage('p06-incoming-retryable-failure');
    $final = p06DlqIncomingMessage('p06-incoming-final-failure');
    $retryableDeadLetter = p06DlqRow($retryable, TalktoDeadLetterQueue::STATUS_REPROCESSING);
    $finalDeadLetter = p06DlqRow($final, TalktoDeadLetterQueue::STATUS_REPROCESSING);

    (new \Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage($retryable->id))->handle(new P06ResultResolver('failed_retryable'));
    (new \Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage($final->id))->handle(new P06ResultResolver('failed_final'));

    expect($retryableDeadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSING)
        ->and($finalDeadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_FAILED_REPROCESS);
});

test('dead letter reprocess command validates limit and failed dispatch does not stay reprocessing', function (): void {
    Queue::fake();
    $message = p06DlqOutgoingMessage('p06-dlq-dispatch-fails', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'Final failure.');

    expect(Artisan::call('talkto:dlq-reprocess', ['--limit' => 0]))->toBe(1)
        ->and(Artisan::output())->toContain('Invalid --limit');

    config(['talkto.jobs.send_message' => P06FailingSendJob::class]);

    expect(Artisan::call('talkto:dlq-reprocess', ['--limit' => 10]))->toBe(0)
        ->and(Artisan::output())->toContain('failed_dispatch=1');

    expect($deadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_FAILED_REPROCESS);
});

test('dead letter reprocess dry run remains read only', function (): void {
    Queue::fake();
    $message = p06DlqOutgoingMessage('p06-dlq-dry-run', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'Final failure.');

    expect(Artisan::call('talkto:dlq-reprocess', ['--dry-run' => true]))->toBe(0);

    Queue::assertNothingPushed();
    expect($deadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_OPEN)
        ->and($deadLetter->fresh()->reprocess_count)->toBe(0);
});

function p06DlqOutgoingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source_service' => 'testing',
        'target_service' => 'peer',
        'command' => 'domain.command',
        'payload' => ['id' => $messageId],
        'payload_hash' => 'hash',
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
    ], $attributes));
}

function p06DlqIncomingMessage(string $messageId, array $attributes = []): TalktoMessage
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
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
        'received_at' => now(),
    ], $attributes));
}

function p06DlqRow(TalktoMessage $message, string $status, array $attributes = []): TalktoDeadLetter
{
    return TalktoDeadLetter::query()->create(array_merge([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'direction' => $message->direction,
        'source' => $message->source_service,
        'target' => $message->target_service,
        'command' => $message->command,
        'payload' => $message->payload,
        'failure_reason' => 'Final failure.',
        'failed_status' => 'failed_final',
        'status' => $status,
        'reprocess_count' => $status === TalktoDeadLetterQueue::STATUS_REPROCESSING ? 1 : 0,
    ], $attributes));
}

class P06ResultResolver
{
    public function __construct(private readonly string $result) {}

    public function resolve(TalktoMessage $message): TalktoIncomingCommandHandler
    {
        return new P06ResultHandler($this->result);
    }
}

class P06ResultHandler implements TalktoIncomingCommandHandler
{
    public function __construct(private readonly string $result) {}

    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        return match ($this->result) {
            'succeeded' => TalktoIncomingCommandResult::succeeded(['processed' => true]),
            'skipped' => TalktoIncomingCommandResult::skipped('Skipped.'),
            'failed_retryable' => TalktoIncomingCommandResult::failedRetryable('Temporary failure.'),
            default => TalktoIncomingCommandResult::failedFinal('Final failure.'),
        };
    }
}

class P06FailingSendJob extends SendTalktoMessage
{
    public static function dispatch(...$arguments): mixed
    {
        throw new RuntimeException('Dispatch failed.');
    }
}
