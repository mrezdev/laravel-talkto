<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

beforeEach(function (): void {
    $this->bootPanelCallbackStatusApp = function (array $env = []): void {
        p52PanelUseEnv(array_merge(['TALKTO_PANEL_ENABLED' => 'true'], $env));

        $this->refreshApplication();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        expect($this->artisan('migrate')->run())->toBe(0);

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'talkto.panel.authorization.enabled' => false,
            'talkto.service' => 'panel-test',
            'talkto.callbacks.command' => 'talkto.result',
            'talkto.outgoing.target-alpha' => [
                'base_url' => 'https://target-alpha.test',
                'secret' => 'panel-callback-secret',
                'receive_endpoint' => '/api/talkto/receive',
                'callback_endpoint' => '/api/talkto/callback',
            ],
            'talkto.incoming.source-alpha' => [
                'secret' => 'panel-callback-secret',
                'allowed_commands' => [
                    'website.event-created' => ['driver' => 'none'],
                    'talkto.result' => ['driver' => 'none'],
                ],
            ],
        ]);

        View::replaceNamespace('talkto', realpath(__DIR__.'/../../resources/views'));
        $this->withoutMiddleware();
    };
});

afterEach(function (): void {
    p52PanelClearEnv();
});

test('message detail page shows check callback button for applicable destination incoming message', function (): void {
    ($this->bootPanelCallbackStatusApp)();

    $incoming = p52IncomingMessage('panel-callback-incoming-completed', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);
    p52CallbackMessage('panel-callback-outgoing-completed', $incoming, [
        'transport_status' => 'sent',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'applied',
        'overall_status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->get('/talkto/messages/'.$incoming->message_id)
        ->assertOk()
        ->assertSee('Check Callback')
        ->assertSee('/talkto/messages/'.$incoming->message_id.'/callback-status', false);
});

test('message detail page hides check callback button for early outgoing message', function (): void {
    ($this->bootPanelCallbackStatusApp)();

    $message = p52OutgoingMessage('panel-callback-early-outgoing', [
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
    ]);

    $this->get('/talkto/messages/'.$message->message_id)
        ->assertOk()
        ->assertDontSee('Check Callback')
        ->assertDontSee('/talkto/messages/'.$message->message_id.'/callback-status', false);
});

test('callback status page renders completed callback details', function (): void {
    ($this->bootPanelCallbackStatusApp)();

    $incoming = p52IncomingMessage('panel-callback-status-incoming', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);
    $callback = p52CallbackMessage('panel-callback-status-callback', $incoming, [
        'transport_status' => 'sent',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'applied',
        'overall_status' => 'completed',
        'last_http_status' => 200,
        'completed_at' => now(),
    ]);
    p52Attempt($callback, [
        'status' => 'sent',
        'http_status' => 200,
    ]);
    p52Event($incoming, 'result_callback_queued', [
        'callback_message_id' => $callback->message_id,
    ]);

    $this->get('/talkto/messages/'.$incoming->message_id.'/callback-status')
        ->assertOk()
        ->assertSee('Callback Status')
        ->assertSee('Callback completed')
        ->assertSee($callback->message_id)
        ->assertSee('Back to message')
        ->assertSee('/talkto/messages/'.$incoming->message_id, false);
});

test('callback status page renders missing callback state', function (): void {
    ($this->bootPanelCallbackStatusApp)();

    $incoming = p52IncomingMessage('panel-callback-status-missing', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);

    $this->get('/talkto/messages/'.$incoming->message_id.'/callback-status')
        ->assertOk()
        ->assertSee('Callback message missing')
        ->assertSee('No durable callback message');
});

test('callback status json endpoint returns inspector payload', function (): void {
    ($this->bootPanelCallbackStatusApp)();

    $incoming = p52IncomingMessage('panel-callback-json-incoming', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);
    p52CallbackMessage('panel-callback-json-callback', $incoming, [
        'overall_status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->getJson('/talkto/messages/'.$incoming->message_id.'/callback-status')
        ->assertOk()
        ->assertJsonPath('callback_status.applicable', true)
        ->assertJsonPath('callback_status.context', 'destination_incoming')
        ->assertJsonPath('callback_status.state', 'callback_completed');
});

test('message show json includes callback status without exposing callback secrets', function (): void {
    ($this->bootPanelCallbackStatusApp)();

    $incoming = p52IncomingMessage('panel-callback-show-json-incoming', [
        'destination_action_status' => 'failed_retryable',
        'overall_status' => 'failed_retryable',
        'failed_at' => now(),
    ]);
    p52CallbackMessage('panel-callback-show-json-callback', $incoming, [
        'overall_status' => 'failed_retryable',
        'last_error' => 'Transport failed with panel-callback-secret',
        'failed_at' => now(),
    ]);

    $response = $this->getJson('/talkto/messages/'.$incoming->message_id)
        ->assertOk()
        ->assertJsonPath('callback_status.applicable', true)
        ->assertJsonPath('callback_status.state', 'callback_failed_retryable');

    expect($response->getContent())->not->toContain('panel-callback-secret')
        ->and($response->getContent())->toContain('[redacted]');
});

test('outgoing callback message shows action button and status page includes parent', function (): void {
    ($this->bootPanelCallbackStatusApp)();

    $incoming = p52IncomingMessage('panel-callback-parent-incoming', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);
    $callback = p52CallbackMessage('panel-callback-self-message', $incoming, [
        'transport_status' => 'sent',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'applied',
        'overall_status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->get('/talkto/messages/'.$callback->message_id)
        ->assertOk()
        ->assertSee('Check Callback');

    $this->get('/talkto/messages/'.$callback->message_id.'/callback-status')
        ->assertOk()
        ->assertSee('outgoing_callback')
        ->assertSee($incoming->message_id)
        ->assertSee('Open parent message');
});

test('callback status route authorization remains enforced', function (): void {
    ($this->bootPanelCallbackStatusApp)();

    config(['talkto.panel.authorization.enabled' => true]);

    $incoming = p52IncomingMessage('panel-callback-auth-incoming', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);

    $this->getJson('/talkto/messages/'.$incoming->message_id.'/callback-status')
        ->assertForbidden();
});

test('callback status route does not mutate callback storage or dispatch jobs', function (): void {
    ($this->bootPanelCallbackStatusApp)();
    Queue::fake();

    $incoming = p52IncomingMessage('panel-callback-read-only-incoming', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'completed_at' => now(),
    ]);
    p52CallbackMessage('panel-callback-read-only-callback', $incoming, [
        'overall_status' => 'completed',
        'completed_at' => now(),
    ]);

    $before = [
        TalktoMessage::query()->count(),
        TalktoEvent::query()->count(),
        TalktoAttempt::query()->count(),
        TalktoDeadLetter::query()->count(),
    ];

    $this->get('/talkto/messages/'.$incoming->message_id.'/callback-status')->assertOk();

    $after = [
        TalktoMessage::query()->count(),
        TalktoEvent::query()->count(),
        TalktoAttempt::query()->count(),
        TalktoDeadLetter::query()->count(),
    ];

    expect($after)->toBe($before);
    Queue::assertNothingPushed();
});

test('callback status page and json do not expose configured secrets', function (): void {
    ($this->bootPanelCallbackStatusApp)();

    $incoming = p52IncomingMessage('panel-callback-redacted-incoming', [
        'destination_action_status' => 'failed_retryable',
        'overall_status' => 'failed_retryable',
        'failed_at' => now(),
    ]);
    p52CallbackMessage('panel-callback-redacted-callback', $incoming, [
        'overall_status' => 'failed_retryable',
        'last_error' => 'Rejected with panel-callback-secret',
        'failed_at' => now(),
    ]);

    $this->get('/talkto/messages/'.$incoming->message_id.'/callback-status')
        ->assertOk()
        ->assertSee('[redacted]')
        ->assertDontSee('panel-callback-secret');

    $response = $this->getJson('/talkto/messages/'.$incoming->message_id.'/callback-status')
        ->assertOk();

    expect($response->getContent())->not->toContain('panel-callback-secret')
        ->and($response->getContent())->toContain('[redacted]');
});

function p52PanelUseEnv(array $values = []): void
{
    p52PanelClearEnv();

    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function p52PanelClearEnv(): void
{
    foreach ([
        'TALKTO_PANEL_ENABLED',
        'TALKTO_PANEL_PREFIX',
        'TALKTO_PANEL_DOMAIN',
        'TALKTO_PANEL_ROUTE_NAME',
        'TALKTO_PANEL_AUTHORIZATION_ENABLED',
        'TALKTO_PANEL_GATE',
        'TALKTO_ROUTES_ENABLED',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

function p52IncomingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    return p52Message($messageId, array_merge([
        'direction' => 'incoming',
        'source_service' => 'source-alpha',
        'target_service' => 'panel-test',
        'command' => 'website.event-created',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'queued',
        'received_at' => now(),
    ], $attributes));
}

function p52OutgoingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    return p52Message($messageId, array_merge([
        'direction' => 'outgoing',
        'source_service' => 'panel-test',
        'target_service' => 'target-alpha',
        'command' => 'website.event-created',
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
    ], $attributes));
}

function p52CallbackMessage(string $messageId, TalktoMessage $incoming, array $attributes = []): TalktoMessage
{
    return p52Message($messageId, array_merge([
        'parent_message_id' => $incoming->message_id,
        'direction' => 'outgoing',
        'source_service' => 'panel-test',
        'target_service' => 'source-alpha',
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

function p52Message(string $messageId, array $attributes = []): TalktoMessage
{
    $payload = $attributes['payload'] ?? ['id' => $messageId];

    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'corr-'.$messageId,
        'business_key' => null,
        'idempotency_key' => null,
        'payload' => $payload,
        'payload_hash' => 'hash-'.$messageId,
        'schema_version' => 1,
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 3,
    ], $attributes));
}

function p52Attempt(TalktoMessage $message, array $attributes = []): TalktoAttempt
{
    return TalktoAttempt::query()->create(array_merge([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'stage' => 'transport',
        'attempt_no' => 1,
        'status' => 'sent',
    ], $attributes));
}

function p52Event(TalktoMessage $message, string $eventType, array $meta = []): TalktoEvent
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
