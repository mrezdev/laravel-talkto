<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.retry.enabled' => true,
        'talkto.retry.max_attempts' => 5,
        'talkto.retry.backoff_seconds' => [10, 30, 60, 120, 300],
        'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.incoming_enabled' => false,
        'talkto.retry.retryable_statuses' => ['failed_retryable'],
        'talkto.retry.final_failure_status' => 'failed_final',
        'talkto.retry.retryable_http_statuses' => [408, 425, 429],
        'talkto.retry.retry_server_errors' => true,
        'talkto.retry.jitter_seconds' => 0,
        'talkto.outgoing.peer' => [
            'url' => 'https://peer.test',
            'secret' => 'secret',
            'endpoint' => '/api/talkto/receive',
        ],
    ]);
});

test('retry policy preserves default behavior and legacy backoff call', function (): void {
    $policy = app(TalktoRetryPolicy::class);
    $message = p06RetryMessage('p06-default');

    expect($policy->backoffSeconds(0))->toBe(10)
        ->and($policy->backoffSeconds(99))->toBe(300)
        ->and($policy->maxAttempts($message))->toBe(5)
        ->and($policy->isDirectionEnabled($message))->toBeTrue()
        ->and($policy->isRetryableHttpStatus(503, $message))->toBeTrue()
        ->and($policy->isRetryableHttpStatus(422, $message))->toBeFalse();
});

test('direction peer and command overrides resolve with command taking precedence', function (): void {
    config([
        'talkto.retry.directions.outgoing' => [
            'enabled' => true,
            'max_attempts' => 4,
            'backoff_seconds' => [11, 22],
            'retryable_http_statuses' => [409],
            'retry_server_errors' => false,
        ],
        'talkto.retry.targets.peer-a' => [
            'max_attempts' => 3,
            'backoff_seconds' => [33],
            'retryable_http_statuses' => [418],
        ],
        'talkto.retry.commands.domain.command' => [
            'max_attempts' => 2,
            'backoff_seconds' => [44],
            'retryable_http_statuses' => [425],
        ],
    ]);

    $policy = app(TalktoRetryPolicy::class);
    $message = p06RetryMessage('p06-command-wins', [
        'target_service' => 'peer-a',
        'max_attempts' => 0,
    ]);

    expect($policy->settingsFor($message)['max_attempts'])->toBe(2)
        ->and($policy->backoffSeconds(0, $message))->toBe(44)
        ->and($policy->isRetryableHttpStatus(425, $message))->toBeTrue()
        ->and($policy->isRetryableHttpStatus(418, $message))->toBeFalse()
        ->and($policy->isRetryableHttpStatus(503, $message))->toBeFalse();
});

test('incoming peer override uses source service and message max attempts wins when positive', function (): void {
    config([
        'talkto.retry.directions.incoming' => [
            'enabled' => true,
            'max_attempts' => 4,
            'backoff_seconds' => [20],
        ],
        'talkto.retry.targets.source-peer' => [
            'max_attempts' => 3,
            'backoff_seconds' => [25],
        ],
    ]);

    $policy = app(TalktoRetryPolicy::class);
    $message = p06RetryMessage('p06-incoming-peer', [
        'direction' => 'incoming',
        'source_service' => 'source-peer',
        'target_service' => 'testing',
        'destination_action_status' => 'failed_retryable',
        'transport_status' => null,
        'max_attempts' => 7,
    ]);

    expect($policy->isDirectionEnabled($message))->toBeTrue()
        ->and($policy->settingsFor($message)['max_attempts'])->toBe(3)
        ->and($policy->maxAttempts($message))->toBe(7)
        ->and($policy->backoffSeconds(0, $message))->toBe(25);
});

test('invalid max attempts config falls back safely and server retry can be disabled', function (): void {
    config([
        'talkto.retry.max_attempts' => 0,
        'talkto.retry.retry_server_errors' => false,
    ]);

    $policy = app(TalktoRetryPolicy::class);
    $message = p06RetryMessage('p06-safe-fallback', ['max_attempts' => 0]);

    expect($policy->maxAttempts($message))->toBe(5)
        ->and($policy->isRetryableHttpStatus(503, $message))->toBeFalse()
        ->and($policy->isRetryableHttpStatus(429, $message))->toBeTrue();
});

test('retry decision returns stable reasons', function (): void {
    $policy = app(TalktoRetryPolicy::class);

    expect($policy->decisionFor(p06RetryMessage('p06-eligible', [
        'next_retry_at' => now()->subMinute(),
    ]))->reason)->toBe('eligible');

    config(['talkto.retry.enabled' => false]);
    expect($policy->decisionFor(p06RetryMessage('p06-disabled'))->reason)->toBe('retry_disabled');
    config(['talkto.retry.enabled' => true]);

    expect($policy->decisionFor(p06RetryMessage('p06-direction-disabled', [
        'direction' => 'incoming',
        'destination_action_status' => 'failed_retryable',
        'transport_status' => null,
    ]))->reason)->toBe('direction_disabled');

    expect($policy->decisionFor(p06RetryMessage('p06-non-retryable', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]))->reason)->toBe('non_retryable_status');

    expect($policy->decisionFor(p06RetryMessage('p06-exhausted', [
        'retry_count' => 5,
        'max_attempts' => 5,
    ]))->reason)->toBe('max_attempts_exhausted');

    expect($policy->decisionFor(p06RetryMessage('p06-not-due', [
        'next_retry_at' => now()->addMinute(),
    ]))->reason)->toBe('not_due');
});

test('mark retryable failure uses resolved backoff and exposes decision shape', function (): void {
    config(['talkto.retry.targets.peer-a.backoff_seconds' => [44]]);

    $policy = app(TalktoRetryPolicy::class);
    $message = p06RetryMessage('p06-mark-retryable', [
        'target_service' => 'peer-a',
        'retry_count' => 0,
    ]);

    $before = now()->addSeconds(43);
    $policy->markRetryableFailure($message, 'transport_status', 'Temporary failure.');
    $message = $message->fresh();
    $after = now()->addSeconds(45);

    $decision = $policy->decisionFor($message)->toArray();

    expect($message->retry_count)->toBe(1)
        ->and($message->next_retry_at->betweenIncluded($before, $after))->toBeTrue()
        ->and($decision)->toHaveKeys(['retryable', 'can_schedule', 'reason', 'max_attempts', 'backoff_seconds', 'policy']);
});

test('mark retryable failure clears locks and preserves null http status behavior', function (): void {
    config(['talkto.retry.targets.peer-a.backoff_seconds' => [44]]);

    $policy = app(TalktoRetryPolicy::class);
    $message = p06RetryMessage('p06-mark-retryable-clears-locks', [
        'target_service' => 'peer-a',
        'retry_count' => 2,
        'last_http_status' => 418,
        'locked_at' => now(),
        'locked_by' => 'worker-1',
    ]);
    $longError = str_repeat('x', 2100);

    $before = now()->addSeconds(43);
    $policy->markRetryableFailure($message, 'transport_status', $longError);
    $message = $message->fresh();
    $after = now()->addSeconds(45);

    expect($message->transport_status)->toBe('failed')
        ->and($message->overall_status)->toBe('failed_retryable')
        ->and($message->retry_count)->toBe(3)
        ->and($message->next_retry_at->betweenIncluded($before, $after))->toBeTrue()
        ->and($message->next_attempt_at->equalTo($message->next_retry_at))->toBeTrue()
        ->and($message->last_attempted_at)->not->toBeNull()
        ->and($message->last_http_status)->toBe(418)
        ->and($message->last_error)->toBe(str_repeat('x', 2000))
        ->and($message->failed_at)->not->toBeNull()
        ->and($message->locked_at)->toBeNull()
        ->and($message->locked_by)->toBeNull();
});

test('retry command validates limits stays read-only in dry run and respects policy direction', function (): void {
    Queue::fake();

    p06RetryMessage('p06-retry-outgoing', [
        'next_retry_at' => now()->subMinute(),
    ]);
    p06RetryMessage('p06-retry-incoming', [
        'direction' => 'incoming',
        'source_service' => 'source',
        'target_service' => 'testing',
        'destination_action_status' => 'failed_retryable',
        'transport_status' => null,
        'next_retry_at' => now()->subMinute(),
    ]);

    expect(Artisan::call('talkto:retry-failed', ['--limit' => 0]))->toBe(1)
        ->and(Artisan::output())->toContain('Invalid --limit');

    expect(Artisan::call('talkto:retry-failed', ['--limit' => 1001]))->toBe(1)
        ->and(Artisan::output())->toContain('Invalid --limit');

    expect(Artisan::call('talkto:retry-failed', ['--limit' => 10, '--dry-run' => true]))->toBe(0);
    Queue::assertNothingPushed();

    $dryRunOutput = Artisan::output();

    expect($dryRunOutput)->toContain('eligible=1')
        ->and($dryRunOutput)->toContain('direction_disabled');

    expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing', '--limit' => 10]))->toBe(0);
    Queue::assertPushed(SendTalktoMessage::class, 1);
    Queue::assertNotPushed(ProcessIncomingTalktoMessage::class);
});

test('command http status override is used by the outgoing sender runtime', function (): void {
    config([
        'talkto.retry.commands.domain.command.retryable_http_statuses' => [422],
    ]);

    Http::fake(['*' => Http::response('retryable by command policy', 422)]);

    $message = p06RetryMessage('p06-runtime-command-status', [
        'overall_status' => 'waiting_to_send',
        'transport_status' => 'pending',
        'attempts' => 0,
        'retry_count' => 0,
        'next_retry_at' => null,
    ]);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_retryable')
        ->and($message->transport_status)->toBe('failed')
        ->and($message->retry_count)->toBe(1)
        ->and($message->next_retry_at)->not->toBeNull();
});

test('target server error override is used by the outgoing sender runtime', function (): void {
    config([
        'talkto.retry.targets.peer.retry_server_errors' => false,
    ]);

    Http::fake(['*' => Http::response('server error disabled by target policy', 503)]);

    $message = p06RetryMessage('p06-runtime-target-server-error-disabled', [
        'overall_status' => 'waiting_to_send',
        'transport_status' => 'pending',
        'attempts' => 0,
        'retry_count' => 0,
        'next_retry_at' => null,
    ]);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_final')
        ->and($message->transport_status)->toBe('failed_final')
        ->and($message->retry_count)->toBe(0)
        ->and($message->next_retry_at)->toBeNull();
});

test('command server error override wins over target override in the outgoing sender runtime', function (): void {
    config([
        'talkto.retry.targets.peer.retry_server_errors' => false,
        'talkto.retry.commands.domain.command.retry_server_errors' => true,
    ]);

    Http::fake(['*' => Http::response('server error enabled by command policy', 503)]);

    $message = p06RetryMessage('p06-runtime-command-server-error-enabled', [
        'overall_status' => 'waiting_to_send',
        'transport_status' => 'pending',
        'attempts' => 0,
        'retry_count' => 0,
        'next_retry_at' => null,
    ]);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_retryable')
        ->and($message->transport_status)->toBe('failed')
        ->and($message->retry_count)->toBe(1)
        ->and($message->next_retry_at)->not->toBeNull();
});

test('outgoing retry scheduled event records the persisted delay and scheduled reason', function (): void {
    Http::fake(['*' => Http::response('temporary failure', 503)]);

    $message = p06RetryMessage('p06-outgoing-retry-event-meta', [
        'overall_status' => 'waiting_to_send',
        'transport_status' => 'pending',
        'attempts' => 0,
        'retry_count' => 0,
        'next_retry_at' => null,
    ]);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();
    $event = TalktoEvent::query()
        ->where('message_id', $message->message_id)
        ->where('event_type', 'retry_scheduled')
        ->firstOrFail();
    $scheduledDelay = p06ScheduledBackoffSeconds($message);

    expect($event->meta['backoff_seconds'])->toBe($scheduledDelay)
        ->and($event->meta['backoff_seconds'])->toBeLessThan(30)
        ->and($event->meta['reason'])->toBe('scheduled')
        ->and($event->meta['reason'])->not->toBe('not_due');
});

test('incoming retry scheduled event records the persisted delay and scheduled reason', function (): void {
    config(['talkto.retry.incoming_enabled' => true]);

    $message = p06RetryMessage('p06-incoming-retry-event-meta', [
        'direction' => 'incoming',
        'source_service' => 'source',
        'target_service' => 'testing',
        'transport_status' => null,
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'queued',
        'attempts' => 0,
        'retry_count' => 0,
        'next_retry_at' => null,
        'received_at' => now(),
    ]);

    (new ProcessIncomingTalktoMessage($message->id))->handle(new P06ARetryableResolver, app(TalktoRetryPolicy::class));

    $message = $message->fresh();
    $event = TalktoEvent::query()
        ->where('message_id', $message->message_id)
        ->where('event_type', 'retry_scheduled')
        ->firstOrFail();
    $scheduledDelay = p06ScheduledBackoffSeconds($message);

    expect($event->meta['backoff_seconds'])->toBe($scheduledDelay)
        ->and($event->meta['backoff_seconds'])->toBeLessThan(30)
        ->and($event->meta['reason'])->toBe('scheduled')
        ->and($event->meta['reason'])->not->toBe('not_due');
});

test('retry event metadata uses the persisted jittered delay instead of recomputing after mutation', function (): void {
    config(['talkto.retry.jitter_seconds' => 5]);

    Http::fake(['*' => Http::response('temporary failure', 503)]);

    $message = p06RetryMessage('p06-outgoing-retry-event-jitter', [
        'overall_status' => 'waiting_to_send',
        'transport_status' => 'pending',
        'attempts' => 0,
        'retry_count' => 0,
        'next_retry_at' => null,
    ]);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();
    $event = TalktoEvent::query()
        ->where('message_id', $message->message_id)
        ->where('event_type', 'retry_scheduled')
        ->firstOrFail();
    $scheduledDelay = p06ScheduledBackoffSeconds($message);

    expect($event->meta['backoff_seconds'])->toBe($scheduledDelay)
        ->and($scheduledDelay)->toBeGreaterThanOrEqual(10)
        ->and($scheduledDelay)->toBeLessThanOrEqual(15)
        ->and($event->meta['reason'])->toBe('scheduled');
});

function p06RetryMessage(string $messageId, array $attributes = []): TalktoMessage
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
        'transport_status' => 'failed',
        'destination_action_status' => null,
        'overall_status' => 'failed_retryable',
        'attempts' => 1,
        'retry_count' => 1,
        'max_attempts' => 5,
        'next_retry_at' => now()->subMinute(),
    ], $attributes));
}

function p06ScheduledBackoffSeconds(TalktoMessage $message): int
{
    return max(0, (int) $message->last_attempted_at->diffInSeconds($message->next_retry_at, false));
}

class P06ARetryableResolver
{
    public function resolve(TalktoMessage $message): TalktoIncomingCommandHandler
    {
        return new P06ARetryableHandler;
    }
}

class P06ARetryableHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        return TalktoIncomingCommandResult::failedRetryable('Temporary failure.');
    }
}
