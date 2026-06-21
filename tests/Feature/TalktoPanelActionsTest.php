<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;

class FailingPanelSendTalktoMessage extends SendTalktoMessage
{
    public static function dispatch(...$arguments): mixed
    {
        throw new RuntimeException('Dispatch failed for outgoing-secret');
    }
}

beforeEach(function (): void {
    $this->bootPanelActionsApp = function (array $env = []): void {
        p4PanelUseEnv(array_merge(['TALKTO_PANEL_ENABLED' => 'true'], $env));

        $this->refreshApplication();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        expect($this->artisan('migrate')->run())->toBe(0);

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'talkto.panel.authorization.enabled' => false,
            'talkto.service' => 'panel-test',
            'talkto.retry.enabled' => true,
            'talkto.retry.outgoing_enabled' => true,
            'talkto.retry.incoming_enabled' => true,
            'talkto.retry.retryable_statuses' => ['failed_retryable'],
            'talkto.dead_letter.enabled' => true,
            'talkto.dead_letter.allow_reprocess' => true,
            'talkto.dead_letter.max_reprocess_attempts' => 3,
            'talkto.outgoing.target-alpha' => [
                'url' => 'https://target-alpha.test',
                'secret' => 'outgoing-secret',
                'endpoint' => '/api/talkto/receive',
            ],
            'talkto.incoming.source-alpha' => [
                'secret' => 'incoming-secret',
                'allowed_commands' => [
                    'website.event-created' => ['driver' => 'handler'],
                ],
            ],
        ]);

        View::replaceNamespace('talkto', realpath(__DIR__.'/../../resources/views'));

        $this->withoutMiddleware();
    };
});

afterEach(function (): void {
    p4PanelClearEnv();
});

test('panel action routes are registered when panel is enabled', function (): void {
    ($this->bootPanelActionsApp)();

    expect(Route::has('talkto.panel.messages.retry'))->toBeTrue()
        ->and(Route::has('talkto.panel.messages.trace'))->toBeTrue()
        ->and(Route::has('talkto.panel.dead-letters.reprocess'))->toBeTrue();
});

test('panel action executor uses translated result messages', function (): void {
    $source = file_get_contents(__DIR__.'/../../src/Services/Panel/TalktoPanelActionExecutor.php') ?: '';

    expect($source)->toContain("actionText('retry_disabled')")
        ->and($source)->toContain("actionText('retry_dispatched')")
        ->and($source)->toContain("actionText('dead_letter_reprocess_dispatched')")
        ->and($source)->toContain('talkto::panel.actions.{$key}');

    foreach ([
        'Panel retry action is disabled.',
        'Unsupported message direction.',
        'Message is not retryable.',
        'Retry job dispatched.',
        'Dead letter reprocess job dispatched.',
    ] as $message) {
        expect($source)->not->toContain($message);
    }
});

test('panel authorization applies to action routes', function (): void {
    ($this->bootPanelActionsApp)();

    config(['talkto.panel.authorization.enabled' => true]);
    $message = p4PanelMessage('panel-auth-retry', 'outgoing', 'failed_retryable');

    $this->postJson('/talkto/messages/'.$message->message_id.'/retry')->assertForbidden();
    $this->getJson('/talkto/messages/'.$message->message_id.'/trace')->assertForbidden();
});

test('retry action dispatches outgoing retry job and records event', function (): void {
    ($this->bootPanelActionsApp)();
    Queue::fake();

    $originalRetryCount = 0;
    $message = p4PanelMessage('panel-retry-outgoing', 'outgoing', 'failed_retryable', [
        'next_retry_at' => now()->addHour(),
        'next_attempt_at' => now()->addHour(),
        'locked_at' => now(),
        'locked_by' => 'worker-1',
        'retry_count' => $originalRetryCount,
    ]);

    $this->postJson('/talkto/messages/'.$message->message_id.'/retry')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Retry job dispatched.')
        ->assertJsonPath('meta.message_id', $message->message_id);

    Queue::assertPushed(SendTalktoMessage::class, fn (SendTalktoMessage $job): bool => $job->talktoMessageId === $message->id);

    $message->refresh();

    expect($message->next_retry_at)->not->toBeNull()
        ->and($message->next_retry_at->lessThanOrEqualTo(now()))->toBeTrue()
        ->and($message->next_attempt_at)->not->toBeNull()
        ->and($message->next_attempt_at->lessThanOrEqualTo(now()))->toBeTrue()
        ->and($message->locked_at)->toBeNull()
        ->and($message->locked_by)->toBeNull()
        ->and($message->retry_count)->toBe($originalRetryCount);

    expect(TalktoEvent::query()
        ->where('message_id', $message->message_id)
        ->where('event_type', 'panel_retry_dispatched')
        ->where('meta->panel_action', true)
        ->exists())->toBeTrue();
});

test('retry action dispatches incoming retry job', function (): void {
    ($this->bootPanelActionsApp)();
    Queue::fake();

    $message = p4PanelMessage('panel-retry-incoming', 'incoming', 'failed_retryable', [
        'command' => 'website.event-created',
        'next_retry_at' => now()->addHour(),
        'next_attempt_at' => now()->addHour(),
    ]);

    $this->postJson('/talkto/messages/'.$message->message_id.'/retry')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.direction', 'incoming');

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, fn (ProcessIncomingTalktoMessage $job): bool => $job->talktoMessageId === $message->id);

    $message->refresh();

    expect($message->next_retry_at)->not->toBeNull()
        ->and($message->next_retry_at->lessThanOrEqualTo(now()))->toBeTrue()
        ->and($message->next_attempt_at)->not->toBeNull()
        ->and($message->next_attempt_at->lessThanOrEqualTo(now()))->toBeTrue();
});

test('retry action returns controlled failure when dispatch fails', function (): void {
    ($this->bootPanelActionsApp)();

    config(['talkto.jobs.send_message' => FailingPanelSendTalktoMessage::class]);
    $message = p4PanelMessage('panel-retry-dispatch-fails', 'outgoing', 'failed_retryable');

    $this->postJson('/talkto/messages/'.$message->message_id.'/retry')
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Retry job could not be dispatched.')
        ->assertJsonPath('meta.exception_class', RuntimeException::class);

    $event = TalktoEvent::query()
        ->where('message_id', $message->message_id)
        ->where('event_type', 'panel_retry_dispatch_failed')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->meta['panel_action'] ?? null)->toBeTrue()
        ->and($event->meta['exception_class'] ?? null)->toBe(RuntimeException::class)
        ->and($event->meta['exception_message'] ?? null)->toContain('[redacted]')
        ->and($event->meta['exception_message'] ?? null)->not->toContain('outgoing-secret');
});

test('retry action refuses terminal unsupported and disabled states without dispatching', function (): void {
    ($this->bootPanelActionsApp)();
    Queue::fake();

    $completed = p4PanelMessage('panel-retry-completed', 'outgoing', 'completed');
    $unsupported = p4PanelMessage('panel-retry-unsupported', 'sideways', 'failed_retryable', [
        'transport_status' => 'failed_retryable',
    ]);

    $this->postJson('/talkto/messages/'.$completed->message_id.'/retry')
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    $this->postJson('/talkto/messages/'.$unsupported->message_id.'/retry')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Unsupported message direction.');

    config(['talkto.panel.actions.retry_enabled' => false]);
    $retryable = p4PanelMessage('panel-retry-disabled', 'outgoing', 'failed_retryable');

    $this->postJson('/talkto/messages/'.$retryable->message_id.'/retry')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Panel retry action is disabled.');

    Queue::assertNothingPushed();
});

test('retry action redirects with flash message for html requests', function (): void {
    ($this->bootPanelActionsApp)();
    Queue::fake();

    $message = p4PanelMessage('panel-retry-html', 'outgoing', 'failed_retryable');

    $this->from('/talkto/messages/'.$message->message_id)
        ->post('/talkto/messages/'.$message->message_id.'/retry')
        ->assertRedirect(route('talkto.panel.messages.show', ['message' => $message->message_id]))
        ->assertSessionHas('talkto_panel_status', 'Retry job dispatched.');
});

test('dead-letter reprocess action dispatches outgoing job, prepares original message, and records event', function (): void {
    ($this->bootPanelActionsApp)();
    Queue::fake();

    $message = p4PanelMessage('panel-dlq-outgoing', 'outgoing', 'failed_final', [
        'next_retry_at' => now(),
        'next_attempt_at' => now(),
        'locked_at' => now(),
        'locked_by' => 'worker-1',
    ]);
    $deadLetter = p4PanelDeadLetter($message);

    $this->postJson('/talkto/dead-letters/'.$deadLetter->id.'/reprocess')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Dead letter reprocess job dispatched.')
        ->assertJsonPath('meta.dead_letter_id', $deadLetter->id);

    Queue::assertPushed(SendTalktoMessage::class, fn (SendTalktoMessage $job): bool => $job->talktoMessageId === $message->id);

    $message->refresh();
    $deadLetter->refresh();

    expect($message->overall_status)->toBe('waiting_to_send')
        ->and($message->transport_status)->toBe('pending')
        ->and($message->next_retry_at)->toBeNull()
        ->and($message->next_attempt_at)->toBeNull()
        ->and($message->locked_at)->toBeNull()
        ->and($message->locked_by)->toBeNull()
        ->and($deadLetter->status)->toBe(TalktoDeadLetterQueue::STATUS_REPROCESSING)
        ->and($deadLetter->reprocess_count)->toBe(1)
        ->and(TalktoEvent::query()
            ->where('message_id', $message->message_id)
            ->where('event_type', 'panel_dead_letter_reprocess_dispatched')
            ->where('meta->panel_action', true)
            ->exists())->toBeTrue();
});

test('dead-letter reprocess action dispatches incoming job and prepares incoming message', function (): void {
    ($this->bootPanelActionsApp)();
    Queue::fake();

    $message = p4PanelMessage('panel-dlq-incoming', 'incoming', 'failed_final', [
        'command' => 'website.event-created',
    ]);
    $deadLetter = p4PanelDeadLetter($message);

    $this->postJson('/talkto/dead-letters/'.$deadLetter->id.'/reprocess')
        ->assertOk()
        ->assertJsonPath('meta.direction', 'incoming');

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, fn (ProcessIncomingTalktoMessage $job): bool => $job->talktoMessageId === $message->id);

    $message->refresh();

    expect($message->overall_status)->toBe('queued')
        ->and($message->destination_action_status)->toBe('queued');
});

test('dead-letter reprocess action marks failed reprocess when dispatch fails', function (): void {
    ($this->bootPanelActionsApp)();

    config(['talkto.jobs.send_message' => FailingPanelSendTalktoMessage::class]);
    $message = p4PanelMessage('panel-dlq-dispatch-fails', 'outgoing', 'failed_final');
    $deadLetter = p4PanelDeadLetter($message);

    $this->postJson('/talkto/dead-letters/'.$deadLetter->id.'/reprocess')
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Dead letter reprocess job could not be dispatched.')
        ->assertJsonPath('meta.exception_class', RuntimeException::class);

    $deadLetter->refresh();

    $event = TalktoEvent::query()
        ->where('message_id', $message->message_id)
        ->where('event_type', 'panel_dead_letter_reprocess_dispatch_failed')
        ->first();

    expect($deadLetter->status)->toBe(TalktoDeadLetterQueue::STATUS_FAILED_REPROCESS)
        ->and($deadLetter->failure_reason)->toBe('Dispatch failed.')
        ->and($event)->not->toBeNull()
        ->and($event->meta['panel_action'] ?? null)->toBeTrue()
        ->and($event->meta['exception_class'] ?? null)->toBe(RuntimeException::class)
        ->and($event->meta['exception_message'] ?? null)->toContain('[redacted]')
        ->and($event->meta['exception_message'] ?? null)->not->toContain('outgoing-secret');
});

test('dead-letter reprocess action refuses disabled missing original and terminal states without dispatching', function (): void {
    ($this->bootPanelActionsApp)();
    Queue::fake();

    config(['talkto.panel.actions.dead_letter_reprocess_enabled' => false]);
    $disabledMessage = p4PanelMessage('panel-dlq-disabled', 'outgoing', 'failed_final');
    $disabledDeadLetter = p4PanelDeadLetter($disabledMessage);

    $this->postJson('/talkto/dead-letters/'.$disabledDeadLetter->id.'/reprocess')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Panel dead-letter reprocess action is disabled.');

    config(['talkto.panel.actions.dead_letter_reprocess_enabled' => true]);
    $missingDeadLetter = TalktoDeadLetter::query()->create([
        'talkto_message_id' => null,
        'message_id' => 'missing-original',
        'direction' => 'outgoing',
        'source' => 'panel-test',
        'target' => 'target-alpha',
        'command' => 'domain.sync',
        'failed_status' => 'failed_final',
        'status' => 'open',
    ]);

    $this->postJson('/talkto/dead-letters/'.$missingDeadLetter->id.'/reprocess')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Original message was not found.');

    $succeededMessage = p4PanelMessage('panel-dlq-succeeded', 'outgoing', 'completed');
    $succeededDeadLetter = p4PanelDeadLetter($succeededMessage);

    $this->postJson('/talkto/dead-letters/'.$succeededDeadLetter->id.'/reprocess')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Original message already succeeded.');

    Queue::assertNothingPushed();
    expect(TalktoEvent::query()
        ->where('message_id', 'missing-original')
        ->where('event_type', 'panel_dead_letter_reprocess_missing_original')
        ->exists())->toBeTrue();
});

test('dead-letter reprocess action redirects with flash message for html requests', function (): void {
    ($this->bootPanelActionsApp)();
    Queue::fake();

    $message = p4PanelMessage('panel-dlq-html', 'outgoing', 'failed_final');
    $deadLetter = p4PanelDeadLetter($message);

    $this->from('/talkto/messages/'.$message->message_id)
        ->post('/talkto/dead-letters/'.$deadLetter->id.'/reprocess')
        ->assertRedirect('/talkto/messages/'.$message->message_id)
        ->assertSessionHas('talkto_panel_status', 'Dead letter reprocess job dispatched.');
});

test('trace action returns html and json without exposing payload by default', function (): void {
    ($this->bootPanelActionsApp)();

    $message = p4PanelMessage('panel-trace-hidden', 'outgoing', 'failed_retryable', [
        'payload' => [
            'visible' => '<script>alert("payload")</script>',
            'api_token' => 'top-secret-token',
        ],
    ]);
    TalktoAttempt::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'stage' => 'transport',
        'attempt_no' => 1,
        'status' => 'failed',
        'request_excerpt' => 'Authorization: Bearer top-secret-token',
    ]);
    TalktoEvent::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'service_name' => 'panel-test',
        'event_type' => 'message_failed',
    ]);

    $this->get('/talkto/messages/'.$message->message_id.'/trace')
        ->assertOk()
        ->assertSee('Message Trace')
        ->assertSee('Timeline')
        ->assertSee('Payload is hidden unless panel config allows payload display')
        ->assertDontSee('<script>alert("payload")</script>', false)
        ->assertDontSee('top-secret-token');

    $this->getJson('/talkto/messages/'.$message->message_id.'/trace?payload=1&limit=25')
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('limit', 25)
        ->assertJsonPath('anchor_message.payload.redacted', true);
});

test('trace action shows escaped redacted payload only when explicitly allowed and requested', function (): void {
    ($this->bootPanelActionsApp)();

    config(['talkto.panel.messages.show_payload' => true]);

    $message = p4PanelMessage('panel-trace-payload', 'outgoing', 'failed_retryable', [
        'payload' => [
            'visible' => '<script>alert("payload")</script>',
            'api_token' => 'top-secret-token',
        ],
    ]);

    $this->get('/talkto/messages/'.$message->message_id.'/trace?payload=1')
        ->assertOk()
        ->assertSee('&lt;script&gt;alert', false)
        ->assertSee('[redacted]')
        ->assertDontSee('<script>alert("payload")</script>', false)
        ->assertDontSee('top-secret-token');

    $this->getJson('/talkto/messages/'.$message->message_id.'/trace?payload=1')
        ->assertOk()
        ->assertJsonPath('anchor_message.payload.api_token', '[redacted]');
});

test('message detail renders safe action controls and layout flashes', function (): void {
    ($this->bootPanelActionsApp)();

    $message = p4PanelMessage('panel-actions-detail', 'outgoing', 'failed_final');
    p4PanelDeadLetter($message);

    $this->withSession([
        'talkto_panel_status' => 'Action completed.',
        'talkto_panel_error' => 'Action failed.',
    ])->get('/talkto/messages/'.$message->message_id)
        ->assertOk()
        ->assertSee('Action completed.')
        ->assertSee('Action failed.')
        ->assertSee('Retry now')
        ->assertSee('View trace')
        ->assertSee('Reprocess dead letter');

    config([
        'talkto.panel.actions.retry_enabled' => false,
        'talkto.panel.actions.dead_letter_reprocess_enabled' => false,
    ]);

    $this->get('/talkto/messages/'.$message->message_id)
        ->assertOk()
        ->assertDontSee('Retry now')
        ->assertDontSee('Reprocess dead letter')
        ->assertSee('View trace');
});

function p4PanelUseEnv(array $values = []): void
{
    p4PanelClearEnv();

    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function p4PanelClearEnv(): void
{
    foreach ([
        'TALKTO_PANEL_ENABLED',
        'TALKTO_PANEL_PREFIX',
        'TALKTO_PANEL_DOMAIN',
        'TALKTO_PANEL_ROUTE_NAME',
        'TALKTO_PANEL_AUTHORIZATION_ENABLED',
        'TALKTO_PANEL_GATE',
        'TALKTO_PANEL_MESSAGES_PER_PAGE',
        'TALKTO_PANEL_SHOW_PAYLOAD',
        'TALKTO_PANEL_SHOW_RESPONSE',
        'TALKTO_PANEL_RETRY_ENABLED',
        'TALKTO_PANEL_DLQ_REPROCESS_ENABLED',
        'TALKTO_ROUTES_ENABLED',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

function p4PanelMessage(string $messageId, string $direction, string $status, array $attributes = []): TalktoMessage
{
    $createdAt = $attributes['created_at'] ?? now();

    $message = TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'corr-'.$messageId,
        'direction' => $direction,
        'source_service' => $direction === 'incoming' ? 'source-alpha' : 'panel-test',
        'target_service' => $direction === 'outgoing' ? 'target-alpha' : 'panel-test',
        'command' => $direction === 'incoming' ? 'website.event-created' : 'domain.sync',
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
        'next_attempt_at' => null,
        'completed_at' => in_array($status, ['completed', 'succeeded'], true) ? $createdAt : null,
        'failed_at' => str_starts_with($status, 'failed') ? $createdAt : null,
    ], array_diff_key($attributes, array_flip(['created_at', 'updated_at']))));

    $message->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $attributes['updated_at'] ?? $createdAt,
    ])->save();

    return $message->fresh();
}

function p4PanelDeadLetter(TalktoMessage $message, array $attributes = []): TalktoDeadLetter
{
    return TalktoDeadLetter::query()->create(array_merge([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'direction' => $message->direction,
        'source' => $message->source_service,
        'target' => $message->target_service,
        'command' => $message->command,
        'payload' => $message->payload,
        'headers' => [],
        'failed_status' => 'failed_final',
        'status' => TalktoDeadLetterQueue::STATUS_OPEN,
    ], $attributes));
}
