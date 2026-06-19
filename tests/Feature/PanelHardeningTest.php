<?php

use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;

beforeEach(function (): void {
    $this->bootPanelHardeningApp = function (array $env = []): void {
        panelHardeningUseEnv(array_merge(['TALKTO_PANEL_ENABLED' => 'true'], $env));

        $this->refreshApplication();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        expect($this->artisan('migrate')->run())->toBe(0);

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'talkto.panel.authorization.enabled' => false,
            'talkto.service' => 'panel-test',
            'talkto.retry.enabled' => true,
            'talkto.retry.outgoing_enabled' => true,
            'talkto.retry.retryable_statuses' => ['failed_retryable'],
            'talkto.outgoing.target-alpha' => [
                'url' => 'https://target-alpha.test',
                'secret' => 'outgoing-secret',
                'endpoint' => '/api/talkto/receive',
            ],
        ]);

        View::replaceNamespace('talkto', realpath(__DIR__.'/../../resources/views'));
        $this->withoutMiddleware();
    };
});

afterEach(function (): void {
    panelHardeningClearEnv();
});

test('panel remains disabled by default and routes load only when explicitly enabled', function (): void {
    panelHardeningUseEnv();
    $this->refreshApplication();

    expect(config('talkto.panel.enabled'))->toBeFalse()
        ->and(Route::has('talkto.panel.index'))->toBeFalse();

    panelHardeningUseEnv(['TALKTO_PANEL_ENABLED' => 'true']);
    $this->refreshApplication();

    expect(Route::has('talkto.panel.index'))->toBeTrue();
});

test('panel list query omits heavy sensitive columns and list output stays redacted', function (): void {
    ($this->bootPanelHardeningApp)();

    $message = panelHardeningMessage('panel-list-safe', 'outgoing', 'completed', [
        'payload' => [
            'visible' => 'safe-payload',
            'secret' => 'payload-secret',
        ],
        'last_error' => 'transport failed with payload-secret',
        'last_response' => json_encode([
            'visible' => 'safe-response',
            'token' => 'response-secret',
        ]),
    ]);

    $row = app(TalktoPanelMessageQuery::class)->latest(1)->first();

    expect($row)->not->toBeNull()
        ->and(array_key_exists('payload', $row->getAttributes()))->toBeFalse()
        ->and(array_key_exists('last_response', $row->getAttributes()))->toBeFalse()
        ->and(array_key_exists('last_error', $row->getAttributes()))->toBeFalse();

    $response = $this->getJson('/talkto/messages')
        ->assertOk()
        ->assertJsonPath('messages.data.0.message_id', $message->message_id)
        ->assertJsonPath('messages.data.0.payload.redacted', true)
        ->assertJsonPath('messages.data.0.last_response', '[redacted]');

    expect($response->getContent())->not->toContain('payload-secret')
        ->and($response->getContent())->not->toContain('response-secret')
        ->and($response->getContent())->not->toContain('transport failed');
});

test('panel detail html redacts sensitive payload and response values when visibility is enabled', function (): void {
    ($this->bootPanelHardeningApp)();

    config([
        'talkto.panel.messages.show_payload' => true,
        'talkto.panel.messages.show_response' => true,
    ]);

    $message = panelHardeningMessage('panel-detail-redacted', 'outgoing', 'completed', [
        'payload' => [
            'visible' => 'safe-payload',
            'cookie' => 'session-cookie-secret',
            'x-api-key' => 'payload-api-key-secret',
        ],
        'last_response' => json_encode([
            'visible' => 'safe-response',
            'x-talkto-signature' => 'response-signature-secret',
            'nested' => [
                'password' => 'response-password-secret',
            ],
        ]),
    ]);

    $this->get('/talkto/messages/'.$message->message_id)
        ->assertOk()
        ->assertSee('safe-payload')
        ->assertSee('safe-response')
        ->assertSee('[redacted]')
        ->assertDontSee('session-cookie-secret')
        ->assertDontSee('payload-api-key-secret')
        ->assertDontSee('response-signature-secret')
        ->assertDontSee('response-password-secret');
});

test('panel post action routes keep configured middleware and work behind auth', function (): void {
    ($this->bootPanelHardeningApp)();
    $this->withMiddleware();

    config(['talkto.panel.authorization.enabled' => true]);
    Gate::define('viewTalktoPanel', fn (AuthenticatableUser $user): bool => true);
    Queue::fake();

    $message = panelHardeningMessage('panel-action-middleware', 'outgoing', 'failed_retryable', [
        'next_retry_at' => now()->addHour(),
        'next_attempt_at' => now()->addHour(),
    ]);

    $route = Route::getRoutes()->getByName('talkto.panel.messages.retry');

    expect($route?->middleware())->toContain('web')
        ->and($route?->middleware())->toContain('auth');

    $this->postJson('/talkto/messages/'.$message->message_id.'/retry')->assertUnauthorized();

    $user = new class extends AuthenticatableUser {};
    $user->id = 1;

    $this->actingAs($user)
        ->postJson('/talkto/messages/'.$message->message_id.'/retry')
        ->assertOk()
        ->assertJsonPath('success', true);

    Queue::assertPushed(SendTalktoMessage::class, fn (SendTalktoMessage $job): bool => $job->talktoMessageId === $message->id);
});

test('security audit warns when panel is enabled without auth-like middleware', function (): void {
    ($this->bootPanelHardeningApp)();

    config([
        'talkto.panel.enabled' => true,
        'talkto.panel.route.middleware' => ['web'],
    ]);

    $this->artisan('talkto:audit-security')
        ->expectsOutputToContain('[WARN] Talkto panel is enabled without auth-like middleware.')
        ->assertExitCode(0);
});

function panelHardeningUseEnv(array $values = []): void
{
    panelHardeningClearEnv();

    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function panelHardeningClearEnv(): void
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
        'TALKTO_ROUTES_ENABLED',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

function panelHardeningMessage(string $messageId, string $direction, string $status, array $attributes = []): TalktoMessage
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
