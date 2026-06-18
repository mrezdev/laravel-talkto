<?php

use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.retry.enabled' => true,
        'talkto.retry.max_attempts' => 5,
        'talkto.retry.backoff_seconds' => [10, 30, 60, 120, 300],
        'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.incoming_enabled' => true,
        'talkto.dead_letter.enabled' => true,
        'talkto.dead_letter.auto_store_on_final_failure' => true,
        'talkto.dead_letter.allow_reprocess' => true,
        'talkto.dead_letter.max_reprocess_attempts' => 3,
        'talkto.outgoing.peer' => [
            'url' => 'https://peer.test',
            'secret' => 'secret',
            'endpoint' => '/api/talkto/receive',
        ],
    ]);
});

test('outgoing exhausted retry creates dead letter row and event', function (): void {
    Http::fake(['*' => Http::response('temporary failure', 503)]);
    $message = dlqOutgoingMessage('dlq-outgoing-exhausted', [
        'retry_count' => 4,
        'max_attempts' => 5,
    ]);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect(TalktoDeadLetter::query()->where('message_id', 'dlq-outgoing-exhausted')->count())->toBe(1)
        ->and(TalktoEvent::query()->where('message_id', 'dlq-outgoing-exhausted')->where('event_type', 'dead_lettered')->exists())->toBeTrue();
});

test('dead letter store is idempotent for the same message', function (): void {
    $message = dlqOutgoingMessage('dlq-idempotent', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
        'last_error' => 'Final failure.',
    ]);
    $queue = app(TalktoDeadLetterQueue::class);

    $queue->store($message, 'Final failure.');
    $queue->store($message, 'Final failure again.');

    expect(TalktoDeadLetter::query()->where('message_id', 'dlq-idempotent')->count())->toBe(1)
        ->and(TalktoEvent::query()->where('message_id', 'dlq-idempotent')->where('event_type', 'dead_lettered')->count())->toBe(1);
});

test('dead letter store marks reprocessing row as failed reprocess without duplicate event', function (): void {
    $message = dlqOutgoingMessage('dlq-reprocess-failed-again', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
        'last_error' => 'Original final failure.',
        'retry_count' => 4,
    ]);
    $queue = app(TalktoDeadLetterQueue::class);
    $deadLetter = $queue->store($message, 'Original final failure.');

    $deadLetter->forceFill([
        'status' => TalktoDeadLetterQueue::STATUS_REPROCESSING,
        'reprocess_count' => 1,
    ])->save();
    $message->forceFill([
        'last_error' => 'Reprocess final failure.',
        'retry_count' => 5,
    ])->save();

    $refreshed = $queue->store($message, 'Reprocess final failure.', new RuntimeException('Reprocess failed.'));

    expect($refreshed->id)->toBe($deadLetter->id)
        ->and($refreshed->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_FAILED_REPROCESS)
        ->and($refreshed->fresh()->reprocess_count)->toBe(1)
        ->and($refreshed->fresh()->failure_reason)->toBe('Reprocess final failure.')
        ->and($refreshed->fresh()->exception_class)->toBe(RuntimeException::class)
        ->and(TalktoEvent::query()->where('message_id', 'dlq-reprocess-failed-again')->where('event_type', 'dead_lettered')->count())->toBe(1);
});

test('retryable outgoing failure does not create dead letter row', function (): void {
    Http::fake(['*' => Http::response('temporary failure', 503)]);
    $message = dlqOutgoingMessage('dlq-retryable');

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect(TalktoDeadLetter::query()->where('message_id', 'dlq-retryable')->exists())->toBeFalse()
        ->and($message->fresh()->overall_status)->toBe('failed_retryable');
});

test('dead letter storage can be disabled', function (): void {
    config(['talkto.dead_letter.enabled' => false]);
    Http::fake(['*' => Http::response('temporary failure', 503)]);
    $message = dlqOutgoingMessage('dlq-disabled', [
        'retry_count' => 4,
        'max_attempts' => 5,
    ]);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect(TalktoDeadLetter::query()->where('message_id', 'dlq-disabled')->exists())->toBeFalse();
});

test('dead letter reprocess dry run does not dispatch or update rows', function (): void {
    Queue::fake();
    $message = dlqOutgoingMessage('dlq-dry-run', ['overall_status' => 'failed_final', 'transport_status' => 'failed_final']);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'Final failure.');
    $succeeded = dlqOutgoingMessage('dlq-dry-run-succeeded', ['overall_status' => 'succeeded', 'transport_status' => 'sent']);
    $succeededDeadLetter = app(TalktoDeadLetterQueue::class)->store($succeeded, 'Historical failure.');
    TalktoDeadLetter::query()->create([
        'talkto_message_id' => 999999,
        'message_id' => 'dlq-dry-run-missing-original',
        'direction' => 'outgoing',
        'status' => 'open',
    ]);

    expect(Artisan::call('talkto:dlq-reprocess', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('scanned=3 eligible=1 dispatched=0 skipped=2 missing_original=1');

    Queue::assertNotPushed(SendTalktoMessage::class);
    expect($deadLetter->fresh()->status)->toBe('open')
        ->and($deadLetter->fresh()->reprocess_count)->toBe(0)
        ->and($succeededDeadLetter->fresh()->status)->toBe('open')
        ->and($succeededDeadLetter->fresh()->reprocess_count)->toBe(0)
        ->and(TalktoEvent::query()->where('message_id', 'dlq-dry-run-missing-original')->where('event_type', 'dead_letter_reprocess_missing_original')->exists())->toBeFalse()
        ->and(TalktoEvent::query()->where('message_id', 'dlq-dry-run-succeeded')->where('event_type', 'dead_letter_reprocess_skipped')->exists())->toBeFalse();
});

test('dead letter reprocess dispatches outgoing incoming and skips missing originals', function (): void {
    Queue::fake();
    $outgoing = dlqOutgoingMessage('dlq-dispatch-outgoing', ['overall_status' => 'failed_final', 'transport_status' => 'failed_final']);
    $incoming = dlqIncomingMessage('dlq-dispatch-incoming', ['overall_status' => 'failed_final', 'destination_action_status' => 'failed_final']);

    app(TalktoDeadLetterQueue::class)->store($outgoing, 'Final outgoing failure.');
    app(TalktoDeadLetterQueue::class)->store($incoming, 'Final incoming failure.');
    TalktoDeadLetter::query()->create([
        'talkto_message_id' => 999999,
        'message_id' => 'dlq-missing-original',
        'direction' => 'outgoing',
        'status' => 'open',
    ]);

    expect(Artisan::call('talkto:dlq-reprocess'))->toBe(0)
        ->and(Artisan::output())->toContain('missing_original=1');

    Queue::assertPushed(SendTalktoMessage::class, 1);
    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 1);
    expect($outgoing->fresh()->overall_status)->toBe('waiting_to_send')
        ->and($incoming->fresh()->overall_status)->toBe('queued')
        ->and(TalktoEvent::query()->where('message_id', 'dlq-missing-original')->where('event_type', 'dead_letter_reprocess_missing_original')->exists())->toBeTrue();
});

test('dead letter reprocess claim prevents duplicate dispatch unless forced', function (): void {
    Queue::fake();
    $message = dlqOutgoingMessage('dlq-already-reprocessing', ['overall_status' => 'failed_final', 'transport_status' => 'failed_final']);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'Final failure.');
    $deadLetter->forceFill([
        'status' => TalktoDeadLetterQueue::STATUS_REPROCESSING,
        'reprocess_count' => 1,
    ])->save();

    expect(app(TalktoDeadLetterQueue::class)->claimForReprocess($deadLetter))->toBeNull();

    expect(Artisan::call('talkto:dlq-reprocess'))->toBe(0);
    Queue::assertNotPushed(SendTalktoMessage::class);

    expect(Artisan::call('talkto:dlq-reprocess', ['--force' => true]))->toBe(0);
    Queue::assertPushed(SendTalktoMessage::class, 1);
    expect($deadLetter->fresh()->reprocess_count)->toBe(2);
});

test('dead letter reprocess limit is respected and force bypasses it', function (): void {
    Queue::fake();
    config(['talkto.dead_letter.max_reprocess_attempts' => 1]);
    $message = dlqOutgoingMessage('dlq-force', ['overall_status' => 'failed_final', 'transport_status' => 'failed_final']);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'Final failure.');
    $deadLetter->forceFill(['reprocess_count' => 1])->save();

    expect(Artisan::call('talkto:dlq-reprocess'))->toBe(0);
    Queue::assertNotPushed(SendTalktoMessage::class);

    expect(Artisan::call('talkto:dlq-reprocess', ['--force' => true]))->toBe(0);
    Queue::assertPushed(SendTalktoMessage::class, 1);
    expect($deadLetter->fresh()->reprocess_count)->toBe(2);
});

test('dead letter reprocess does not dispatch succeeded original messages', function (): void {
    Queue::fake();
    $message = dlqOutgoingMessage('dlq-succeeded', ['overall_status' => 'succeeded', 'transport_status' => 'sent']);
    app(TalktoDeadLetterQueue::class)->store($message, 'Historical failure.');

    expect(Artisan::call('talkto:dlq-reprocess'))->toBe(0);

    Queue::assertNotPushed(SendTalktoMessage::class);
    expect(TalktoEvent::query()->where('message_id', 'dlq-succeeded')->where('event_type', 'dead_letter_reprocess_skipped')->exists())->toBeTrue()
        ->and(Schema::hasTable('talkto_processed_messages'))->toBeFalse();
});

test('dead letter reprocess skips unsupported original direction without dispatched count', function (): void {
    Queue::fake();
    $message = dlqOutgoingMessage('dlq-unsupported-direction', [
        'direction' => 'sideways',
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    app(TalktoDeadLetterQueue::class)->store($message, 'Unsupported direction failure.');

    expect(Artisan::call('talkto:dlq-reprocess'))->toBe(0)
        ->and(Artisan::output())->toContain('eligible=0 dispatched=0 skipped=1');

    Queue::assertNotPushed(SendTalktoMessage::class);
    Queue::assertNotPushed(ProcessIncomingTalktoMessage::class);
    expect(TalktoEvent::query()->where('message_id', 'dlq-unsupported-direction')->where('event_type', 'dead_letter_reprocess_skipped')->exists())->toBeTrue()
        ->and($message->fresh()->overall_status)->toBe('failed_final');
});

function dlqOutgoingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source_service' => 'testing',
        'target_service' => 'peer',
        'command' => 'domain.command',
        'payload' => ['id' => $messageId],
        'payload_hash' => app(\Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher::class)->hash(['id' => $messageId]),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
    ], $attributes));
}

function dlqIncomingMessage(string $messageId, array $attributes = []): TalktoMessage
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
