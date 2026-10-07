<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
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
        'talkto.retry.retryable_http_statuses' => [408, 425, 429],
        'talkto.retry.retry_server_errors' => true,
        'talkto.outgoing.peer' => [
            'url' => 'https://peer.test',
            'secret' => 'secret',
            'endpoint' => '/api/talkto/receive',
        ],
    ]);
});

test('retry backoff uses configured delay sequence', function (): void {
    $policy = app(TalktoRetryPolicy::class);

    expect($policy->backoffSeconds(0))->toBe(10)
        ->and($policy->backoffSeconds(1))->toBe(30)
        ->and($policy->backoffSeconds(99))->toBe(300);
});

test('outgoing transport failure schedules retry while attempts remain', function (): void {
    $this->freezeTime();
    Http::fake(['*' => Http::response('temporary failure', 503)]);
    $message = retryOutgoingMessage('retry-outgoing-scheduled');

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();

    expect($message->retry_count)->toBe(1)
        ->and($message->next_retry_at)->not->toBeNull()
        ->and($message->overall_status)->toBe('failed_retryable')
        ->and($message->transport_status)->toBe('failed')
        ->and($message->attempts)->toBe(1)
        ->and((int) $message->last_attempted_at->diffInSeconds($message->next_retry_at))->toBe(10)
        ->and($message->next_attempt_at->equalTo($message->next_retry_at))->toBeTrue()
        ->and($message->locked_at)->toBeNull()
        ->and($message->locked_by)->toBeNull()
        ->and(TalktoDeadLetter::query()->where('message_id', $message->message_id)->exists())->toBeFalse();
    $attempt = TalktoAttempt::query()->where('message_id', $message->message_id)->sole();
    expect($attempt->stage)->toBe('transport')
        ->and($attempt->attempt_no)->toBe(1)
        ->and($attempt->status)->toBe('failed_retryable')
        ->and($attempt->http_status)->toBe(503)
        ->and($attempt->error_class)->toBe('http_error');
    expect(TalktoEvent::query()->where('message_id', $message->message_id)->where('event_type', 'message_send_failed')->where('new_status', 'failed_retryable')->exists())->toBeTrue();
});

test('configured transient http status schedules retry while attempts remain', function (): void {
    Http::fake(['*' => Http::response('too many requests', 429)]);
    $message = retryOutgoingMessage('retry-outgoing-429');

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_retryable')
        ->and($message->retry_count)->toBe(1)
        ->and($message->next_retry_at)->not->toBeNull();
});

test('outgoing transport failure becomes final when attempts are exhausted', function (): void {
    Http::fake(['*' => Http::response('temporary failure', 503)]);
    $message = retryOutgoingMessage('retry-outgoing-final', [
        'retry_count' => 4,
        'max_attempts' => 5,
    ]);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_final')
        ->and($message->transport_status)->toBe('failed_final')
        ->and($message->next_retry_at)->toBeNull()
        ->and(TalktoAttempt::query()->where('message_id', $message->message_id)->where('status', 'failed_final')->where('http_status', 503)->exists())->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', $message->message_id)->where('event_type', 'retry_exhausted')->exists())->toBeTrue()
        ->and(TalktoDeadLetter::query()->where('message_id', 'retry-outgoing-final')->exists())->toBeTrue();
});

test('permanent http statuses become final without scheduling retry by default', function (int $status): void {
    Http::fake(['*' => Http::response('permanent failure', $status)]);
    $message = retryOutgoingMessage("retry-outgoing-permanent-{$status}");

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_final')
        ->and($message->transport_status)->toBe('failed_final')
        ->and($message->retry_count)->toBe(0)
        ->and($message->next_retry_at)->toBeNull()
        ->and(TalktoDeadLetter::query()->where('message_id', $message->message_id)->where('status', 'open')->exists())->toBeTrue()
        ->and(TalktoAttempt::query()->where('message_id', $message->message_id)->where('status', 'failed_final')->where('http_status', $status)->exists())->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', $message->message_id)->where('event_type', 'retry_not_scheduled')->exists())->toBeTrue();
})->with([401, 422]);

test('disabling outgoing retry makes transient transport failures final without redispatch', function (string $setting): void {
    config([$setting => false]);
    Queue::fake();
    Http::fake(['*' => Http::response('temporary failure', 503)]);
    $message = retryOutgoingMessage('retry-disabled-transport');

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));
    $message->refresh();

    expect($message->overall_status)->toBe('failed_final')
        ->and($message->transport_status)->toBe('failed_final')
        ->and($message->retry_count)->toBe(0)
        ->and($message->next_retry_at)->toBeNull()
        ->and(TalktoDeadLetter::query()->where('message_id', $message->message_id)->exists())->toBeTrue();
    expect(Artisan::call('talkto:retry-failed'))->toBe(0);
    Queue::assertNothingPushed();
})->with(['global disabled' => 'talkto.retry.enabled', 'outgoing disabled' => 'talkto.retry.outgoing_enabled']);

test('retry command dispatches due outgoing retries and dry run does not dispatch', function (): void {
    Queue::fake();

    retryOutgoingMessage('retry-command-null', [
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'retry_count' => 1,
        'next_retry_at' => null,
    ]);
    retryOutgoingMessage('retry-command-future', [
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'retry_count' => 1,
        'next_retry_at' => now()->addMinute(),
    ]);
    retryOutgoingMessage('retry-command-due', [
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'retry_count' => 1,
        'next_retry_at' => now()->subMinute(),
    ]);
    retryOutgoingMessage('retry-command-terminal', [
        'overall_status' => 'succeeded',
        'transport_status' => 'sent',
        'retry_count' => 1,
        'next_retry_at' => now()->subMinute(),
    ]);

    expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing', '--limit' => 1]))->toBe(0)
        ->and(Artisan::output())->toContain('scanned=1 eligible=1 dispatched=1 skipped=0 dry_run=false direction=outgoing');

    Queue::assertPushed(SendTalktoMessage::class, 1);

    Queue::fake();
    retryOutgoingMessage('retry-command-dry-run-due', [
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'retry_count' => 1,
        'next_retry_at' => now()->subMinute(),
    ]);

    expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing', '--limit' => 1, '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('scanned=1 eligible=1 dispatched=0 skipped=0 dry_run=true direction=outgoing');

    Queue::assertNotPushed(SendTalktoMessage::class);
});

test('incoming retry is disabled by default', function (): void {
    $message = retryIncomingMessage('retry-incoming-disabled');

    (new ProcessIncomingTalktoMessage($message->id))->handle(new RetryThrowingResolver);

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_retryable')
        ->and($message->retry_count)->toBe(0)
        ->and($message->next_retry_at)->toBeNull();
});

test('incoming retry can be enabled explicitly', function (): void {
    config(['talkto.retry.incoming_enabled' => true]);
    $message = retryIncomingMessage('retry-incoming-enabled');

    (new ProcessIncomingTalktoMessage($message->id))->handle(new RetryThrowingResolver);

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_retryable')
        ->and($message->retry_count)->toBe(1)
        ->and($message->next_retry_at)->not->toBeNull();

    $completed = retryIncomingMessage('retry-incoming-completed', [
        'overall_status' => 'completed',
        'destination_action_status' => 'completed',
    ]);

    (new ProcessIncomingTalktoMessage($completed->id))->handle(new RetryThrowingResolver);

    expect($completed->fresh()->overall_status)->toBe('completed');
});

function retryOutgoingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source_service' => 'testing',
        'target_service' => 'peer',
        'command' => 'domain.command',
        'payload' => ['id' => $messageId],
        'payload_hash' => app(TalktoPayloadHasher::class)->hash(['id' => $messageId]),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
    ], $attributes));
}

function retryIncomingMessage(string $messageId, array $attributes = []): TalktoMessage
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

class RetryThrowingResolver
{
    public function resolve(TalktoMessage $message): TalktoIncomingCommandHandler
    {
        return new RetryThrowingIncomingHandler;
    }
}

class RetryThrowingIncomingHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        throw new RuntimeException('Temporary incoming failure.');
    }
}
