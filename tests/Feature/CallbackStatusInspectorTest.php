<?php

use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoCallbackStatusInspector;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.callbacks.command' => 'talkto.result',
        'talkto.outgoing.peer' => [
            'base_url' => 'https://peer.test',
            'secret' => 'callback-status-secret',
        ],
        'talkto.incoming.peer' => [
            'secret' => 'callback-status-secret',
            'allowed_commands' => [
                'domain.command' => ['driver' => 'none'],
                'talkto.result' => ['driver' => 'none'],
            ],
        ],
    ]);
});

test('destination incoming processed message reports completed callback', function (): void {
    $incoming = cbStatusIncomingMessage('cb-status-incoming-completed', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);
    $callback = cbStatusCallbackMessage('cb-status-callback-completed', $incoming, [
        'transport_status' => 'sent',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'applied',
        'overall_status' => 'completed',
        'last_http_status' => 200,
        'sent_at' => now(),
        'completed_at' => now(),
    ]);
    cbStatusAttempt($callback, [
        'attempt_no' => 1,
        'status' => 'sent',
        'http_status' => 200,
    ]);
    cbStatusEvent($incoming, 'result_callback_queued', [
        'callback_message_id' => $callback->message_id,
    ]);

    $result = app(TalktoCallbackStatusInspector::class)->inspect($incoming);

    expect($result['applicable'])->toBeTrue()
        ->and($result['context'])->toBe('destination_incoming')
        ->and($result['state'])->toBe('callback_completed')
        ->and($result['callback_message']['message_id'])->toBe($callback->message_id)
        ->and($result['attempts']['count'])->toBe(1)
        ->and($result['attempts']['last_status'])->toBe('sent')
        ->and($result['events']['result_callback_queued'])->toBeTrue();
});

test('destination incoming processed message reports missing callback message', function (): void {
    $incoming = cbStatusIncomingMessage('cb-status-incoming-missing-callback', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);

    $result = app(TalktoCallbackStatusInspector::class)->inspect($incoming);

    expect($result['applicable'])->toBeTrue()
        ->and($result['context'])->toBe('destination_incoming')
        ->and($result['state'])->toBe('callback_message_missing')
        ->and($result['callback_message'])->toBeNull()
        ->and($result['summary'])->toContain('No durable callback message');
});

test('destination incoming processed message reports failed retryable callback with redacted error', function (): void {
    $incoming = cbStatusIncomingMessage('cb-status-incoming-retryable-callback', [
        'destination_action_status' => 'failed_retryable',
        'overall_status' => 'failed_retryable',
        'failed_at' => now(),
    ]);
    $callback = cbStatusCallbackMessage('cb-status-callback-retryable', $incoming, [
        'transport_status' => 'failed',
        'overall_status' => 'failed_retryable',
        'last_error' => 'Transport failed with callback-status-secret',
        'failed_at' => now(),
    ]);
    cbStatusAttempt($callback, [
        'attempt_no' => 2,
        'status' => 'failed_retryable',
        'error_message' => 'Retryable failure with callback-status-secret',
    ]);

    $result = app(TalktoCallbackStatusInspector::class)->inspect($incoming);
    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);

    expect($result['state'])->toBe('callback_failed_retryable')
        ->and($result['callback_message']['last_error'])->toContain('[redacted]')
        ->and($result['attempts']['last_error'])->toContain('[redacted]')
        ->and($encoded)->not->toContain('callback-status-secret');
});

test('destination incoming processed message reports dead lettered callback', function (): void {
    $incoming = cbStatusIncomingMessage('cb-status-incoming-dead-lettered', [
        'destination_action_status' => 'failed_final',
        'overall_status' => 'failed_final',
        'failed_at' => now(),
    ]);
    $callback = cbStatusCallbackMessage('cb-status-callback-dead-lettered', $incoming, [
        'transport_status' => 'failed_final',
        'overall_status' => 'failed_final',
        'failed_at' => now(),
    ]);
    cbStatusDeadLetter($callback);

    $result = app(TalktoCallbackStatusInspector::class)->inspect($incoming);

    expect($result['state'])->toBe('callback_dead_lettered')
        ->and($result['dead_letter']['exists'])->toBeTrue()
        ->and($result['dead_letter']['status'])->toBe('open');
});

test('source outgoing message reports waiting for callback after destination receive', function (): void {
    $outgoing = cbStatusOutgoingMessage('cb-status-source-waiting', [
        'transport_status' => 'sent',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'destination_received',
        'sent_at' => now(),
    ]);

    $result = app(TalktoCallbackStatusInspector::class)->inspect($outgoing);

    expect($result['applicable'])->toBeTrue()
        ->and($result['context'])->toBe('source_outgoing')
        ->and($result['state'])->toBe('waiting_for_callback')
        ->and($result['callback_message'])->toBeNull();
});

test('source outgoing message reports applied callback from receiver events', function (): void {
    $outgoing = cbStatusOutgoingMessage('cb-status-source-applied', [
        'transport_status' => 'sent',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'succeeded',
        'overall_status' => 'completed',
        'completed_at' => now(),
    ]);
    cbStatusEvent($outgoing, 'result_callback_received', [
        'callback_message_id' => 'cb-status-remote-callback',
        'status' => 'succeeded',
    ]);
    cbStatusEvent($outgoing, 'result_callback_applied', [
        'callback_message_id' => 'cb-status-remote-callback',
        'destination_action_status' => 'succeeded',
    ]);

    $result = app(TalktoCallbackStatusInspector::class)->inspect($outgoing);

    expect($result['context'])->toBe('source_outgoing')
        ->and($result['state'])->toBe('callback_applied')
        ->and($result['events']['result_callback_received'])->toBeTrue()
        ->and($result['events']['result_callback_applied'])->toBeTrue();
});

test('outgoing callback message inspection includes parent message', function (): void {
    $incoming = cbStatusIncomingMessage('cb-status-parent-incoming', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);
    $callback = cbStatusCallbackMessage('cb-status-outgoing-callback', $incoming, [
        'transport_status' => 'sent',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'applied',
        'overall_status' => 'completed',
        'completed_at' => now(),
    ]);

    $result = app(TalktoCallbackStatusInspector::class)->inspect($callback);

    expect($result['applicable'])->toBeTrue()
        ->and($result['context'])->toBe('outgoing_callback')
        ->and($result['state'])->toBe('callback_completed')
        ->and($result['callback_message']['message_id'])->toBe($callback->message_id)
        ->and($result['parent_message']['message_id'])->toBe($incoming->message_id);
});

test('early outgoing normal message is not applicable', function (): void {
    $outgoing = cbStatusOutgoingMessage('cb-status-source-early', [
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
    ]);

    $result = app(TalktoCallbackStatusInspector::class)->inspect($outgoing);

    expect($result['applicable'])->toBeFalse()
        ->and($result['context'])->toBe('source_outgoing')
        ->and($result['state'])->toBe('not_applicable');
});

test('inspector does not mutate callback storage', function (): void {
    $incoming = cbStatusIncomingMessage('cb-status-read-only', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);
    cbStatusCallbackMessage('cb-status-read-only-callback', $incoming, [
        'overall_status' => 'completed',
        'completed_at' => now(),
    ]);

    $before = [
        TalktoMessage::query()->count(),
        TalktoEvent::query()->count(),
        TalktoAttempt::query()->count(),
        TalktoDeadLetter::query()->count(),
    ];

    app(TalktoCallbackStatusInspector::class)->inspect($incoming);

    $after = [
        TalktoMessage::query()->count(),
        TalktoEvent::query()->count(),
        TalktoAttempt::query()->count(),
        TalktoDeadLetter::query()->count(),
    ];

    expect($after)->toBe($before);
});

test('inspector service is applicable helper mirrors inspect result', function (): void {
    $incoming = cbStatusIncomingMessage('cb-status-helper', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);

    $inspector = app(TalktoCallbackStatusInspector::class);

    expect($inspector)->toBeInstanceOf(TalktoCallbackStatusInspector::class)
        ->and($inspector->isApplicable($incoming))->toBeTrue()
        ->and($inspector->inspect($incoming)['applicable'])->toBeTrue();
});

function cbStatusIncomingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    return cbStatusMessage($messageId, array_merge([
        'direction' => 'incoming',
        'source_service' => 'peer',
        'target_service' => 'testing',
        'command' => 'domain.command',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'queued',
        'received_at' => now(),
    ], $attributes));
}

function cbStatusOutgoingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    return cbStatusMessage($messageId, array_merge([
        'direction' => 'outgoing',
        'source_service' => 'testing',
        'target_service' => 'peer',
        'command' => 'domain.command',
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
    ], $attributes));
}

function cbStatusCallbackMessage(string $messageId, TalktoMessage $incoming, array $attributes = []): TalktoMessage
{
    return cbStatusMessage($messageId, array_merge([
        'parent_message_id' => $incoming->message_id,
        'direction' => 'outgoing',
        'source_service' => 'testing',
        'target_service' => 'peer',
        'command' => 'talkto.result',
        'payload' => [
            'original_message_id' => $incoming->message_id,
            'original_command' => $incoming->command,
            'status' => 'succeeded',
            'succeeded' => true,
            'retryable' => false,
            'skipped' => false,
        ],
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
    ], $attributes));
}

function cbStatusMessage(string $messageId, array $attributes = []): TalktoMessage
{
    $payload = $attributes['payload'] ?? ['id' => $messageId];

    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'correlation-'.$messageId,
        'business_key' => 'business-'.$messageId,
        'idempotency_key' => 'idempotency-'.$messageId,
        'payload' => $payload,
        'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        'schema_version' => 1,
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
    ], $attributes));
}

function cbStatusAttempt(TalktoMessage $message, array $attributes = []): TalktoAttempt
{
    return TalktoAttempt::query()->create(array_merge([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'stage' => 'transport',
        'attempt_no' => 1,
        'status' => 'sent',
    ], $attributes));
}

function cbStatusEvent(TalktoMessage $message, string $eventType, array $meta = []): TalktoEvent
{
    return TalktoEvent::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'service_name' => config('talkto.service', 'app'),
        'event_type' => $eventType,
        'old_status' => null,
        'new_status' => null,
        'meta' => $meta,
    ]);
}

function cbStatusDeadLetter(TalktoMessage $message): TalktoDeadLetter
{
    return TalktoDeadLetter::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'direction' => $message->direction,
        'source' => $message->source_service,
        'target' => $message->target_service,
        'command' => $message->command,
        'failure_reason' => 'Callback failed permanently.',
        'failed_status' => $message->overall_status,
        'status' => 'open',
    ]);
}
