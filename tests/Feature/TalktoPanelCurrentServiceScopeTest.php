<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

beforeEach(function (): void {
    $this->bootSharedPanelApp = function (array $env = []): void {
        p8SharedPanelUseEnv(array_merge(['TALKTO_PANEL_ENABLED' => 'true'], $env));

        $this->refreshApplication();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        expect($this->artisan('migrate')->run())->toBe(0);

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'talkto.panel.authorization.enabled' => false,
            'talkto.service' => 'website',
            'talkto.retry.enabled' => true,
            'talkto.retry.outgoing_enabled' => true,
            'talkto.retry.retryable_statuses' => ['failed_retryable'],
            'talkto.dead_letter.enabled' => true,
            'talkto.dead_letter.allow_reprocess' => true,
            'talkto.outgoing.inventory' => [
                'url' => 'https://inventory.test',
                'secret' => 'inventory-secret',
                'endpoint' => '/api/talkto/receive',
            ],
        ]);

        View::replaceNamespace('talkto', realpath(__DIR__.'/../../resources/views'));

        $this->withoutMiddleware();
    };
});

afterEach(function (): void {
    p8SharedPanelClearEnv();
});

test('panel list and show routes hide messages unrelated to the current service by default', function (): void {
    ($this->bootSharedPanelApp)();

    $owned = p8SharedPanelMessage('shared-panel-owned', 'outgoing', 'completed', [
        'source_service' => 'website',
        'target_service' => 'inventory',
    ]);
    $foreign = p8SharedPanelMessage('shared-panel-foreign', 'outgoing', 'completed', [
        'source_service' => 'crm',
        'target_service' => 'inventory',
    ]);

    $this->getJson('/talkto/messages')
        ->assertOk()
        ->assertJsonPath('messages.total', 1)
        ->assertJsonPath('messages.data.0.message_id', $owned->message_id);

    $this->getJson('/talkto/messages/'.$owned->message_id)->assertOk();
    $this->getJson('/talkto/messages/'.$foreign->message_id)->assertNotFound();
});

test('central observer panel can read all messages but cannot retry another service message by default', function (): void {
    ($this->bootSharedPanelApp)(['TALKTO_PANEL_CURRENT_SERVICE_ONLY' => 'false']);
    Queue::fake();

    $foreign = p8SharedPanelMessage('shared-panel-retry-foreign', 'outgoing', 'failed_retryable', [
        'source_service' => 'crm',
        'target_service' => 'inventory',
        'next_retry_at' => now()->subMinute(),
    ]);

    $this->getJson('/talkto/messages/'.$foreign->message_id)->assertOk();

    $this->postJson('/talkto/messages/'.$foreign->message_id.'/retry')
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Message belongs to another service.');

    Queue::assertNothingPushed();

    expect($foreign->fresh()->overall_status)->toBe('failed_retryable');
});

test('central observer panel cannot reprocess another service dead letter by default', function (): void {
    ($this->bootSharedPanelApp)(['TALKTO_PANEL_CURRENT_SERVICE_ONLY' => 'false']);
    Queue::fake();

    $foreign = p8SharedPanelMessage('shared-panel-dlq-foreign', 'outgoing', 'failed_final', [
        'source_service' => 'crm',
        'target_service' => 'inventory',
    ]);
    $deadLetter = p8SharedPanelDeadLetter($foreign);

    $this->postJson('/talkto/dead-letters/'.$deadLetter->id.'/reprocess')
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Original message belongs to another service.');

    Queue::assertNotPushed(SendTalktoMessage::class);

    expect($foreign->fresh()->overall_status)->toBe('failed_final')
        ->and($deadLetter->fresh()->status)->toBe('open');
});

function p8SharedPanelUseEnv(array $values = []): void
{
    p8SharedPanelClearEnv();

    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function p8SharedPanelClearEnv(): void
{
    foreach ([
        'TALKTO_PANEL_ENABLED',
        'TALKTO_PANEL_CURRENT_SERVICE_ONLY',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

function p8SharedPanelMessage(string $messageId, string $direction, string $status, array $attributes = []): TalktoMessage
{
    $createdAt = $attributes['created_at'] ?? now();

    $message = TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'corr-'.$messageId,
        'direction' => $direction,
        'source_service' => $direction === 'incoming' ? 'billing' : 'website',
        'target_service' => $direction === 'outgoing' ? 'inventory' : 'website',
        'command' => $direction === 'incoming' ? 'billing.invoice-paid' : 'verify-invoice',
        'business_key' => null,
        'idempotency_key' => null,
        'payload' => ['id' => $messageId],
        'payload_hash' => 'hash-'.$messageId,
        'schema_version' => 1,
        'source_action_status' => $direction === 'outgoing' ? $status : null,
        'transport_status' => $direction === 'outgoing' ? $status : null,
        'destination_receive_status' => $direction === 'incoming' ? 'received' : null,
        'destination_action_status' => $direction === 'incoming' ? $status : null,
        'overall_status' => $status,
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 3,
        'next_retry_at' => null,
        'completed_at' => in_array($status, ['completed', 'succeeded'], true) ? $createdAt : null,
        'failed_at' => str_starts_with($status, 'failed') ? $createdAt : null,
    ], array_diff_key($attributes, array_flip(['created_at', 'updated_at']))));

    $message->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $attributes['updated_at'] ?? $createdAt,
    ])->save();

    return $message->fresh();
}

function p8SharedPanelDeadLetter(TalktoMessage $message, array $attributes = []): TalktoDeadLetter
{
    return TalktoDeadLetter::query()->create(array_merge([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'direction' => $message->direction,
        'source' => $message->source_service,
        'target' => $message->target_service,
        'command' => $message->command,
        'failed_status' => 'failed_final',
        'status' => 'open',
    ], $attributes));
}
