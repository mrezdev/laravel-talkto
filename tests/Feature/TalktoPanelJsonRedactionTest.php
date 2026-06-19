<?php

use Illuminate\Support\Facades\View;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

beforeEach(function (): void {
    $this->bootPanelJsonRedactionApp = function (array $env = []): void {
        panelJsonUseEnv(array_merge(['TALKTO_PANEL_ENABLED' => 'true'], $env));

        $this->refreshApplication();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        expect($this->artisan('migrate')->run())->toBe(0);

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'talkto.panel.authorization.enabled' => false,
            'talkto.service' => 'panel-test',
            'talkto.outgoing.target-alpha' => [
                'url' => 'https://target-alpha.test',
                'secret' => 'outgoing-secret-value',
                'endpoint' => '/api/talkto/receive',
                'health' => [
                    'url' => 'https://target-alpha.test/health?api_token=health-token&signature=health-signature&plain=yes',
                    'method' => 'GET',
                ],
            ],
            'talkto.incoming.source-alpha' => [
                'secret' => 'incoming-secret-value',
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
    panelJsonClearEnv();
});

test('message detail json hides payload and response by default', function (): void {
    ($this->bootPanelJsonRedactionApp)();

    $message = panelJsonMessage('json-detail-hidden', 'outgoing', 'completed', [
        'payload' => panelJsonSensitivePayload(),
        'last_response' => json_encode(panelJsonSensitiveResponse()),
    ]);
    panelJsonDeadLetter($message);

    $response = $this->getJson('/talkto/messages/'.$message->message_id)
        ->assertOk()
        ->assertJsonPath('message.payload.redacted', true)
        ->assertJsonPath('message.last_response', '[redacted]')
        ->assertJsonPath('dead_letter.payload.redacted', true);

    expect($response->getContent())->not->toContain('payload-secret')
        ->and($response->getContent())->not->toContain('response-secret')
        ->and($response->getContent())->not->toContain('outgoing-secret-value')
        ->and($response->getContent())->not->toContain('incoming-secret-value');
});

test('message detail json shows redacted payload and response only when enabled', function (): void {
    ($this->bootPanelJsonRedactionApp)();

    config([
        'talkto.panel.messages.show_payload' => true,
        'talkto.panel.messages.show_response' => true,
    ]);

    $message = panelJsonMessage('json-detail-visible', 'outgoing', 'completed', [
        'payload' => panelJsonSensitivePayload(),
        'last_response' => json_encode(panelJsonSensitiveResponse()),
    ]);

    $response = $this->getJson('/talkto/messages/'.$message->message_id)
        ->assertOk()
        ->assertJsonPath('message.payload.visible', 'safe-payload')
        ->assertJsonPath('message.payload.secret', '[redacted]')
        ->assertJsonPath('message.payload.nested.access_token', '[redacted]')
        ->assertJsonPath('message.last_response.visible', 'safe-response')
        ->assertJsonPath('message.last_response.token', '[redacted]')
        ->assertJsonPath('message.last_response.nested.refresh_token', '[redacted]');

    expect($response->getContent())->not->toContain('payload-secret')
        ->and($response->getContent())->not->toContain('access-token-value')
        ->and($response->getContent())->not->toContain('response-secret')
        ->and($response->getContent())->not->toContain('refresh-token-value');
});

test('dashboard and messages index json hide payload by default', function (): void {
    ($this->bootPanelJsonRedactionApp)();

    $message = panelJsonMessage('json-list-hidden', 'outgoing', 'completed', [
        'payload' => panelJsonSensitivePayload(),
        'last_response' => json_encode(panelJsonSensitiveResponse()),
    ]);

    $dashboard = $this->getJson('/talkto')
        ->assertOk()
        ->assertJsonPath('latest_messages.0.message_id', $message->message_id)
        ->assertJsonPath('latest_messages.0.payload.redacted', true)
        ->assertJsonPath('latest_messages.0.last_response', '[redacted]');

    $index = $this->getJson('/talkto/messages')
        ->assertOk()
        ->assertJsonPath('messages.data.0.message_id', $message->message_id)
        ->assertJsonPath('messages.data.0.payload.redacted', true)
        ->assertJsonPath('messages.data.0.last_response', '[redacted]');

    expect($dashboard->getContent())->not->toContain('payload-secret')
        ->and($index->getContent())->not->toContain('payload-secret')
        ->and($dashboard->getContent())->not->toContain('response-secret')
        ->and($index->getContent())->not->toContain('response-secret');
});

test('trace json hides payload by default and shows redacted payload only when allowed', function (): void {
    ($this->bootPanelJsonRedactionApp)();

    $message = panelJsonMessage('json-trace', 'outgoing', 'failed_retryable', [
        'payload' => panelJsonSensitivePayload(),
    ]);

    $hidden = $this->getJson('/talkto/messages/'.$message->message_id.'/trace?payload=1')
        ->assertOk()
        ->assertJsonPath('anchor_message.payload.redacted', true);

    expect($hidden->getContent())->not->toContain('payload-secret')
        ->and($hidden->getContent())->not->toContain('access-token-value');

    config(['talkto.panel.messages.show_payload' => true]);

    $visible = $this->getJson('/talkto/messages/'.$message->message_id.'/trace?payload=1')
        ->assertOk()
        ->assertJsonPath('anchor_message.payload.visible', 'safe-payload')
        ->assertJsonPath('anchor_message.payload.secret', '[redacted]')
        ->assertJsonPath('anchor_message.payload.nested.access_token', '[redacted]');

    expect($visible->getContent())->not->toContain('payload-secret')
        ->and($visible->getContent())->not->toContain('access-token-value');
});

test('connections json redacts health url query values and never exposes configured secrets', function (): void {
    ($this->bootPanelJsonRedactionApp)();

    $response = $this->getJson('/talkto/connections')
        ->assertOk()
        ->assertJsonPath('outgoing.0.active_health_url', 'https://target-alpha.test/health?api_token=[redacted]&signature=[redacted]&plain=yes');

    expect($response->getContent())->not->toContain('health-token')
        ->and($response->getContent())->not->toContain('health-signature')
        ->and($response->getContent())->not->toContain('outgoing-secret-value')
        ->and($response->getContent())->not->toContain('incoming-secret-value');
});

function panelJsonUseEnv(array $values = []): void
{
    panelJsonClearEnv();

    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function panelJsonClearEnv(): void
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
        'TALKTO_PANEL_ACTIVE_HEALTH_CHECKS_ENABLED',
        'TALKTO_ROUTES_ENABLED',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

function panelJsonMessage(string $messageId, string $direction, string $status, array $attributes = []): TalktoMessage
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
        'completed_at' => in_array($status, ['completed', 'succeeded'], true) ? $createdAt : null,
        'failed_at' => str_starts_with($status, 'failed') ? $createdAt : null,
    ], array_diff_key($attributes, array_flip(['created_at', 'updated_at']))));

    $message->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $attributes['updated_at'] ?? $createdAt,
    ])->save();

    return $message->fresh();
}

function panelJsonDeadLetter(TalktoMessage $message): TalktoDeadLetter
{
    return TalktoDeadLetter::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'direction' => $message->direction,
        'source' => $message->source_service,
        'target' => $message->target_service,
        'command' => $message->command,
        'payload' => $message->payload,
        'headers' => ['authorization' => 'Bearer dead-letter-secret'],
        'failed_status' => 'failed_final',
        'status' => 'open',
    ]);
}

function panelJsonSensitivePayload(): array
{
    return [
        'visible' => 'safe-payload',
        'secret' => 'payload-secret',
        'nested' => [
            'access_token' => 'access-token-value',
            'safe' => 'nested-safe',
        ],
    ];
}

function panelJsonSensitiveResponse(): array
{
    return [
        'visible' => 'safe-response',
        'token' => 'response-secret',
        'nested' => [
            'refresh_token' => 'refresh-token-value',
            'safe' => 'nested-safe-response',
        ],
    ];
}
