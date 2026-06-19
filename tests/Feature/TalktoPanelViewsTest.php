<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\View;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

beforeEach(function (): void {
    $this->bootPanelViewsApp = function (array $env = []): void {
        p3PanelUseEnv(array_merge(['TALKTO_PANEL_ENABLED' => 'true'], $env));

        $this->refreshApplication();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        expect($this->artisan('migrate')->run())->toBe(0);

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'talkto.panel.authorization.enabled' => false,
            'talkto.service' => 'panel-test',
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

        $this->withoutMiddleware();
    };
});

afterEach(function (): void {
    p3PanelClearEnv();
});

test('panel dashboard returns html and keeps json mode', function (): void {
    ($this->bootPanelViewsApp)();

    p3PanelMessage('views-dashboard-message', 'outgoing', 'completed', [
        'target_service' => 'target-alpha',
        'completed_at' => now()->subMinute(),
    ]);

    $this->get('/talkto')
        ->assertOk()
        ->assertSee('Panel Dashboard')
        ->assertSee('Latest Talkto Messages')
        ->assertSee('Connections Health')
        ->assertDontSee('https://cdn.tailwindcss.com', false);

    $this->getJson('/talkto')
        ->assertOk()
        ->assertJsonStructure(['latest_messages', 'connections_health']);
});

test('messages index and connections index return html', function (): void {
    ($this->bootPanelViewsApp)();

    p3PanelMessage('views-index-message', 'incoming', 'completed', [
        'source_service' => 'source-alpha',
        'command' => 'website.event-created',
    ]);

    $this->get('/talkto/messages')
        ->assertOk()
        ->assertSee('Latest Talkto Messages')
        ->assertSee('Filter local incoming and outgoing message records')
        ->assertSee('views-index-message');

    $this->get('/talkto/connections')
        ->assertOk()
        ->assertSee('Connections')
        ->assertSee('Outgoing connections')
        ->assertSee('Incoming connections')
        ->assertSee('Health')
        ->assertSee('unknown');
});

test('message detail returns html and hides payload and response by default', function (): void {
    ($this->bootPanelViewsApp)();

    p3PanelMessage('views-detail-message', 'outgoing', 'completed', [
        'payload' => ['visible' => '<script>alert("payload")</script>'],
        'last_response' => '<script>alert("response")</script>',
    ]);

    $this->get('/talkto/messages/views-detail-message')
        ->assertOk()
        ->assertSee('Message Detail')
        ->assertSee('Payload is hidden by panel config')
        ->assertSee('Response is hidden by panel config')
        ->assertDontSee('<script>alert("payload")</script>', false)
        ->assertDontSee('<script>alert("response")</script>', false);
});

test('message detail shows escaped payload and response only when enabled', function (): void {
    ($this->bootPanelViewsApp)();

    config([
        'talkto.panel.messages.show_payload' => true,
        'talkto.panel.messages.show_response' => true,
    ]);

    p3PanelMessage('views-visible-payload', 'outgoing', 'completed', [
        'payload' => ['visible' => '<script>alert("payload")</script>'],
        'last_response' => '<script>alert("response")</script>',
    ]);

    $this->get('/talkto/messages/views-visible-payload')
        ->assertOk()
        ->assertSee('&lt;script&gt;alert', false)
        ->assertSee('payload')
        ->assertSee('response')
        ->assertDontSee('<script>alert("payload")</script>', false)
        ->assertDontSee('<script>alert("response")</script>', false);
});

test('tailwind cdn is opt in only', function (): void {
    ($this->bootPanelViewsApp)();

    $this->get('/talkto')
        ->assertOk()
        ->assertDontSee('https://cdn.tailwindcss.com', false);

    config(['talkto.panel.views.tailwind_cdn' => true]);

    $this->get('/talkto')
        ->assertOk()
        ->assertSee('https://cdn.tailwindcss.com', false);
});

test('panel views are namespaced and publishable', function (): void {
    ($this->bootPanelViewsApp)();

    expect(View::exists('talkto::panel.layout'))->toBeTrue()
        ->and(View::exists('talkto::panel.index'))->toBeTrue()
        ->and(View::exists('talkto::panel.messages.index'))->toBeTrue()
        ->and(View::exists('talkto::panel.messages.show'))->toBeTrue()
        ->and(View::exists('talkto::panel.connections.index'))->toBeTrue()
        ->and(View::exists('talkto::panel.partials.active-health-badge'))->toBeTrue();

    expect(Artisan::call('vendor:publish', [
        '--tag' => 'talkto-panel-views',
        '--force' => true,
    ]))->toBe(0);
});

test('panel layout can be customized through config', function (): void {
    ($this->bootPanelViewsApp)();

    View::addNamespace('panel-test', __DIR__.'/../Fixtures/views');
    config(['talkto.panel.views.layout' => 'panel-test::custom-panel-layout']);

    $this->get('/talkto')
        ->assertOk()
        ->assertSee('Custom Panel Layout');
});

function p3PanelUseEnv(array $values = []): void
{
    p3PanelClearEnv();

    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function p3PanelClearEnv(): void
{
    foreach ([
        'TALKTO_PANEL_ENABLED',
        'TALKTO_PANEL_PREFIX',
        'TALKTO_PANEL_DOMAIN',
        'TALKTO_PANEL_ROUTE_NAME',
        'TALKTO_PANEL_TAILWIND_CDN',
        'TALKTO_ROUTES_ENABLED',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

function p3PanelMessage(string $messageId, string $direction, string $status, array $attributes = []): TalktoMessage
{
    $createdAt = $attributes['created_at'] ?? now();

    $message = TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'corr-'.$messageId,
        'direction' => $direction,
        'source_service' => $direction === 'incoming' ? 'source-alpha' : 'panel-test',
        'target_service' => $direction === 'outgoing' ? 'target-alpha' : 'panel-test',
        'command' => 'domain.sync',
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
        'last_response' => null,
    ], array_diff_key($attributes, array_flip(['created_at', 'updated_at']))));

    $message->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $attributes['updated_at'] ?? $createdAt,
    ])->save();

    return $message->fresh();
}
