<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelActionExecutor;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoDispatchClaimingService;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Services\TalktoStaleMessageRecoveryService;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;

beforeEach(function (): void {
    TalktoDispatchTestHooks::reset();

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
        'talkto.dead_letter.max_reprocess_attempts' => 3,
        'talkto.callbacks.enabled' => true,
        'talkto.callbacks.auto_dispatch' => true,
        'talkto.outgoing.peer' => [
            'url' => 'https://peer.test',
            'secret' => 'shared-secret',
            'endpoint' => '/api/talkto/receive',
        ],
        'talkto.outgoing.source' => [
            'url' => 'https://source.test',
            'secret' => 'callback-secret',
            'callback_endpoint' => '/callbacks/talkto',
        ],
    ]);
});

afterEach(function (): void {
    TalktoDispatchTestHooks::reset();
});

test('retry command atomically claims due rows before dispatch and leaves wrong-service rows untouched', function (): void {
    Queue::fake();

    $owned = p2AtomicOutgoingMessage('p2-retry-owned', [
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'retry_count' => 1,
        'next_retry_at' => now()->subMinute(),
        'next_attempt_at' => now()->subMinute(),
    ]);
    $foreign = p2AtomicOutgoingMessage('p2-retry-foreign', [
        'source_service' => 'foreign-service',
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'retry_count' => 1,
        'next_retry_at' => now()->subMinute(),
        'next_attempt_at' => now()->subMinute(),
    ]);

    expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing', '--limit' => 10]))->toBe(0);
    expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing', '--limit' => 10]))->toBe(0);

    Queue::assertPushed(SendTalktoMessage::class, 1);

    expect($owned->fresh()->overall_status)->toBe('waiting_to_send')
        ->and($owned->fresh()->transport_status)->toBe('pending')
        ->and($owned->fresh()->locked_at)->not->toBeNull()
        ->and(str_starts_with((string) $owned->fresh()->locked_by, 'dispatch-claim:'))->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', 'p2-retry-owned')->where('event_type', 'retry_dispatched')->count())->toBe(1)
        ->and($foreign->fresh()->overall_status)->toBe('failed_retryable')
        ->and($foreign->fresh()->transport_status)->toBe('failed')
        ->and($foreign->fresh()->locked_by)->toBeNull();
});

test('retry dispatch failure restores only the claimed retry state', function (): void {
    config(['talkto.jobs.send_message' => P2AtomicFailingSendJob::class]);

    $message = p2AtomicOutgoingMessage('p2-retry-dispatch-fails', [
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'retry_count' => 1,
        'next_retry_at' => now()->subMinute(),
        'next_attempt_at' => now()->subMinute(),
    ]);

    expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing']))->toBe(0);

    $message->refresh();

    expect($message->overall_status)->toBe('failed_retryable')
        ->and($message->transport_status)->toBe('failed')
        ->and($message->next_retry_at)->not->toBeNull()
        ->and($message->locked_at)->toBeNull()
        ->and($message->locked_by)->toBeNull()
        ->and(TalktoEvent::query()->where('message_id', 'p2-retry-dispatch-fails')->where('event_type', 'retry_dispatch_failed')->exists())->toBeTrue();
});

test('dead letter reprocess claims the dead letter and original message together', function (): void {
    Queue::fake();

    $message = p2AtomicOutgoingMessage('p2-dlq-owned', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'Final failure.');

    expect(Artisan::call('talkto:dlq-reprocess', ['--message-id' => 'p2-dlq-owned']))->toBe(0);
    expect(Artisan::call('talkto:dlq-reprocess', ['--message-id' => 'p2-dlq-owned']))->toBe(0);

    Queue::assertPushed(SendTalktoMessage::class, 1);

    expect($deadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSING)
        ->and($deadLetter->fresh()->reprocess_count)->toBe(1)
        ->and($message->fresh()->overall_status)->toBe('waiting_to_send')
        ->and($message->fresh()->transport_status)->toBe('pending')
        ->and(str_starts_with((string) $message->fresh()->locked_by, 'dispatch-claim:'))->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', 'p2-dlq-owned')->where('event_type', 'dead_letter_reprocess_dispatched')->count())->toBe(1);
});

test('dead letter dispatch failure marks failed reprocess and restores the original message', function (): void {
    config(['talkto.jobs.send_message' => P2AtomicFailingSendJob::class]);

    $message = p2AtomicOutgoingMessage('p2-dlq-dispatch-fails', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'Final failure.');

    expect(Artisan::call('talkto:dlq-reprocess', ['--message-id' => 'p2-dlq-dispatch-fails']))->toBe(0);

    expect($deadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_FAILED_REPROCESS)
        ->and($deadLetter->fresh()->reprocess_count)->toBe(1)
        ->and($message->fresh()->overall_status)->toBe('failed_final')
        ->and($message->fresh()->transport_status)->toBe('failed_final')
        ->and($message->fresh()->locked_by)->toBeNull()
        ->and(TalktoEvent::query()->where('message_id', 'p2-dlq-dispatch-fails')->where('event_type', 'dead_letter_reprocess_dispatch_failed')->exists())->toBeTrue();
});

test('stale recovery claims before dispatch and second recovery pass does not redispatch', function (): void {
    Queue::fake();

    $message = p2AtomicStaleOutgoingMessage('p2-stale-once');

    expect(Artisan::call('talkto:recover-stale'))->toBe(0);
    expect(Artisan::call('talkto:recover-stale'))->toBe(0);

    Queue::assertPushed(SendTalktoMessage::class, 1);

    expect($message->fresh()->overall_status)->toBe('waiting_to_send')
        ->and($message->fresh()->transport_status)->toBe('pending')
        ->and($message->fresh()->locked_at)->not->toBeNull()
        ->and(str_starts_with((string) $message->fresh()->locked_by, 'dispatch-claim:'))->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', 'p2-stale-once')->where('event_type', 'stale_lock_recovered')->count())->toBe(1);
});

test('stale recovery dispatch failure restores the stale lock state', function (): void {
    config(['talkto.jobs.send_message' => P2AtomicFailingSendJob::class]);

    $message = p2AtomicStaleOutgoingMessage('p2-stale-dispatch-fails');

    expect(Artisan::call('talkto:recover-stale'))->toBe(0);

    $message->refresh();

    expect($message->overall_status)->toBe('sending')
        ->and($message->transport_status)->toBe('sending')
        ->and($message->locked_at)->not->toBeNull()
        ->and($message->locked_by)->toBe('sender:stale-worker')
        ->and(TalktoEvent::query()->where('message_id', 'p2-stale-dispatch-fails')->where('event_type', 'stale_lock_recovery_dispatch_failed')->exists())->toBeTrue();
});

test('orphaned retry dispatch claim is timestamped and recovered exactly once', function (): void {
    $message = p2AtomicOutgoingMessage('p2-orphaned-retry-claim', [
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'retry_count' => 1,
        'next_retry_at' => now()->subMinute(),
        'next_attempt_at' => now()->subMinute(),
    ]);

    $claim = app(TalktoDispatchClaimingService::class)->claimRetry($message, 'retry-command');

    expect($claim->claimed)->toBeTrue()
        ->and($message->fresh()->overall_status)->toBe('waiting_to_send')
        ->and($message->fresh()->locked_at)->not->toBeNull()
        ->and(str_starts_with((string) $message->fresh()->locked_by, 'dispatch-claim:retry-command:'))->toBeTrue();

    Queue::fake();

    expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing']))->toBe(0);
    Queue::assertNothingPushed();

    $message->fresh()->forceFill(['locked_at' => now()->subMinutes(30)])->save();

    expect(Artisan::call('talkto:recover-stale', ['--older-than' => 15]))->toBe(0);
    expect(Artisan::call('talkto:recover-stale', ['--older-than' => 15]))->toBe(0);

    Queue::assertPushed(SendTalktoMessage::class, 1);

    expect(TalktoEvent::query()
        ->where('message_id', 'p2-orphaned-retry-claim')
        ->where('event_type', 'orphaned_dispatch_claim_recovered')
        ->count())->toBe(1);
});

test('orphaned panel and callback dispatch claims are recoverable', function (): void {
    $panelMessage = p2AtomicOutgoingMessage('p2-orphaned-panel-claim', [
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'retry_count' => 1,
        'next_retry_at' => now()->addHour(),
        'next_attempt_at' => now()->addHour(),
    ]);
    $panelClaim = app(TalktoDispatchClaimingService::class)->claimRetry($panelMessage, 'panel-retry', true);
    $panelClaim->message?->forceFill(['locked_at' => now()->subMinutes(30)])->save();

    $incoming = p2AtomicIncomingMessage('p2-orphaned-callback-original', [
        'overall_status' => 'succeeded',
        'destination_action_status' => 'succeeded',
        'source_service' => 'source',
        'target_service' => 'testing',
    ]);
    $callback = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(['processed' => true])
    );
    $callback->forceFill([
        'locked_at' => now()->subMinutes(30),
        'locked_by' => app(TalktoDispatchClaimingService::class)->newClaimId('result-callback'),
    ])->save();

    Queue::fake();

    expect(Artisan::call('talkto:recover-stale', ['--older-than' => 15]))->toBe(0);

    Queue::assertPushed(SendTalktoMessage::class, 2);

    expect(TalktoEvent::query()
        ->where('message_id', 'p2-orphaned-panel-claim')
        ->where('event_type', 'orphaned_dispatch_claim_recovered')
        ->exists())->toBeTrue()
        ->and(TalktoEvent::query()
            ->where('message_id', $callback->message_id)
            ->where('event_type', 'orphaned_dispatch_claim_recovered')
            ->exists())->toBeTrue();
});

test('orphaned dead letter dispatch claim recovers without incrementing reprocess count again', function (): void {
    $message = p2AtomicOutgoingMessage('p2-orphaned-dlq-claim', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'Final failure.');
    $claim = app(TalktoDispatchClaimingService::class)->claimDeadLetterForReprocess($deadLetter, false, 'dlq-command');

    expect($claim->claimed)->toBeTrue()
        ->and($deadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSING)
        ->and($deadLetter->fresh()->reprocess_count)->toBe(1)
        ->and($message->fresh()->locked_at)->not->toBeNull();

    $message->fresh()->forceFill(['locked_at' => now()->subMinutes(30)])->save();

    Queue::fake();

    expect(Artisan::call('talkto:recover-stale', ['--older-than' => 15]))->toBe(0);

    Queue::assertPushed(SendTalktoMessage::class, 1);

    expect($deadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSING)
        ->and($deadLetter->fresh()->reprocess_count)->toBe(1)
        ->and(TalktoEvent::query()
            ->where('message_id', 'p2-orphaned-dlq-claim')
            ->where('event_type', 'orphaned_dispatch_claim_recovered')
            ->exists())->toBeTrue();
});

test('recent dispatch claim is not recovered and refreshed claim wins recovery race', function (): void {
    $message = p2AtomicOutgoingMessage('p2-recent-claim', [
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'retry_count' => 1,
        'next_retry_at' => now()->subMinute(),
        'next_attempt_at' => now()->subMinute(),
    ]);
    $claim = app(TalktoDispatchClaimingService::class)->claimRetry($message, 'retry-command');
    $claimId = (string) $claim->claimId;

    Queue::fake();

    expect(Artisan::call('talkto:recover-stale', ['--older-than' => 15]))->toBe(0);

    Queue::assertNothingPushed();

    expect($message->fresh()->locked_by)->toBe($claimId)
        ->and($message->fresh()->locked_at)->not->toBeNull();

    $candidate = $message->fresh();
    $candidate->forceFill(['locked_at' => now()->subMinutes(30)])->save();
    $laterClaimId = app(TalktoDispatchClaimingService::class)->newClaimId('retry-command');
    $message->fresh()->forceFill([
        'locked_at' => now(),
        'locked_by' => $laterClaimId,
    ])->save();

    $recovery = app(TalktoDispatchClaimingService::class)->claimStaleRecovery($candidate, 15, 'stale-recovery');

    expect($recovery->claimed)->toBeFalse()
        ->and($recovery->status)->toBe('not_stale')
        ->and($message->fresh()->locked_by)->toBe($laterClaimId);
});

test('forced dead letter reprocess cannot steal active reprocessing claim', function (): void {
    Queue::fake();

    $message = p2AtomicOutgoingMessage('p2-force-dlq-active', [
        'overall_status' => 'waiting_to_send',
        'transport_status' => 'pending',
        'locked_at' => now(),
        'locked_by' => app(TalktoDispatchClaimingService::class)->newClaimId('dlq-command'),
    ]);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'Final failure.');
    $deadLetter->forceFill([
        'status' => TalktoDeadLetterQueue::STATUS_REPROCESSING,
        'reprocess_count' => 1,
        'reprocessed_at' => now(),
    ])->save();
    $claimId = (string) $message->fresh()->locked_by;

    expect(Artisan::call('talkto:dlq-reprocess', [
        '--message-id' => 'p2-force-dlq-active',
        '--force' => true,
    ]))->toBe(0);

    Queue::assertNothingPushed();

    expect($deadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSING)
        ->and($deadLetter->fresh()->reprocess_count)->toBe(1)
        ->and($message->fresh()->locked_by)->toBe($claimId);
});

test('two forced dead letter actors produce one claim and one reprocess count increment', function (): void {
    $message = p2AtomicOutgoingMessage('p2-force-dlq-two-actors', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'Final failure.');
    $claimer = app(TalktoDispatchClaimingService::class);

    $first = $claimer->claimDeadLetterForReprocess($deadLetter, true, 'dlq-command');
    $second = $claimer->claimDeadLetterForReprocess($deadLetter->fresh(), true, 'dlq-command');

    expect($first->claimed)->toBeTrue()
        ->and($second->claimed)->toBeFalse()
        ->and($second->status)->toBe('dead_letter_not_reprocessable')
        ->and($deadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSING)
        ->and($deadLetter->fresh()->reprocess_count)->toBe(1)
        ->and($message->fresh()->locked_by)->toBe($first->claimId);
});

test('compensation skips when a later actor owns the message or dead letter state', function (): void {
    $message = p2AtomicOutgoingMessage('p2-compensation-ownership', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'Final failure.');
    $claimer = app(TalktoDispatchClaimingService::class);
    $claim = $claimer->claimDeadLetterForReprocess($deadLetter, false, 'dlq-command');
    $laterClaimId = $claimer->newClaimId('dlq-command');

    $message->fresh()->forceFill([
        'locked_at' => now(),
        'locked_by' => $laterClaimId,
    ])->save();

    expect($claimer->compensateDeadLetterClaim($claim, 'Dispatch failed.', new RuntimeException('failed')))->toBeFalse()
        ->and($message->fresh()->locked_by)->toBe($laterClaimId)
        ->and($deadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSING)
        ->and($deadLetter->fresh()->reprocess_count)->toBe(1);
});

test('result callback sender claim suppresses duplicate queue dispatch and releases on push failure', function (): void {
    Bus::fake();

    $incoming = p2AtomicIncomingMessage('p2-callback-once', [
        'overall_status' => 'succeeded',
        'destination_action_status' => 'succeeded',
        'source_service' => 'source',
        'target_service' => 'testing',
    ]);
    $result = TalktoIncomingCommandResult::succeeded(['processed' => true]);

    $first = app(ResultCallbackSenderContract::class)->sendResult($incoming, $result);
    $second = app(ResultCallbackSenderContract::class)->sendResult($incoming->fresh(), $result);
    $callback = TalktoMessage::query()->where('parent_message_id', 'p2-callback-once')->sole();

    expect($first['queued'])->toBeTrue()
        ->and($second['queued'])->toBeFalse()
        ->and($second['duplicate'])->toBeTrue()
        ->and($callback->locked_at)->not->toBeNull()
        ->and(str_starts_with((string) $callback->locked_by, 'dispatch-claim:'))->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', 'p2-callback-once')->where('event_type', 'result_callback_queued')->count())->toBe(1);

    Bus::assertDispatched(SendTalktoMessage::class, 1);

    config(['talkto.jobs.send_message' => P2AtomicFailingSendJob::class]);

    $failedIncoming = p2AtomicIncomingMessage('p2-callback-dispatch-fails', [
        'overall_status' => 'succeeded',
        'destination_action_status' => 'succeeded',
        'source_service' => 'source',
        'target_service' => 'testing',
    ]);

    $failed = app(ResultCallbackSenderContract::class)->sendResult($failedIncoming, $result);
    $failedCallback = TalktoMessage::query()->where('parent_message_id', 'p2-callback-dispatch-fails')->sole();

    expect($failed['status'])->toBe('failed')
        ->and($failedCallback->locked_by)->toBeNull()
        ->and(TalktoEvent::query()->where('message_id', 'p2-callback-dispatch-fails')->where('event_type', 'result_callback_queue_failed')->exists())->toBeTrue();
});

test('callback queue failure compensation keeps the claim when failure event insert rolls back', function (): void {
    config(['talkto.jobs.send_message' => P2AtomicFailingSendJob::class]);

    $incoming = p2AtomicIncomingMessage('p2-callback-event-insert-fails', [
        'overall_status' => 'succeeded',
        'destination_action_status' => 'succeeded',
        'source_service' => 'source',
        'target_service' => 'testing',
    ]);

    TalktoDispatchTestHooks::set('callback.compensation.before_event', function (): void {
        throw new RuntimeException('Simulated event insert failure.');
    });

    $result = app(ResultCallbackSenderContract::class)->sendResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(['processed' => true])
    );
    $callback = TalktoMessage::query()->where('parent_message_id', 'p2-callback-event-insert-fails')->sole();

    expect($result['status'])->toBe('failed')
        ->and($callback->fresh()->locked_at)->not->toBeNull()
        ->and(str_starts_with((string) $callback->fresh()->locked_by, 'dispatch-claim:result-callback:'))->toBeTrue()
        ->and(TalktoEvent::query()
            ->where('message_id', 'p2-callback-event-insert-fails')
            ->where('event_type', 'result_callback_queue_failed')
            ->exists())->toBeFalse();
});

test('callback queue failure compensation does not clear a newer callback claim', function (): void {
    config(['talkto.jobs.send_message' => P2AtomicFailingSendJob::class]);

    $incoming = p2AtomicIncomingMessage('p2-callback-newer-claim-wins', [
        'overall_status' => 'succeeded',
        'destination_action_status' => 'succeeded',
        'source_service' => 'source',
        'target_service' => 'testing',
    ]);
    $laterClaimId = app(TalktoDispatchClaimingService::class)->newClaimId('result-callback');

    p2AtomicOnDispatchOnce('result-callback', function (array $context) use ($laterClaimId): void {
        TalktoMessage::query()
            ->whereKey($context['message_db_id'])
            ->update([
                'locked_at' => now(),
                'locked_by' => $laterClaimId,
            ]);
    });

    $result = app(ResultCallbackSenderContract::class)->sendResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(['processed' => true])
    );
    $callback = TalktoMessage::query()->where('parent_message_id', 'p2-callback-newer-claim-wins')->sole();

    expect($result['status'])->toBe('failed')
        ->and($callback->fresh()->locked_by)->toBe($laterClaimId)
        ->and(TalktoEvent::query()
            ->where('message_id', 'p2-callback-newer-claim-wins')
            ->where('event_type', 'result_callback_queue_failed')
            ->exists())->toBeFalse();
});

test('retry command actors interleaved before dispatch still queue one job', function (): void {
    Queue::fake();

    $message = p2AtomicOutgoingMessage('p2-interleave-retry-command', [
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'retry_count' => 1,
        'next_retry_at' => now()->subMinute(),
        'next_attempt_at' => now()->subMinute(),
    ]);

    p2AtomicOnDispatchOnce('retry-command', function (): void {
        expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing']))->toBe(0);
    });

    expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing']))->toBe(0);

    Queue::assertPushed(SendTalktoMessage::class, 1);

    expect($message->fresh()->overall_status)->toBe('waiting_to_send')
        ->and(TalktoEvent::query()->where('message_id', 'p2-interleave-retry-command')->where('event_type', 'retry_dispatched')->count())->toBe(1);
});

test('panel retry interleaved with retry command still queues one job', function (): void {
    Queue::fake();

    $message = p2AtomicOutgoingMessage('p2-interleave-panel-command', [
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'retry_count' => 1,
        'next_retry_at' => now()->addHour(),
        'next_attempt_at' => now()->addHour(),
    ]);

    p2AtomicOnDispatchOnce('panel-retry', function (): void {
        expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing']))->toBe(0);
    });

    $result = app(TalktoPanelActionExecutor::class)->retryMessage($message);

    Queue::assertPushed(SendTalktoMessage::class, 1);

    expect($result->success)->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', 'p2-interleave-panel-command')->where('event_type', 'panel_retry_dispatched')->count())->toBe(1)
        ->and(TalktoEvent::query()->where('message_id', 'p2-interleave-panel-command')->where('event_type', 'retry_dispatched')->count())->toBe(0);
});

test('forced dead letter command actors interleaved before dispatch still keep one claim', function (): void {
    Queue::fake();

    $message = p2AtomicOutgoingMessage('p2-interleave-force-dlq', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'Final failure.');

    p2AtomicOnDispatchOnce('dlq-command', function (): void {
        expect(Artisan::call('talkto:dlq-reprocess', [
            '--message-id' => 'p2-interleave-force-dlq',
            '--force' => true,
        ]))->toBe(0);
    });

    expect(Artisan::call('talkto:dlq-reprocess', [
        '--message-id' => 'p2-interleave-force-dlq',
        '--force' => true,
    ]))->toBe(0);

    Queue::assertPushed(SendTalktoMessage::class, 1);

    expect($deadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSING)
        ->and($deadLetter->fresh()->reprocess_count)->toBe(1)
        ->and(TalktoEvent::query()->where('message_id', 'p2-interleave-force-dlq')->where('event_type', 'dead_letter_reprocess_dispatched')->count())->toBe(1);
});

test('stale recovery interleaving skips a candidate refreshed by an active worker', function (): void {
    Queue::fake();

    $message = p2AtomicStaleOutgoingMessage('p2-interleave-stale-active-worker');

    TalktoDispatchTestHooks::set('recovery.before_claim', function (array $context): void {
        TalktoDispatchTestHooks::reset('recovery.before_claim');

        TalktoMessage::query()
            ->whereKey($context['message_db_id'])
            ->update([
                'locked_at' => now(),
                'locked_by' => 'sender:active-worker',
            ]);
    });

    $summary = app(TalktoStaleMessageRecoveryService::class)->recover('outgoing', 15, 10, false);

    Queue::assertNothingPushed();

    expect($summary['recovered'])->toBe(0)
        ->and($summary['claim_changed'])->toBe(1)
        ->and($message->fresh()->locked_by)->toBe('sender:active-worker');
});

test('callback senders interleaved before dispatch still queue one callback job', function (): void {
    Bus::fake();

    $incoming = p2AtomicIncomingMessage('p2-interleave-callback-senders', [
        'overall_status' => 'succeeded',
        'destination_action_status' => 'succeeded',
        'source_service' => 'source',
        'target_service' => 'testing',
    ]);
    $result = TalktoIncomingCommandResult::succeeded(['processed' => true]);
    $second = null;

    p2AtomicOnDispatchOnce('result-callback', function () use ($incoming, $result, &$second): void {
        $second = app(ResultCallbackSenderContract::class)->sendResult($incoming->fresh(), $result);
    });

    $first = app(ResultCallbackSenderContract::class)->sendResult($incoming, $result);

    Bus::assertDispatched(SendTalktoMessage::class, 1);

    expect($first['queued'])->toBeTrue()
        ->and($second['queued'])->toBeFalse()
        ->and($second['duplicate'])->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', 'p2-interleave-callback-senders')->where('event_type', 'result_callback_queued')->count())->toBe(1);
});

test('dead letter dual-resource transactions lock messages before dead letters', function (): void {
    $events = [];
    p2AtomicTrackDlqLockOrder($events);

    $claimMessage = p2AtomicOutgoingMessage('p2-lock-order-claim', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    $claimDeadLetter = p2AtomicDeadLetterRow($claimMessage, TalktoDeadLetterQueue::STATUS_OPEN);

    $claim = app(TalktoDispatchClaimingService::class)->claimDeadLetterForReprocess($claimDeadLetter, false, 'dlq-command');

    expect($claim->claimed)->toBeTrue()
        ->and($events)->toBe(['message:claim_for_reprocess', 'dead_letter:claim_for_reprocess']);

    $events = [];

    expect(app(TalktoDispatchClaimingService::class)->compensateDeadLetterClaim($claim, 'Dispatch failed.', new RuntimeException('failed')))->toBeTrue()
        ->and($events)->toBe(['message:compensate_claim', 'dead_letter:compensate_claim']);

    $events = [];

    $successMessage = p2AtomicOutgoingMessage('p2-lock-order-success', [
        'overall_status' => 'waiting_to_send',
        'transport_status' => 'pending',
    ]);
    $successDeadLetter = p2AtomicDeadLetterRow($successMessage, TalktoDeadLetterQueue::STATUS_REPROCESSING, [
        'reprocess_count' => 1,
        'reprocessed_at' => now(),
    ]);

    app(TalktoDeadLetterQueue::class)->markReprocessedForMessage($successMessage);

    expect($successDeadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSED)
        ->and($events)->toBe(['message:mark_reprocessed', 'dead_letter:mark_reprocessed']);

    $events = [];

    $finalMessage = p2AtomicOutgoingMessage('p2-lock-order-final', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    $finalDeadLetter = p2AtomicDeadLetterRow($finalMessage, TalktoDeadLetterQueue::STATUS_REPROCESSING, [
        'reprocess_count' => 1,
        'reprocessed_at' => now(),
    ]);

    app(TalktoDeadLetterQueue::class)->store($finalMessage, 'Final failure.');

    expect($finalDeadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_FAILED_REPROCESS)
        ->and($events)->toBe(['message:store', 'dead_letter:store']);
});

test('orphaned dead letter claim recovery finalization locks message before dead letter', function (): void {
    Http::fake(['*' => Http::response(['received' => true, 'status' => 'queued'], 200)]);

    $message = p2AtomicOutgoingMessage('p2-lock-order-orphaned-dlq-recovery', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    $deadLetter = p2AtomicDeadLetterRow($message, TalktoDeadLetterQueue::STATUS_OPEN);
    $claim = app(TalktoDispatchClaimingService::class)->claimDeadLetterForReprocess($deadLetter, false, 'dlq-command');

    $claim->message?->forceFill(['locked_at' => now()->subMinutes(30)])->save();

    Queue::fake();

    expect(Artisan::call('talkto:recover-stale', ['--older-than' => 15]))->toBe(0);

    Queue::assertPushed(SendTalktoMessage::class, 1);

    $events = [];
    p2AtomicTrackDlqLockOrder($events);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect($deadLetter->fresh()->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSED)
        ->and($events)->toBe(['message:mark_reprocessed', 'dead_letter:mark_reprocessed']);
});

test('duplicate outgoing jobs no-op without creating skipped attempts after terminal advancement', function (): void {
    Http::fake(['*' => Http::response(['received' => true, 'status' => 'queued'], 200)]);

    $message = p2AtomicOutgoingMessage('p2-worker-duplicate', [
        'locked_at' => now(),
        'locked_by' => app(TalktoDispatchClaimingService::class)->newClaimId('retry-command'),
    ]);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));
    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect($message->fresh()->overall_status)->toBe('destination_received')
        ->and(TalktoAttempt::query()->where('message_id', 'p2-worker-duplicate')->count())->toBe(1)
        ->and(TalktoAttempt::query()->where('message_id', 'p2-worker-duplicate')->where('status', 'skipped')->exists())->toBeFalse();
});

test('retry claim is durable across two Laravel connections pointing at the same sqlite database', function (): void {
    Queue::fake();

    $database = tempnam(sys_get_temp_dir(), 'talkto-atomic-');

    try {
        $connection = [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];

        config([
            'database.default' => 'talkto_primary_file',
            'database.connections.talkto_primary_file' => $connection,
            'database.connections.talkto_shadow_file' => $connection,
            'talkto.database.connection' => 'talkto_shadow_file',
        ]);

        DB::purge('talkto_primary_file');
        DB::purge('talkto_shadow_file');

        foreach (glob(__DIR__.'/../../database/migrations/*.php') ?: [] as $migration) {
            (include $migration)->up();
        }

        $message = p2AtomicOutgoingMessage('p2-retry-two-connections', [
            'overall_status' => 'failed_retryable',
            'transport_status' => 'failed',
            'retry_count' => 1,
            'next_retry_at' => now()->subMinute(),
            'next_attempt_at' => now()->subMinute(),
        ]);

        expect($message->getConnection()->getName())->toBe('talkto_shadow_file');

        expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing']))->toBe(0);

        Queue::assertPushed(SendTalktoMessage::class, 1);

        $primaryRow = DB::connection('talkto_primary_file')
            ->table('talkto_messages')
            ->where('message_id', 'p2-retry-two-connections')
            ->first();
        $shadowRow = DB::connection('talkto_shadow_file')
            ->table('talkto_messages')
            ->where('message_id', 'p2-retry-two-connections')
            ->first();

        expect($primaryRow->overall_status)->toBe('waiting_to_send')
            ->and($shadowRow->overall_status)->toBe('waiting_to_send')
            ->and($primaryRow->locked_at)->not->toBeNull()
            ->and(str_starts_with((string) $shadowRow->locked_by, 'dispatch-claim:'))->toBeTrue();
    } finally {
        config([
            'database.default' => 'sqlite',
            'talkto.database.connection' => null,
        ]);

        DB::purge('talkto_primary_file');
        DB::purge('talkto_shadow_file');

        if (is_file($database)) {
            unlink($database);
        }
    }
});

test('orphaned claim recovery and forced dlq safety use the resolved talkto connection', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'talkto-atomic-corrected-');

    try {
        $connection = [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];

        config([
            'database.default' => 'talkto_primary_corrected',
            'database.connections.talkto_primary_corrected' => $connection,
            'database.connections.talkto_shadow_corrected' => $connection,
            'talkto.database.connection' => 'talkto_shadow_corrected',
        ]);

        DB::purge('talkto_primary_corrected');
        DB::purge('talkto_shadow_corrected');

        foreach (glob(__DIR__.'/../../database/migrations/*.php') ?: [] as $migration) {
            (include $migration)->up();
        }

        $message = p2AtomicOutgoingMessage('p2-separate-orphaned-claim', [
            'overall_status' => 'failed_retryable',
            'transport_status' => 'failed',
            'retry_count' => 1,
            'next_retry_at' => now()->subMinute(),
            'next_attempt_at' => now()->subMinute(),
        ]);
        $claim = app(TalktoDispatchClaimingService::class)->claimRetry($message, 'retry-command');
        $claim->message?->forceFill(['locked_at' => now()->subMinutes(30)])->save();

        Queue::fake();

        expect(Artisan::call('talkto:recover-stale', ['--older-than' => 15]))->toBe(0);

        Queue::assertPushed(SendTalktoMessage::class, 1);

        $primaryRecovered = DB::connection('talkto_primary_corrected')
            ->table('talkto_messages')
            ->where('message_id', 'p2-separate-orphaned-claim')
            ->first();

        expect($primaryRecovered->overall_status)->toBe('waiting_to_send')
            ->and(str_starts_with((string) $primaryRecovered->locked_by, 'dispatch-claim:'))->toBeTrue();

        $dlqMessage = p2AtomicOutgoingMessage('p2-separate-force-dlq', [
            'overall_status' => 'failed_final',
            'transport_status' => 'failed_final',
        ]);
        $deadLetter = app(TalktoDeadLetterQueue::class)->store($dlqMessage, 'Final failure.');
        $activeClaimId = app(TalktoDispatchClaimingService::class)->newClaimId('dlq-command');
        $dlqMessage->forceFill([
            'overall_status' => 'waiting_to_send',
            'transport_status' => 'pending',
            'locked_at' => now(),
            'locked_by' => $activeClaimId,
        ])->save();
        $deadLetter->forceFill([
            'status' => TalktoDeadLetterQueue::STATUS_REPROCESSING,
            'reprocess_count' => 1,
            'reprocessed_at' => now(),
        ])->save();

        Queue::fake();

        expect(Artisan::call('talkto:dlq-reprocess', [
            '--message-id' => 'p2-separate-force-dlq',
            '--force' => true,
        ]))->toBe(0);

        Queue::assertNothingPushed();

        $primaryDeadLetter = DB::connection('talkto_primary_corrected')
            ->table('talkto_dead_letters')
            ->where('message_id', 'p2-separate-force-dlq')
            ->first();
        $primaryMessage = DB::connection('talkto_primary_corrected')
            ->table('talkto_messages')
            ->where('message_id', 'p2-separate-force-dlq')
            ->first();

        expect($primaryDeadLetter->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSING)
            ->and((int) $primaryDeadLetter->reprocess_count)->toBe(1)
            ->and($primaryMessage->locked_by)->toBe($activeClaimId);
    } finally {
        config([
            'database.default' => 'sqlite',
            'talkto.database.connection' => null,
        ]);

        DB::purge('talkto_primary_corrected');
        DB::purge('talkto_shadow_corrected');

        if (is_file($database)) {
            unlink($database);
        }
    }
});

/**
 * @param  callable(array<string, mixed>): void  $callback
 */
function p2AtomicOnDispatchOnce(string $operation, callable $callback): void
{
    $ran = false;

    TalktoDispatchTestHooks::push('dispatch.before_queue', function (array $context) use (&$ran, $operation, $callback): void {
        if ($ran || ($context['operation'] ?? null) !== $operation) {
            return;
        }

        $ran = true;
        $callback($context);
    });
}

/**
 * @param  list<string>  $events
 */
function p2AtomicTrackDlqLockOrder(array &$events): void
{
    TalktoDispatchTestHooks::push('dlq.message_locked', function (array $context) use (&$events): void {
        $events[] = 'message:'.($context['operation'] ?? 'unknown');
    });

    TalktoDispatchTestHooks::push('dlq.dead_letter_locked', function (array $context) use (&$events): void {
        $events[] = 'dead_letter:'.($context['operation'] ?? 'unknown');
    });
}

function p2AtomicOutgoingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    $payload = $attributes['payload'] ?? ['id' => $messageId];

    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source_service' => 'testing',
        'target_service' => 'peer',
        'command' => 'domain.command',
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
    ], $attributes));
}

function p2AtomicIncomingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    $payload = $attributes['payload'] ?? ['id' => $messageId];

    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'correlation-'.$messageId,
        'direction' => 'incoming',
        'source_service' => 'source',
        'target_service' => 'testing',
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
    ], $attributes));
}

function p2AtomicDeadLetterRow(TalktoMessage $message, string $status, array $attributes = []): TalktoDeadLetter
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
        'failed_status' => $message->overall_status,
        'original_retry_count' => (int) ($message->retry_count ?? 0),
        'reprocess_count' => 0,
        'status' => $status,
    ], $attributes));
}

function p2AtomicStaleOutgoingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    return p2AtomicOutgoingMessage($messageId, array_merge([
        'transport_status' => 'sending',
        'overall_status' => 'sending',
        'attempts' => 1,
        'locked_at' => now()->subMinutes(30),
        'locked_by' => 'sender:stale-worker',
        'last_attempted_at' => now()->subMinutes(30),
    ], $attributes));
}

class P2AtomicFailingSendJob extends SendTalktoMessage
{
    public static function dispatch(...$arguments): mixed
    {
        throw new RuntimeException('Atomic dispatch failed.');
    }
}
