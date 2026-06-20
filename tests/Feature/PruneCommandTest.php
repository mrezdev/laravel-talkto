<?php

use Illuminate\Support\Facades\Artisan;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoNonce;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.retention.messages_days' => 90,
        'talkto.retention.attempts_days' => 90,
        'talkto.retention.events_days' => 30,
        'talkto.retention.dead_letters_days' => 180,
        'talkto.retention.nonces_days' => 7,
    ]);
});

test('command is registered and callable', function (): void {
    expect(array_key_exists('talkto:prune', Artisan::all()))->toBeTrue()
        ->and(Artisan::call('talkto:prune', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Talkto pruning');
});

test('dry run reports candidates and deletes nothing', function (): void {
    oldEvent('prune-dry-event');
    oldAttempt('prune-dry-attempt');
    oldDeadLetter('prune-dry-dead-letter');
    terminalMessage('prune-dry-message', now()->subDays(120));

    expect(Artisan::call('talkto:prune', ['--dry-run' => true]))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Events: 1 candidates')
        ->and($output)->toContain('Attempts: 1 candidates')
        ->and($output)->toContain('Dead letters: 1 candidates')
        ->and($output)->toContain('Messages: 1 candidates')
        ->and($output)->toContain('No changes were made.')
        ->and(TalktoEvent::query()->count())->toBe(1)
        ->and(TalktoAttempt::query()->count())->toBe(1)
        ->and(TalktoDeadLetter::query()->count())->toBe(1)
        ->and(TalktoMessage::query()->count())->toBe(1);
});

test('old events are pruned and recent events are retained', function (): void {
    oldEvent('prune-old-event');
    recentEvent('prune-recent-event');

    expect(Artisan::call('talkto:prune', ['--type' => 'events']))->toBe(0)
        ->and(Artisan::output())->toContain('Events deleted: 1')
        ->and(TalktoEvent::query()->where('message_id', 'prune-old-event')->exists())->toBeFalse()
        ->and(TalktoEvent::query()->where('message_id', 'prune-recent-event')->exists())->toBeTrue();
});

test('old attempts are pruned and recent attempts are retained', function (): void {
    oldAttempt('prune-old-attempt');
    recentAttempt('prune-recent-attempt');

    expect(Artisan::call('talkto:prune', ['--type' => 'attempts']))->toBe(0)
        ->and(Artisan::output())->toContain('Attempts deleted: 1')
        ->and(TalktoAttempt::query()->where('message_id', 'prune-old-attempt')->exists())->toBeFalse()
        ->and(TalktoAttempt::query()->where('message_id', 'prune-recent-attempt')->exists())->toBeTrue();
});

test('old dead letters are pruned and recent dead letters are retained', function (): void {
    oldDeadLetter('prune-old-dead-letter');
    recentDeadLetter('prune-recent-dead-letter');

    expect(Artisan::call('talkto:prune', ['--type' => 'dead-letters']))->toBe(0)
        ->and(Artisan::output())->toContain('Dead letters deleted: 1')
        ->and(TalktoDeadLetter::query()->where('message_id', 'prune-old-dead-letter')->exists())->toBeFalse()
        ->and(TalktoDeadLetter::query()->where('message_id', 'prune-recent-dead-letter')->exists())->toBeTrue();
});

test('expired nonces are pruned and fresh nonces are retained', function (): void {
    oldNonce('prune-old-nonce');
    recentNonce('prune-recent-nonce');

    expect(Artisan::call('talkto:prune', ['--type' => 'nonces']))->toBe(0)
        ->and(Artisan::output())->toContain('Nonces deleted: 1')
        ->and(TalktoNonce::query()->where('message_id', 'prune-old-nonce')->exists())->toBeFalse()
        ->and(TalktoNonce::query()->where('message_id', 'prune-recent-nonce')->exists())->toBeTrue();
});

test('old terminal messages are pruned and recent terminal messages are retained', function (): void {
    terminalMessage('prune-old-terminal', now()->subDays(120));
    terminalMessage('prune-recent-terminal', now()->subDays(10));

    expect(Artisan::call('talkto:prune', ['--type' => 'messages']))->toBe(0)
        ->and(Artisan::output())->toContain('Messages deleted: 1')
        ->and(TalktoMessage::query()->where('message_id', 'prune-old-terminal')->exists())->toBeFalse()
        ->and(TalktoMessage::query()->where('message_id', 'prune-recent-terminal')->exists())->toBeTrue();
});

test('active in flight messages are not pruned even when old', function (): void {
    foreach (['queued', 'pending', 'waiting_to_send', 'sending', 'processing', 'failed_retryable', 'destination_received'] as $status) {
        talktoMessage("active-{$status}", $status, now()->subDays(120));
    }

    expect(Artisan::call('talkto:prune', ['--type' => 'messages']))->toBe(0)
        ->and(Artisan::output())->toContain('Messages deleted: 0')
        ->and(TalktoMessage::query()->count())->toBe(7);
});

test('type events only prunes events', function (): void {
    oldEvent('type-events-event');
    oldAttempt('type-events-attempt');
    oldDeadLetter('type-events-dead-letter');
    terminalMessage('type-events-message', now()->subDays(120));

    expect(Artisan::call('talkto:prune', ['--type' => 'events']))->toBe(0)
        ->and(TalktoEvent::query()->count())->toBe(0)
        ->and(TalktoAttempt::query()->count())->toBe(1)
        ->and(TalktoDeadLetter::query()->count())->toBe(1)
        ->and(TalktoMessage::query()->count())->toBe(1);
});

test('type attempts only prunes attempts', function (): void {
    oldEvent('type-attempts-event');
    oldAttempt('type-attempts-attempt');
    oldDeadLetter('type-attempts-dead-letter');
    terminalMessage('type-attempts-message', now()->subDays(120));

    expect(Artisan::call('talkto:prune', ['--type' => 'attempts']))->toBe(0)
        ->and(TalktoAttempt::query()->count())->toBe(0)
        ->and(TalktoEvent::query()->count())->toBe(1)
        ->and(TalktoDeadLetter::query()->count())->toBe(1)
        ->and(TalktoMessage::query()->count())->toBe(1);
});

test('type dead letters only prunes dead letters', function (): void {
    oldEvent('type-dead-letters-event');
    oldAttempt('type-dead-letters-attempt');
    oldDeadLetter('type-dead-letters-dead-letter');
    terminalMessage('type-dead-letters-message', now()->subDays(120));

    expect(Artisan::call('talkto:prune', ['--type' => 'dead-letters']))->toBe(0)
        ->and(TalktoDeadLetter::query()->count())->toBe(0)
        ->and(TalktoAttempt::query()->count())->toBe(1)
        ->and(TalktoEvent::query()->count())->toBe(1)
        ->and(TalktoMessage::query()->count())->toBe(1);
});

test('type messages prunes terminal messages and required related rows only', function (): void {
    $terminal = terminalMessage('type-messages-terminal', now()->subDays(120));
    $active = talktoMessage('type-messages-active', 'sending', now()->subDays(120));
    oldAttempt('type-messages-terminal', $terminal);
    oldEvent('type-messages-terminal', $terminal);
    oldDeadLetter('type-messages-terminal', $terminal);
    oldAttempt('type-messages-active', $active);
    oldEvent('type-messages-active', $active);
    oldDeadLetter('type-messages-active', $active);

    expect(Artisan::call('talkto:prune', ['--type' => 'messages']))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Messages deleted: 1')
        ->and($output)->toContain('Related attempts deleted: 1')
        ->and($output)->toContain('Related events deleted: 1')
        ->and($output)->toContain('Related dead letters deleted: 1')
        ->and(TalktoMessage::query()->where('message_id', 'type-messages-terminal')->exists())->toBeFalse()
        ->and(TalktoMessage::query()->where('message_id', 'type-messages-active')->exists())->toBeTrue()
        ->and(TalktoAttempt::query()->where('message_id', 'type-messages-active')->exists())->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', 'type-messages-active')->exists())->toBeTrue()
        ->and(TalktoDeadLetter::query()->where('message_id', 'type-messages-active')->exists())->toBeTrue();
});

test('type all prunes all supported categories safely', function (): void {
    oldEvent('type-all-event');
    oldAttempt('type-all-attempt');
    oldDeadLetter('type-all-dead-letter');
    oldNonce('type-all-nonce');
    terminalMessage('type-all-message', now()->subDays(120));
    talktoMessage('type-all-active', 'processing', now()->subDays(120));

    expect(Artisan::call('talkto:prune', ['--type' => 'all']))->toBe(0)
        ->and(Artisan::output())->toContain('Messages deleted: 1')
        ->and(TalktoEvent::query()->count())->toBe(0)
        ->and(TalktoAttempt::query()->count())->toBe(0)
        ->and(TalktoDeadLetter::query()->count())->toBe(0)
        ->and(TalktoNonce::query()->count())->toBe(0)
        ->and(TalktoMessage::query()->where('message_id', 'type-all-message')->exists())->toBeFalse()
        ->and(TalktoMessage::query()->where('message_id', 'type-all-active')->exists())->toBeTrue();
});

test('older than option overrides configured retention', function (): void {
    config(['talkto.retention.events_days' => 90]);
    oldEvent('override-event', null, now()->subDays(40));

    expect(Artisan::call('talkto:prune', ['--type' => 'events']))->toBe(0)
        ->and(Artisan::output())->toContain('Events deleted: 0')
        ->and(TalktoEvent::query()->where('message_id', 'override-event')->exists())->toBeTrue();

    expect(Artisan::call('talkto:prune', ['--type' => 'events', '--older-than' => '30d']))->toBe(0)
        ->and(Artisan::output())->toContain('Events deleted: 1')
        ->and(TalktoEvent::query()->where('message_id', 'override-event')->exists())->toBeFalse();
});

test('older than supports hours and plain integers as days', function (): void {
    oldEvent('hours-event', null, now()->subHours(13));
    oldAttempt('plain-days-attempt', null, now()->subDays(2));

    expect(Artisan::call('talkto:prune', ['--type' => 'events', '--older-than' => '12h']))->toBe(0)
        ->and(TalktoEvent::query()->where('message_id', 'hours-event')->exists())->toBeFalse();

    expect(Artisan::call('talkto:prune', ['--type' => 'attempts', '--older-than' => '1']))->toBe(0)
        ->and(TalktoAttempt::query()->where('message_id', 'plain-days-attempt')->exists())->toBeFalse();
});

test('invalid older than returns non zero and does not delete anything', function (): void {
    oldEvent('invalid-event');

    expect(Artisan::call('talkto:prune', ['--type' => 'events', '--older-than' => 'soon']))->toBe(1)
        ->and(Artisan::output())->toContain('Invalid --older-than')
        ->and(TalktoEvent::query()->where('message_id', 'invalid-event')->exists())->toBeTrue();

    expect(Artisan::call('talkto:prune', ['--type' => 'events', '--older-than' => '0']))->toBe(1)
        ->and(TalktoEvent::query()->where('message_id', 'invalid-event')->exists())->toBeTrue();
});

test('limit option limits deletion count per type', function (): void {
    oldEvent('limit-one');
    oldEvent('limit-two');
    oldEvent('limit-three');

    expect(Artisan::call('talkto:prune', ['--type' => 'events', '--limit' => 2]))->toBe(0)
        ->and(Artisan::output())->toContain('Events deleted: 2')
        ->and(TalktoEvent::query()->count())->toBe(1);
});

test('message pruning does not leave broken related records', function (): void {
    $message = terminalMessage('no-broken-related', now()->subDays(120));
    oldAttempt('no-broken-related', $message);
    oldEvent('no-broken-related', $message);
    oldDeadLetter('no-broken-related', $message);

    expect(Artisan::call('talkto:prune', ['--type' => 'messages']))->toBe(0)
        ->and(TalktoMessage::query()->whereKey($message->id)->exists())->toBeFalse()
        ->and(TalktoAttempt::query()->where('talkto_message_id', $message->id)->orWhere('message_id', 'no-broken-related')->exists())->toBeFalse()
        ->and(TalktoEvent::query()->where('talkto_message_id', $message->id)->orWhere('message_id', 'no-broken-related')->exists())->toBeFalse()
        ->and(TalktoDeadLetter::query()->where('talkto_message_id', $message->id)->orWhere('message_id', 'no-broken-related')->exists())->toBeFalse();
});

test('invalid type and limit fail safely', function (): void {
    oldEvent('invalid-options-event');

    expect(Artisan::call('talkto:prune', ['--type' => 'unknown']))->toBe(1)
        ->and(Artisan::call('talkto:prune', ['--limit' => 0]))->toBe(1)
        ->and(TalktoEvent::query()->where('message_id', 'invalid-options-event')->exists())->toBeTrue();
});

function terminalMessage(string $messageId, mixed $createdAt): TalktoMessage
{
    return talktoMessage($messageId, 'succeeded', $createdAt, [
        'transport_status' => 'sent',
        'destination_action_status' => 'succeeded',
        'completed_at' => $createdAt,
    ]);
}

function talktoMessage(string $messageId, string $status, mixed $createdAt, array $attributes = []): TalktoMessage
{
    $message = TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source_service' => 'testing',
        'target_service' => 'peer',
        'command' => 'domain.command',
        'payload' => ['id' => $messageId],
        'payload_hash' => app(TalktoPayloadHasher::class)->hash(['id' => $messageId]),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => $status,
        'overall_status' => $status,
        'attempts' => 1,
        'retry_count' => 0,
        'max_attempts' => 5,
    ], $attributes));

    return timestamped($message, $createdAt);
}

function oldEvent(string $messageId, ?TalktoMessage $message = null, mixed $createdAt = null): TalktoEvent
{
    return timestamped(TalktoEvent::query()->create([
        'talkto_message_id' => $message?->id,
        'message_id' => $messageId,
        'service_name' => 'testing',
        'event_type' => 'test_event',
        'old_status' => 'old',
        'new_status' => 'new',
        'meta' => [],
    ]), $createdAt ?? now()->subDays(40));
}

function recentEvent(string $messageId): TalktoEvent
{
    return oldEvent($messageId, null, now()->subDays(5));
}

function oldAttempt(string $messageId, ?TalktoMessage $message = null, mixed $createdAt = null): TalktoAttempt
{
    return timestamped(TalktoAttempt::query()->create([
        'talkto_message_id' => $message?->id,
        'message_id' => $messageId,
        'stage' => 'transport',
        'attempt_no' => 1,
        'status' => 'failed',
        'meta' => [],
    ]), $createdAt ?? now()->subDays(120));
}

function recentAttempt(string $messageId): TalktoAttempt
{
    return oldAttempt($messageId, null, now()->subDays(5));
}

function oldDeadLetter(string $messageId, ?TalktoMessage $message = null, mixed $createdAt = null): TalktoDeadLetter
{
    return timestamped(TalktoDeadLetter::query()->create([
        'talkto_message_id' => $message?->id,
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source' => 'testing',
        'target' => 'peer',
        'command' => 'domain.command',
        'payload' => ['id' => $messageId],
        'headers' => [],
        'failure_reason' => 'Old failure.',
        'failed_status' => 'failed_final',
        'original_retry_count' => 1,
        'status' => 'open',
    ]), $createdAt ?? now()->subDays(200));
}

function recentDeadLetter(string $messageId): TalktoDeadLetter
{
    return oldDeadLetter($messageId, null, now()->subDays(5));
}

function oldNonce(string $messageId): TalktoNonce
{
    return TalktoNonce::query()->create([
        'nonce_hash' => hash('sha256', 'old-'.$messageId),
        'source_service' => 'source',
        'target_service' => 'testing',
        'message_id' => $messageId,
        'signature_version' => 'v2',
        'signed_timestamp' => now()->subDays(8)->toIso8601String(),
        'used_at' => now()->subDays(8),
        'expires_at' => now()->subDay(),
        'created_at' => now()->subDays(8),
        'updated_at' => now()->subDays(8),
    ]);
}

function recentNonce(string $messageId): TalktoNonce
{
    return TalktoNonce::query()->create([
        'nonce_hash' => hash('sha256', 'recent-'.$messageId),
        'source_service' => 'source',
        'target_service' => 'testing',
        'message_id' => $messageId,
        'signature_version' => 'v2',
        'signed_timestamp' => now()->toIso8601String(),
        'used_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);
}

function timestamped(mixed $model, mixed $createdAt): mixed
{
    $model->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ])->save();

    return $model->fresh();
}
