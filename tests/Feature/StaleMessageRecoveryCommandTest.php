<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.recovery.stale_after_minutes' => 15,
        'talkto.retry.enabled' => true,
        'talkto.retry.max_attempts' => 5,
        'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.incoming_enabled' => true,
        'talkto.dead_letter.enabled' => true,
        'talkto.dead_letter.auto_store_on_final_failure' => true,
    ]);
});

test('dry run finds stale messages but does not mutate or dispatch', function (): void {
    Queue::fake();
    $outgoing = staleOutgoingMessage('stale-dry-out');
    $incoming = staleIncomingMessage('stale-dry-in');

    expect(Artisan::call('talkto:recover-stale', ['--dry-run' => true]))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Candidates: 2')
        ->and($output)->toContain('Dry run: no changes were made.');

    Queue::assertNothingPushed();

    expect($outgoing->fresh()->overall_status)->toBe('sending')
        ->and($outgoing->fresh()->locked_at)->not->toBeNull()
        ->and($incoming->fresh()->overall_status)->toBe('processing')
        ->and($incoming->fresh()->locked_at)->not->toBeNull()
        ->and(TalktoEvent::query()->count())->toBe(0);
});

test('stale outgoing message is recovered and dispatched', function (): void {
    Queue::fake();
    $message = staleOutgoingMessage('stale-outgoing-recover');

    expect(Artisan::call('talkto:recover-stale'))->toBe(0)
        ->and(Artisan::output())->toContain('Recovered: 1');

    Queue::assertPushed(SendTalktoMessage::class, 1);

    $message = $message->fresh();

    expect($message->overall_status)->toBe('waiting_to_send')
        ->and($message->transport_status)->toBe('pending')
        ->and($message->locked_at)->toBeNull()
        ->and($message->locked_by)->toBeNull()
        ->and($message->next_retry_at)->not->toBeNull()
        ->and(TalktoEvent::query()->where('message_id', 'stale-outgoing-recover')->where('event_type', 'stale_lock_recovered')->exists())->toBeTrue();
});

test('stale incoming message is recovered and dispatched', function (): void {
    Queue::fake();
    $message = staleIncomingMessage('stale-incoming-recover');

    expect(Artisan::call('talkto:recover-stale'))->toBe(0);

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 1);

    $message = $message->fresh();

    expect($message->overall_status)->toBe('queued')
        ->and($message->destination_action_status)->toBe('queued')
        ->and($message->locked_at)->toBeNull()
        ->and($message->next_retry_at)->not->toBeNull()
        ->and(TalktoEvent::query()->where('message_id', 'stale-incoming-recover')->where('event_type', 'stale_lock_recovered')->exists())->toBeTrue();
});

test('non stale in flight message is ignored', function (): void {
    Queue::fake();
    $message = staleOutgoingMessage('not-old-enough', [
        'locked_at' => now()->subMinutes(5),
    ]);

    expect(Artisan::call('talkto:recover-stale'))->toBe(0)
        ->and(Artisan::output())->toContain('Candidates: 0');

    Queue::assertNothingPushed();
    expect($message->fresh()->overall_status)->toBe('sending');
});

test('succeeded failed and dead lettered messages are ignored', function (): void {
    Queue::fake();
    staleOutgoingMessage('final-success', [
        'overall_status' => 'succeeded',
        'transport_status' => 'sent',
    ]);
    staleOutgoingMessage('final-failed', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
    ]);
    staleOutgoingMessage('final-dead-lettered', [
        'overall_status' => 'dead_lettered',
        'transport_status' => 'dead_lettered',
    ]);

    expect(Artisan::call('talkto:recover-stale'))->toBe(0)
        ->and(Artisan::output())->toContain('Candidates: 0');

    Queue::assertNothingPushed();
});

test('direction filter works for incoming', function (): void {
    Queue::fake();
    staleOutgoingMessage('direction-in-out');
    staleIncomingMessage('direction-in-in');

    expect(Artisan::call('talkto:recover-stale', ['--direction' => 'incoming']))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Candidates: 1')
        ->and($output)->toContain('Direction: incoming');

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 1);
    Queue::assertNotPushed(SendTalktoMessage::class);
});

test('direction filter works for outgoing', function (): void {
    Queue::fake();
    staleOutgoingMessage('direction-out-out');
    staleIncomingMessage('direction-out-in');

    expect(Artisan::call('talkto:recover-stale', ['--direction' => 'outgoing']))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Candidates: 1')
        ->and($output)->toContain('Direction: outgoing');

    Queue::assertPushed(SendTalktoMessage::class, 1);
    Queue::assertNotPushed(ProcessIncomingTalktoMessage::class);
});

test('limit option works', function (): void {
    Queue::fake();
    staleOutgoingMessage('limit-one');
    staleOutgoingMessage('limit-two');
    staleOutgoingMessage('limit-three');

    expect(Artisan::call('talkto:recover-stale', ['--limit' => 2]))->toBe(0)
        ->and(Artisan::output())->toContain('Candidates: 2');

    Queue::assertPushed(SendTalktoMessage::class, 2);
});

test('older than option overrides config', function (): void {
    Queue::fake();
    config(['talkto.recovery.stale_after_minutes' => 60]);
    $message = staleOutgoingMessage('older-than-override', [
        'locked_at' => now()->subMinutes(30),
    ]);

    expect(Artisan::call('talkto:recover-stale'))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Candidates: 0');

    expect(Artisan::call('talkto:recover-stale', ['--older-than' => 20]))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Candidates: 1');

    Queue::assertPushed(SendTalktoMessage::class, 1);
    expect($message->fresh()->overall_status)->toBe('waiting_to_send');
});

test('exhausted messages are marked final dead lettered and not dispatched', function (): void {
    Queue::fake();
    $message = staleOutgoingMessage('stale-exhausted', [
        'attempts' => 3,
        'max_attempts' => 3,
    ]);

    expect(Artisan::call('talkto:recover-stale'))->toBe(0)
        ->and(Artisan::output())->toContain('Failed/dead-lettered: 1');

    Queue::assertNothingPushed();

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_final')
        ->and($message->transport_status)->toBe('failed_final')
        ->and($message->locked_at)->toBeNull()
        ->and(TalktoDeadLetter::query()->where('message_id', 'stale-exhausted')->exists())->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', 'stale-exhausted')->where('event_type', 'stale_lock_recovery_exhausted')->exists())->toBeTrue();
});

test('command is registered and callable', function (): void {
    expect(array_key_exists('talkto:recover-stale', Artisan::all()))->toBeTrue()
        ->and(Artisan::call('talkto:recover-stale', ['--dry-run' => true]))->toBe(0);
});

function staleOutgoingMessage(string $messageId, array $attributes = []): TalktoMessage
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
        'transport_status' => 'sending',
        'overall_status' => 'sending',
        'attempts' => 1,
        'retry_count' => 0,
        'max_attempts' => 5,
        'locked_at' => now()->subMinutes(30),
        'locked_by' => 'sender:stale-worker',
        'last_attempted_at' => now()->subMinutes(30),
    ], $attributes));
}

function staleIncomingMessage(string $messageId, array $attributes = []): TalktoMessage
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
        'destination_action_status' => 'processing',
        'overall_status' => 'processing',
        'attempts' => 1,
        'retry_count' => 0,
        'max_attempts' => 5,
        'received_at' => now()->subHour(),
        'processing_started_at' => now()->subMinutes(30),
        'locked_at' => now()->subMinutes(30),
        'locked_by' => 'processor:stale-worker',
        'last_attempted_at' => now()->subMinutes(30),
    ], $attributes));
}
