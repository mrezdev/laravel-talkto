<?php

use Ibake\TalktoReliable\Models\TalktoDeadLetter;
use Ibake\TalktoReliable\Models\TalktoEvent;
use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Models\TalktoAttempt;
use Ibake\TalktoReliable\Services\TalktoHealthChecker;
use Ibake\TalktoReliable\Services\TalktoMetricsCollector;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.observability.report.default_window_hours' => 24,
        'talkto.observability.report.default_limit' => 20,
        'talkto.observability.health.stale_processing_minutes' => 15,
        'talkto.observability.health.due_retry_grace_minutes' => 5,
    ]);
});

test('metrics collector returns safe zero data snapshot', function (): void {
    $snapshot = app(TalktoMetricsCollector::class)->collect(now()->subHour(), now());

    expect($snapshot->totalMessages)->toBe(0)
        ->and($snapshot->incomingMessages)->toBe(0)
        ->and($snapshot->outgoingMessages)->toBe(0)
        ->and($snapshot->successRate)->toBe(0.0)
        ->and($snapshot->failureRate)->toBe(0.0)
        ->and($snapshot->toArray()['status_counts'])->toBe([]);
});

test('metrics collector counts messages retries and dead letters', function (): void {
    observabilityMessage('obs-in-succeeded', 'incoming', 'succeeded');
    observabilityMessage('obs-out-completed', 'outgoing', 'completed');
    observabilityMessage('obs-out-retryable', 'outgoing', 'failed_retryable', [
        'retry_count' => 1,
        'next_retry_at' => now()->subMinute(),
    ]);
    observabilityMessage('obs-in-final', 'incoming', 'failed_final');
    observabilityMessage('obs-in-processing', 'incoming', 'processing');
    observabilityDeadLetter('obs-dlq-open', 'open');
    observabilityDeadLetter('obs-dlq-failed-reprocess', 'failed_reprocess');
    observabilityDeadLetter('obs-dlq-reprocessing', 'reprocessing');
    observabilityAttempt('obs-out-retryable', 'failed');
    observabilityEvent('obs-out-retryable', 'retry_scheduled');

    $collector = app(TalktoMetricsCollector::class);
    $snapshot = $collector->collect(now()->subHour(), now());

    expect($snapshot->totalMessages)->toBe(5)
        ->and($snapshot->incomingMessages)->toBe(3)
        ->and($snapshot->outgoingMessages)->toBe(2)
        ->and($snapshot->succeededMessages)->toBe(2)
        ->and($snapshot->failedMessages)->toBe(2)
        ->and($snapshot->retryableMessages)->toBe(1)
        ->and($snapshot->finalFailedMessages)->toBe(1)
        ->and($snapshot->processingMessages)->toBe(1)
        ->and($snapshot->dueRetryMessages)->toBe(1)
        ->and($snapshot->openDeadLetters)->toBe(2)
        ->and($snapshot->successRate)->toBe(40.0)
        ->and($snapshot->failureRate)->toBe(40.0)
        ->and($snapshot->deadLetterCounts)->toMatchArray([
            'open' => 1,
            'failed_reprocess' => 1,
            'reprocessing' => 1,
        ])
        ->and($collector->attemptStatusCounts(now()->subHour(), now()))->toBe(['failed' => 1])
        ->and($collector->eventTypeCounts(now()->subHour(), now()))->toBe(['retry_scheduled' => 1]);
});

test('health checker reports warnings and clean state', function (): void {
    expect(app(TalktoHealthChecker::class)->check()['ok'])->toBeTrue();

    observabilityMessage('obs-stale-processing', 'incoming', 'processing', [
        'processing_started_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(30),
    ]);
    observabilityMessage('obs-due-retry', 'outgoing', 'failed_retryable', [
        'next_retry_at' => now()->subMinutes(10),
    ]);
    observabilityMessage('obs-final-failure', 'outgoing', 'failed_final');
    observabilityDeadLetter('obs-health-dlq', 'open');
    observabilityEvent('obs-security', 'security_signature_failed');

    $health = app(TalktoHealthChecker::class)->check(now()->subHour(), now());

    expect($health['ok'])->toBeFalse()
        ->and($health['stale_processing_messages'])->toBe(1)
        ->and($health['due_retry_messages'])->toBe(1)
        ->and($health['open_dead_letters'])->toBe(1)
        ->and($health['recent_final_failures'])->toBe(1)
        ->and($health['security_failures'])->toBe(1)
        ->and($health['warnings'])->toContain('stale_processing_messages=1')
        ->and($health['warnings'])->toContain('due_retry_messages=1')
        ->and($health['warnings'])->toContain('open_dead_letters=1');
});

test('report command runs with empty database and outputs json', function (): void {
    expect(Artisan::call('talkto:report'))->toBe(0)
        ->and(Artisan::output())->toContain('Talkto report');

    expect(Artisan::call('talkto:report', ['--json' => true]))->toBe(0);

    $payload = json_decode(Artisan::output(), true);

    expect($payload)->toBeArray()
        ->and($payload['metrics']['total_messages'])->toBe(0)
        ->and($payload['health']['ok'])->toBeTrue();
});

test('report command filters by hours direction and limit', function (): void {
    observabilityMessage('obs-old-outgoing', 'outgoing', 'failed_final', [
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    observabilityMessage('obs-new-incoming', 'incoming', 'failed_final');
    observabilityMessage('obs-new-outgoing-a', 'outgoing', 'failed_final', ['last_error' => 'A']);
    observabilityMessage('obs-new-outgoing-b', 'outgoing', 'failed_retryable', ['last_error' => 'B']);
    observabilityEvent('obs-event-a', 'retry_scheduled');
    observabilityEvent('obs-event-b', 'dead_lettered');

    expect(Artisan::call('talkto:report', [
        '--json' => true,
        '--hours' => 1,
        '--direction' => 'outgoing',
        '--limit' => 1,
    ]))->toBe(0);

    $payload = json_decode(Artisan::output(), true);

    expect($payload['metrics']['total_messages'])->toBe(2)
        ->and($payload['metrics']['direction_counts'])->toBe(['outgoing' => 2])
        ->and($payload['event_type_counts'])->toMatchArray([
            'retry_scheduled' => 1,
            'dead_lettered' => 1,
        ])
        ->and($payload['recent_failures'])->toHaveCount(1)
        ->and($payload['recent_events'])->toHaveCount(1);
});

test('report command does not mutate observability tables', function (): void {
    observabilityMessage('obs-readonly-message', 'outgoing', 'failed_final');
    observabilityEvent('obs-readonly-message', 'dead_lettered');
    observabilityDeadLetter('obs-readonly-message', 'open');

    $before = [
        TalktoMessage::query()->count(),
        TalktoEvent::query()->count(),
        TalktoDeadLetter::query()->count(),
    ];

    expect(Artisan::call('talkto:report', ['--json' => true]))->toBe(0);

    $after = [
        TalktoMessage::query()->count(),
        TalktoEvent::query()->count(),
        TalktoDeadLetter::query()->count(),
    ];

    expect($after)->toBe($before);
});

function observabilityMessage(string $messageId, string $direction, string $status, array $attributes = []): TalktoMessage
{
    $message = TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'direction' => $direction,
        'source_service' => 'source',
        'target_service' => 'target',
        'command' => 'domain.command',
        'payload' => ['id' => $messageId],
        'payload_hash' => 'hash',
        'schema_version' => 1,
        'source_action_status' => $direction === 'outgoing' ? $status : null,
        'transport_status' => $direction === 'outgoing' ? $status : null,
        'destination_receive_status' => $direction === 'incoming' ? 'received' : null,
        'destination_action_status' => $direction === 'incoming' ? $status : null,
        'overall_status' => $status,
        'retry_count' => 0,
        'max_attempts' => 3,
        'next_retry_at' => $attributes['next_retry_at'] ?? null,
        'last_error' => $attributes['last_error'] ?? null,
        'processing_started_at' => $attributes['processing_started_at'] ?? null,
        'completed_at' => in_array($status, ['succeeded', 'completed'], true) ? now() : null,
        'failed_at' => str_starts_with($status, 'failed') ? now() : null,
    ], array_diff_key($attributes, array_flip(['created_at', 'updated_at']))));

    $message->forceFill([
        'created_at' => $attributes['created_at'] ?? now(),
        'updated_at' => $attributes['updated_at'] ?? now(),
    ])->save();

    return $message->fresh();
}

function observabilityDeadLetter(string $messageId, string $status): TalktoDeadLetter
{
    return TalktoDeadLetter::query()->create([
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source' => 'source',
        'target' => 'target',
        'command' => 'domain.command',
        'failed_status' => 'failed_final',
        'status' => $status,
    ]);
}

function observabilityAttempt(string $messageId, string $status): TalktoAttempt
{
    return TalktoAttempt::query()->create([
        'message_id' => $messageId,
        'stage' => 'transport',
        'attempt_no' => 1,
        'status' => $status,
    ]);
}

function observabilityEvent(string $messageId, string $eventType): TalktoEvent
{
    return TalktoEvent::query()->create([
        'message_id' => $messageId,
        'service_name' => 'testing',
        'event_type' => $eventType,
        'old_status' => null,
        'new_status' => null,
    ]);
}
