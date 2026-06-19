<?php

use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

beforeEach(function (): void {
    $this->bootPanelApp = function (array $env = []): void {
        p2PanelUseEnv($env);

        $this->refreshApplication();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        expect($this->artisan('migrate')->run())->toBe(0);

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'talkto.service' => 'panel-test',
            'talkto.outgoing' => [],
            'talkto.incoming' => [
                'handlers' => [],
                'unknown_command_strategy' => 'fail',
            ],
        ]);
    };
});

afterEach(function (): void {
    p2PanelClearEnv();
});

test('panel routes are not registered when panel is disabled', function (): void {
    ($this->bootPanelApp)();

    expect(Route::has('talkto.panel.index'))->toBeFalse();

    $this->getJson('/talkto')->assertNotFound();
});

test('panel routes are registered and accessible when enabled', function (): void {
    ($this->bootPanelApp)(['TALKTO_PANEL_ENABLED' => 'true']);

    config(['talkto.panel.authorization.enabled' => false]);
    $this->withoutMiddleware();

    expect(Route::has('talkto.panel.index'))->toBeTrue()
        ->and(Route::has('talkto.panel.messages.index'))->toBeTrue()
        ->and(Route::has('talkto.panel.messages.show'))->toBeTrue()
        ->and(Route::has('talkto.panel.connections.index'))->toBeTrue();

    $this->getJson('/talkto')
        ->assertOk()
        ->assertJsonStructure(['latest_messages', 'connections_health']);
});

test('panel route custom prefix works', function (): void {
    ($this->bootPanelApp)([
        'TALKTO_PANEL_ENABLED' => 'true',
        'TALKTO_PANEL_PREFIX' => 'ops/talkto',
    ]);

    config(['talkto.panel.authorization.enabled' => false]);
    $this->withoutMiddleware();

    $this->getJson('/ops/talkto')->assertOk();
    $this->getJson('/talkto')->assertNotFound();
});

test('panel routes are independent from existing api route loading', function (): void {
    ($this->bootPanelApp)(['TALKTO_PANEL_ENABLED' => 'true']);

    expect(Route::has('talkto.panel.index'))->toBeTrue()
        ->and(Route::has('talkto.receive'))->toBeFalse();

    ($this->bootPanelApp)(['TALKTO_ROUTES_ENABLED' => 'true']);

    expect(Route::has('talkto.panel.index'))->toBeFalse()
        ->and(Route::has('talkto.receive'))->toBeTrue();
});

test('panel authorization denies access when enabled and gate does not allow', function (): void {
    ($this->bootPanelApp)(['TALKTO_PANEL_ENABLED' => 'true']);

    $this->withoutMiddleware();

    $this->getJson('/talkto')->assertForbidden();
});

test('panel authorization allows access when gate allows', function (): void {
    ($this->bootPanelApp)(['TALKTO_PANEL_ENABLED' => 'true']);

    $this->withoutMiddleware();
    Gate::define('viewTalktoPanel', fn (AuthenticatableUser $user): bool => true);
    $user = new class extends AuthenticatableUser {};
    $user->id = 1;
    $this->actingAs($user);

    $this->getJson('/talkto')->assertOk();
});

test('panel authorization can be disabled', function (): void {
    ($this->bootPanelApp)(['TALKTO_PANEL_ENABLED' => 'true']);

    config(['talkto.panel.authorization.enabled' => false]);
    $this->withoutMiddleware();

    $this->getJson('/talkto')->assertOk();
});

test('panel route middleware is respected', function (): void {
    ($this->bootPanelApp)(['TALKTO_PANEL_ENABLED' => 'true']);

    Gate::before(fn (...$arguments): bool => true);

    $this->getJson('/talkto')->assertUnauthorized();
});

test('panel index returns latest messages and connection health json', function (): void {
    ($this->bootPanelApp)(['TALKTO_PANEL_ENABLED' => 'true']);

    config([
        'talkto.panel.authorization.enabled' => false,
        'talkto.outgoing.target-alpha' => [
            'url' => 'https://target-alpha.test',
            'secret' => 'outgoing-secret',
            'endpoint' => '/api/talkto/receive',
        ],
    ]);
    $this->withoutMiddleware();

    p2PanelMessage('panel-dashboard-old', 'outgoing', 'completed', [
        'target_service' => 'target-alpha',
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);
    p2PanelMessage('panel-dashboard-new', 'outgoing', 'completed', [
        'target_service' => 'target-alpha',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $this->getJson('/talkto')
        ->assertOk()
        ->assertJsonPath('latest_messages.0.message_id', 'panel-dashboard-new')
        ->assertJsonPath('connections_health.0.connection.service', 'target-alpha')
        ->assertJsonPath('connections_health.0.status', 'healthy');
});

test('panel messages index returns paginated json and accepts filters', function (): void {
    ($this->bootPanelApp)(['TALKTO_PANEL_ENABLED' => 'true']);

    config([
        'talkto.panel.authorization.enabled' => false,
        'talkto.panel.messages.per_page' => 1,
    ]);
    $this->withoutMiddleware();

    p2PanelMessage('panel-message-out', 'outgoing', 'completed', [
        'target_service' => 'target-alpha',
        'command' => 'domain.sync',
    ]);
    p2PanelMessage('panel-message-in', 'incoming', 'completed', [
        'source_service' => 'source-alpha',
        'command' => 'website.event-created',
    ]);

    $this->getJson('/talkto/messages?direction=outgoing&service=target-alpha&command=domain.sync')
        ->assertOk()
        ->assertJsonPath('filters.direction', 'outgoing')
        ->assertJsonPath('filters.service', 'target-alpha')
        ->assertJsonPath('messages.total', 1)
        ->assertJsonPath('messages.data.0.message_id', 'panel-message-out');
});

test('panel message detail returns message attempts events and dead letter', function (): void {
    ($this->bootPanelApp)(['TALKTO_PANEL_ENABLED' => 'true']);

    config(['talkto.panel.authorization.enabled' => false]);
    $this->withoutMiddleware();

    $message = p2PanelMessage('panel-detail', 'outgoing', 'failed_final', [
        'failed_at' => now()->subMinute(),
    ]);
    TalktoAttempt::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'stage' => 'transport',
        'attempt_no' => 1,
        'status' => 'failed',
    ]);
    TalktoEvent::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'service_name' => 'panel-test',
        'event_type' => 'message_failed',
    ]);
    p2PanelDeadLetter($message);

    $this->getJson('/talkto/messages/panel-detail')
        ->assertOk()
        ->assertJsonPath('message.message_id', 'panel-detail')
        ->assertJsonPath('attempts.0.status', 'failed')
        ->assertJsonPath('events.0.event_type', 'message_failed')
        ->assertJsonPath('dead_letter.message_id', 'panel-detail');
});

test('panel message detail returns not found for missing message', function (): void {
    ($this->bootPanelApp)(['TALKTO_PANEL_ENABLED' => 'true']);

    config(['talkto.panel.authorization.enabled' => false]);
    $this->withoutMiddleware();

    $this->getJson('/talkto/messages/missing-message')->assertNotFound();
});

test('panel connections index returns outgoing incoming and health json', function (): void {
    ($this->bootPanelApp)(['TALKTO_PANEL_ENABLED' => 'true']);

    config([
        'talkto.panel.authorization.enabled' => false,
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

    p2PanelMessage('panel-connection-in', 'incoming', 'completed', [
        'source_service' => 'source-alpha',
        'command' => 'website.event-created',
    ]);

    $this->getJson('/talkto/connections')
        ->assertOk()
        ->assertJsonPath('outgoing.0.service', 'target-alpha')
        ->assertJsonPath('incoming.0.service', 'source-alpha')
        ->assertJsonPath('incoming.0.commands.0', 'website.event-created')
        ->assertJsonPath('health.0.connection.service', 'target-alpha')
        ->assertJsonPath('health.1.connection.service', 'source-alpha');
});

function p2PanelUseEnv(array $values = []): void
{
    p2PanelClearEnv();

    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function p2PanelClearEnv(): void
{
    foreach ([
        'TALKTO_PANEL_ENABLED',
        'TALKTO_PANEL_PREFIX',
        'TALKTO_PANEL_DOMAIN',
        'TALKTO_PANEL_ROUTE_NAME',
        'TALKTO_PANEL_AUTHORIZATION_ENABLED',
        'TALKTO_PANEL_GATE',
        'TALKTO_PANEL_MESSAGES_PER_PAGE',
        'TALKTO_PANEL_HEALTH_WINDOW_MINUTES',
        'TALKTO_ROUTES_ENABLED',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

function p2PanelMessage(string $messageId, string $direction, string $status, array $attributes = []): TalktoMessage
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
    ], array_diff_key($attributes, array_flip(['created_at', 'updated_at']))));

    $message->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $attributes['updated_at'] ?? $createdAt,
    ])->save();

    return $message->fresh();
}

function p2PanelDeadLetter(TalktoMessage $message): TalktoDeadLetter
{
    return TalktoDeadLetter::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'direction' => $message->direction,
        'source' => $message->source_service,
        'target' => $message->target_service,
        'command' => $message->command,
        'failed_status' => 'failed_final',
        'status' => 'open',
    ]);
}
