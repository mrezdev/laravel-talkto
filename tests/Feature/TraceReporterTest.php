<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoTraceReporter;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.outgoing.peer' => [
            'url' => 'https://peer.test',
            'secret' => 'shared-trace-secret',
        ],
        'talkto.incoming.peer' => [
            'secret' => 'incoming-trace-secret',
        ],
    ]);
});

test('trace by message id finds anchor related correlation children and parent messages', function (): void {
    $parent = p05TraceMessage('trace-parent', ['correlation_id' => 'trace-corr']);
    $anchor = p05TraceMessage('trace-anchor', [
        'correlation_id' => 'trace-corr',
        'parent_message_id' => $parent->message_id,
        'created_at' => now()->subMinutes(4),
    ]);
    p05TraceMessage('trace-sibling', [
        'correlation_id' => 'trace-corr',
        'created_at' => now()->subMinutes(3),
    ]);
    p05TraceMessage('trace-child', [
        'correlation_id' => 'other-corr',
        'parent_message_id' => $anchor->message_id,
        'created_at' => now()->subMinutes(2),
    ]);
    p05TraceMessage('trace-unrelated', ['correlation_id' => 'other-corr']);

    $snapshot = app(TalktoTraceReporter::class)->traceByMessageId('trace-anchor')->toArray();
    $messageIds = array_column($snapshot['related_messages'], 'message_id');

    expect($snapshot['found'])->toBeTrue()
        ->and($snapshot['anchor_message']['message_id'])->toBe('trace-anchor')
        ->and($messageIds)->toContain('trace-parent')
        ->and($messageIds)->toContain('trace-anchor')
        ->and($messageIds)->toContain('trace-sibling')
        ->and($messageIds)->toContain('trace-child')
        ->and($messageIds)->not->toContain('trace-unrelated');
});

test('trace by correlation id includes all messages and chooses earliest anchor', function (): void {
    p05TraceMessage('trace-corr-late', [
        'correlation_id' => 'trace-correlation',
        'created_at' => now()->subMinute(),
    ]);
    p05TraceMessage('trace-corr-early', [
        'correlation_id' => 'trace-correlation',
        'created_at' => now()->subMinutes(5),
    ]);

    $snapshot = app(TalktoTraceReporter::class)->traceByCorrelationId('trace-correlation')->toArray();

    expect($snapshot['found'])->toBeTrue()
        ->and($snapshot['anchor_message']['message_id'])->toBe('trace-corr-early')
        ->and(array_column($snapshot['related_messages'], 'message_id'))->toContain('trace-corr-late')
        ->and(array_column($snapshot['related_messages'], 'message_id'))->toContain('trace-corr-early');
});

test('trace includes attempts events dead letters and sorted timeline', function (): void {
    p05TraceMessage('trace-with-sections', ['created_at' => now()->subMinutes(5)]);
    p05TraceAttempt('trace-with-sections', 'failed', ['created_at' => now()->subMinutes(3)]);
    p05TraceEvent('trace-with-sections', 'message_failed', ['created_at' => now()->subMinutes(2)]);
    p05TraceDeadLetter('trace-with-sections', ['created_at' => now()->subMinute()]);

    $snapshot = app(TalktoTraceReporter::class)->traceByMessageId('trace-with-sections')->toArray();
    $timestamps = array_column($snapshot['timeline'], 'at');

    expect($snapshot['attempts'])->toHaveCount(1)
        ->and($snapshot['events'])->toHaveCount(1)
        ->and($snapshot['dead_letters'])->toHaveCount(1)
        ->and($timestamps)->toBe(collect($timestamps)->sort()->values()->all());
});

test('trace limit is respected and truncated is marked', function (): void {
    p05TraceMessage('trace-limited', ['created_at' => now()->subMinutes(10)]);

    foreach (range(1, 5) as $index) {
        p05TraceEvent('trace-limited', 'trace_event_'.$index, [
            'created_at' => now()->subMinutes(10 - $index),
        ]);
    }

    $snapshot = app(TalktoTraceReporter::class)->traceByMessageId('trace-limited', 2)->toArray();

    expect($snapshot['events'])->toHaveCount(2)
        ->and($snapshot['timeline'])->toHaveCount(2)
        ->and($snapshot['truncated'])->toBeTrue();
});

test('trace redacts payload by default and keeps secret-like values redacted when payload is included', function (): void {
    p05TraceMessage('trace-redaction', [
        'payload' => [
            'visible' => 'safe-value',
            'api_key' => 'payload-secret',
            'nested' => ['token' => 'nested-secret'],
        ],
        'last_error' => 'failed with shared-trace-secret',
        'last_response' => 'response incoming-trace-secret',
    ]);
    p05TraceAttempt('trace-redaction', 'failed', [
        'error_message' => 'attempt shared-trace-secret',
        'request_excerpt' => 'X-Talkto-Signature: raw-signature',
        'response_excerpt' => 'body incoming-trace-secret',
        'meta' => ['authorization' => 'Bearer secret', 'note' => 'shared-trace-secret'],
    ]);

    $default = app(TalktoTraceReporter::class)->traceByMessageId('trace-redaction')->toArray();
    $withPayload = app(TalktoTraceReporter::class)->traceByMessageId('trace-redaction', 100, true)->toArray();

    expect($default['anchor_message']['payload'])->toBe([
        'redacted' => true,
        'keys' => ['visible', 'api_key', 'nested'],
    ])->and($default['anchor_message']['last_error'])->toBe('failed with [redacted]')
        ->and($default['anchor_message']['last_response'])->toBe('response [redacted]')
        ->and($withPayload['anchor_message']['payload']['visible'])->toBe('safe-value')
        ->and($withPayload['anchor_message']['payload']['api_key'])->toBe('[redacted]')
        ->and($withPayload['anchor_message']['payload']['nested']['token'])->toBe('[redacted]')
        ->and($withPayload['attempts'][0]['error_message'])->toBe('attempt [redacted]')
        ->and($withPayload['attempts'][0]['request_excerpt'])->toBe('X-Talkto-Signature: [redacted]')
        ->and($withPayload['attempts'][0]['response_excerpt'])->toBe('body [redacted]')
        ->and($withPayload['attempts'][0]['meta']['authorization'])->toBe('[redacted]')
        ->and($withPayload['attempts'][0]['meta']['note'])->toBe('[redacted]');
});

test('trace payload option applies to dead letter payloads while headers stay redacted', function (): void {
    p05TraceMessage('trace-dead-letter-payload');
    p05TraceDeadLetter('trace-dead-letter-payload', [
        'payload' => [
            'visible' => 'safe-dead-letter-value',
            'token' => 'token-secret',
            'secret' => 'plain-secret',
            'password' => 'password-secret',
            'signature' => 'signature-secret',
            'authorization' => 'Bearer secret',
            'api_key' => 'api-key-secret',
            'key' => 'generic-key-secret',
        ],
        'headers' => [
            'authorization' => 'Bearer raw',
            'X-Talkto-Signature' => 'raw-signature',
        ],
    ]);

    $default = app(TalktoTraceReporter::class)->traceByMessageId('trace-dead-letter-payload')->toArray();
    $withPayload = app(TalktoTraceReporter::class)->traceByMessageId('trace-dead-letter-payload', 100, true)->toArray();

    expect($default['dead_letters'][0]['payload'])->toBe([
        'redacted' => true,
        'keys' => ['visible', 'token', 'secret', 'password', 'signature', 'authorization', 'api_key', 'key'],
    ])->and($default['dead_letters'][0]['headers'])->toBe('[redacted]')
        ->and($withPayload['dead_letters'][0]['payload']['visible'])->toBe('safe-dead-letter-value')
        ->and($withPayload['dead_letters'][0]['payload']['token'])->toBe('[redacted]')
        ->and($withPayload['dead_letters'][0]['payload']['secret'])->toBe('[redacted]')
        ->and($withPayload['dead_letters'][0]['payload']['password'])->toBe('[redacted]')
        ->and($withPayload['dead_letters'][0]['payload']['signature'])->toBe('[redacted]')
        ->and($withPayload['dead_letters'][0]['payload']['authorization'])->toBe('[redacted]')
        ->and($withPayload['dead_letters'][0]['payload']['api_key'])->toBe('[redacted]')
        ->and($withPayload['dead_letters'][0]['payload']['key'])->toBe('[redacted]')
        ->and($withPayload['dead_letters'][0]['headers'])->toBe('[redacted]');

    expect(Artisan::call('talkto:trace', [
        'message_id' => 'trace-dead-letter-payload',
        '--json' => true,
        '--payload' => true,
    ]))->toBe(0);

    $json = json_decode(Artisan::output(), true);

    expect($json['dead_letters'][0]['payload']['visible'])->toBe('safe-dead-letter-value')
        ->and($json['dead_letters'][0]['payload']['token'])->toBe('[redacted]')
        ->and($json['dead_letters'][0]['headers'])->toBe('[redacted]');
});

test('trace returns warnings instead of throwing when optional tables are missing', function (): void {
    p05TraceMessage('trace-missing-tables');

    Schema::dropIfExists('talkto_attempts');
    Schema::dropIfExists('talkto_events');
    Schema::dropIfExists('talkto_dead_letters');

    $snapshot = app(TalktoTraceReporter::class)->traceByMessageId('trace-missing-tables')->toArray();

    expect($snapshot['found'])->toBeTrue()
        ->and($snapshot['attempts'])->toBe([])
        ->and($snapshot['events'])->toBe([])
        ->and($snapshot['dead_letters'])->toBe([])
        ->and($snapshot['warnings'])->toContain('attempts_table_missing')
        ->and($snapshot['warnings'])->toContain('events_table_missing')
        ->and($snapshot['warnings'])->toContain('dead_letters_table_missing');
});

test('trace command outputs json and readable human output', function (): void {
    p05TraceMessage('trace-command');
    p05TraceEvent('trace-command', 'command_event');

    expect(Artisan::call('talkto:trace', [
        'message_id' => 'trace-command',
        '--json' => true,
    ]))->toBe(0);

    $json = json_decode(Artisan::output(), true);

    expect($json['found'])->toBeTrue()
        ->and($json['anchor_message']['message_id'])->toBe('trace-command');

    expect(Artisan::call('talkto:trace', ['message_id' => 'trace-command']))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Talkto trace')
        ->and($output)->toContain('trace-command')
        ->and($output)->toContain('related_messages=1');
});

test('trace command validates required input and limit', function (): void {
    expect(Artisan::call('talkto:trace'))->toBe(1)
        ->and(Artisan::output())->toContain('Provide a message_id or --correlation.');

    expect(Artisan::call('talkto:trace', [
        'message_id' => 'trace-any',
        '--limit' => 0,
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('Invalid --limit');

    expect(Artisan::call('talkto:trace', [
        'message_id' => 'trace-any',
        '--limit' => 501,
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('Invalid --limit');
});

test('trace command and reporter do not mutate observability rows', function (): void {
    p05TraceMessage('trace-readonly');
    p05TraceAttempt('trace-readonly', 'failed');
    p05TraceEvent('trace-readonly', 'readonly_event');
    p05TraceDeadLetter('trace-readonly');

    $before = [
        TalktoMessage::query()->count(),
        TalktoAttempt::query()->count(),
        TalktoEvent::query()->count(),
        TalktoDeadLetter::query()->count(),
    ];

    app(TalktoTraceReporter::class)->traceByMessageId('trace-readonly');
    expect(Artisan::call('talkto:trace', [
        'message_id' => 'trace-readonly',
        '--json' => true,
        '--payload' => true,
    ]))->toBe(0);

    $after = [
        TalktoMessage::query()->count(),
        TalktoAttempt::query()->count(),
        TalktoEvent::query()->count(),
        TalktoDeadLetter::query()->count(),
    ];

    expect($after)->toBe($before);
});

function p05TraceMessage(string $messageId, array $attributes = []): TalktoMessage
{
    $createdAt = $attributes['created_at'] ?? now()->subMinutes(5);
    $payload = $attributes['payload'] ?? ['id' => $messageId];

    $message = TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'correlation-'.$messageId,
        'parent_message_id' => null,
        'direction' => 'outgoing',
        'source_service' => 'source',
        'target_service' => 'target',
        'command' => 'domain.command',
        'payload' => $payload,
        'payload_hash' => 'hash-'.$messageId,
        'schema_version' => 1,
        'source_action_status' => 'succeeded',
        'transport_status' => 'sent',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'destination_received',
        'retry_count' => 0,
        'max_attempts' => 3,
        'last_error' => null,
        'last_response' => null,
    ], array_diff_key($attributes, array_flip(['created_at', 'updated_at']))));

    $message->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $attributes['updated_at'] ?? $createdAt,
    ])->save();

    return $message->fresh();
}

function p05TraceAttempt(string $messageId, string $status, array $attributes = []): TalktoAttempt
{
    $createdAt = $attributes['created_at'] ?? now()->subMinutes(4);

    $attempt = TalktoAttempt::query()->create(array_merge([
        'message_id' => $messageId,
        'stage' => 'transport',
        'attempt_no' => 1,
        'status' => $status,
        'error_message' => null,
        'response_excerpt' => null,
        'meta' => [],
    ], array_diff_key($attributes, array_flip(['created_at', 'updated_at']))));

    $attempt->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $attributes['updated_at'] ?? $createdAt,
    ])->save();

    return $attempt->fresh();
}

function p05TraceEvent(string $messageId, string $eventType, array $attributes = []): TalktoEvent
{
    $createdAt = $attributes['created_at'] ?? now()->subMinutes(3);

    $event = TalktoEvent::query()->create(array_merge([
        'message_id' => $messageId,
        'service_name' => 'testing',
        'event_type' => $eventType,
        'old_status' => null,
        'new_status' => null,
        'meta' => [],
    ], array_diff_key($attributes, array_flip(['created_at', 'updated_at']))));

    $event->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $attributes['updated_at'] ?? $createdAt,
    ])->save();

    return $event->fresh();
}

function p05TraceDeadLetter(string $messageId, array $attributes = []): TalktoDeadLetter
{
    $createdAt = $attributes['created_at'] ?? now()->subMinutes(2);

    $deadLetter = TalktoDeadLetter::query()->create(array_merge([
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source' => 'source',
        'target' => 'target',
        'command' => 'domain.command',
        'payload' => ['id' => $messageId],
        'headers' => ['authorization' => 'Bearer raw'],
        'failure_reason' => 'final failure',
        'failed_status' => 'failed_final',
        'status' => 'open',
    ], array_diff_key($attributes, array_flip(['created_at', 'updated_at']))));

    $deadLetter->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $attributes['updated_at'] ?? $createdAt,
    ])->save();

    return $deadLetter->fresh();
}
