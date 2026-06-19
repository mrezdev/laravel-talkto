<?php

use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelActiveHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionRegistry;

beforeEach(function (): void {
    $this->bootPanelConnectionHealthApp = function (array $env = []): void {
        p5PanelUseEnv(array_merge(['TALKTO_PANEL_ENABLED' => 'true'], $env));

        $this->refreshApplication();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        expect($this->artisan('migrate')->run())->toBe(0);

        Cache::flush();

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'talkto.panel.authorization.enabled' => false,
            'talkto.service' => 'panel-test',
            'talkto.outgoing.target-alpha' => [
                'url' => 'https://target-alpha.test',
                'secret' => 'outgoing-secret',
                'endpoint' => '/api/talkto/receive',
                'health' => [
                    'url' => 'https://target-alpha.test/health?api_token=health-token&plain=yes',
                    'method' => 'GET',
                    'timeout' => 2,
                ],
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
    p5PanelClearEnv();
    Cache::flush();
});

test('outgoing registry detects health config and redacts sensitive query values', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    $connection = app(TalktoPanelConnectionRegistry::class)->outgoing()->firstWhere('service', 'target-alpha');
    $array = $connection->toArray();

    expect($connection->activeHealthConfigured)->toBeTrue()
        ->and($connection->activeHealthMethod)->toBe('GET')
        ->and($connection->activeHealthMeta['timeout_seconds'] ?? null)->toBe(2)
        ->and($array['active_health_url'])->toContain('api_token=[redacted]')
        ->and($array['active_health_url'])->toContain('plain=yes')
        ->and($array['active_health_url'])->not->toContain('health-token')
        ->and($array['active_health_url'])->not->toContain('outgoing-secret');
});

test('outgoing registry detects health_url and health_endpoint fallback config', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    config([
        'talkto.outgoing.target-beta' => [
            'url' => 'https://target-beta.test/base',
            'secret' => 'beta-secret',
            'endpoint' => '/api/talkto/receive',
            'health_url' => 'https://status.target-beta.test/up',
        ],
        'talkto.outgoing.target-gamma' => [
            'url' => 'https://target-gamma.test',
            'secret' => 'gamma-secret',
            'endpoint' => '/api/talkto/receive',
            'health_endpoint' => '/ready',
        ],
    ]);

    $outgoing = app(TalktoPanelConnectionRegistry::class)->outgoing();

    expect($outgoing->firstWhere('service', 'target-beta')->activeHealthUrl)->toBe('https://status.target-beta.test/up')
        ->and($outgoing->firstWhere('service', 'target-gamma')->activeHealthUrl)->toBe('https://target-gamma.test/ready');
});

test('unsupported active health method creates connection warning', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    config(['talkto.outgoing.target-alpha.health.method' => 'POST']);

    $connection = app(TalktoPanelConnectionRegistry::class)->outgoing()->firstWhere('service', 'target-alpha');

    expect($connection->warnings)->toContain('unsupported_active_health_method')
        ->and($connection->activeHealthMeta['warning'] ?? null)->toBe('unsupported_method');
});

test('active checker returns disabled not configured and not applicable states', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    $registry = app(TalktoPanelConnectionRegistry::class);
    $checker = app(TalktoPanelActiveHealthChecker::class);
    $outgoing = $registry->outgoing()->firstWhere('service', 'target-alpha');
    $incoming = $registry->incoming()->firstWhere('service', 'source-alpha');

    expect($checker->check($outgoing)->toArray())->toMatchArray([
        'enabled' => false,
        'status' => 'unknown',
    ]);

    config([
        'talkto.panel.health.active_checks.enabled' => true,
        'talkto.outgoing.target-alpha.health.url' => null,
        'talkto.outgoing.target-alpha.health_url' => null,
        'talkto.outgoing.target-alpha.health_endpoint' => null,
    ]);

    $registry = app(TalktoPanelConnectionRegistry::class);

    expect($checker->check($registry->outgoing()->firstWhere('service', 'target-alpha'))->toArray())->toMatchArray([
        'enabled' => true,
        'configured' => false,
        'status' => 'not_configured',
    ])
        ->and($checker->check($incoming)->toArray())->toMatchArray([
            'enabled' => true,
            'configured' => false,
            'status' => 'not_applicable',
        ]);
});

test('active checker reports healthy and does not send configured service secret', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    config(['talkto.panel.health.active_checks.enabled' => true]);
    Http::fake([
        'https://target-alpha.test/health*' => Http::response('', 200),
    ]);

    $connection = app(TalktoPanelConnectionRegistry::class)->outgoing()->firstWhere('service', 'target-alpha');
    $result = app(TalktoPanelActiveHealthChecker::class)->check($connection);

    expect($result->toArray())->toMatchArray([
        'enabled' => true,
        'configured' => true,
        'status' => 'healthy',
        'http_status' => 200,
    ]);

    Http::assertSent(function ($request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://target-alpha.test/health?api_token=health-token&plain=yes'
            && ! $request->hasHeader('Authorization')
            && ! str_contains(json_encode($request->headers(), JSON_THROW_ON_ERROR), 'outgoing-secret');
    });
});

test('active checker reports failing response and safe exception output', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    config(['talkto.panel.health.active_checks.enabled' => true]);
    $connection = app(TalktoPanelConnectionRegistry::class)->outgoing()->firstWhere('service', 'target-alpha');

    Http::fake([
        'https://target-alpha.test/health*' => Http::response('', 503),
    ]);

    expect(app(TalktoPanelActiveHealthChecker::class)->check($connection, force: true)->toArray())->toMatchArray([
        'status' => 'failing',
        'http_status' => 503,
    ]);

    Http::fake(function (): never {
        throw new RuntimeException('Connection failed for outgoing-secret');
    });

    $exceptionResult = app(TalktoPanelActiveHealthChecker::class)->check($connection, force: true)->toArray();

    expect($exceptionResult['status'])->toBe('unknown')
        ->and($exceptionResult['warnings'][0] ?? '')->toContain('[redacted]')
        ->and($exceptionResult['warnings'][0] ?? '')->not->toContain('outgoing-secret');
});

test('active checker caches results and force bypasses cache', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    config(['talkto.panel.health.active_checks.enabled' => true]);

    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return Http::response('', 200);
    });

    $connection = app(TalktoPanelConnectionRegistry::class)->outgoing()->firstWhere('service', 'target-alpha');
    $checker = app(TalktoPanelActiveHealthChecker::class);

    $checker->check($connection);
    $checker->check($connection);

    expect($calls)->toBe(1);

    $checker->check($connection, force: true);

    expect($calls)->toBe(2);
});

test('connections index returns active health json and check route handles disabled and unknown connections', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    $this->getJson('/talkto/connections')
        ->assertOk()
        ->assertJsonPath('active_health.0.enabled', false)
        ->assertJsonPath('active_health_enabled', false);

    $this->postJson('/talkto/connections/outgoing/missing/check')->assertNotFound();

    $this->postJson('/talkto/connections/outgoing/target-alpha/check')
        ->assertUnprocessable()
        ->assertJsonPath('enabled', false);
});

test('connection check route returns json and redirects with flash for html requests', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    config(['talkto.panel.health.active_checks.enabled' => true]);
    Http::fake([
        'https://target-alpha.test/health*' => Http::response('', 200),
    ]);

    $this->postJson('/talkto/connections/outgoing/target-alpha/check')
        ->assertOk()
        ->assertJsonPath('status', 'healthy')
        ->assertJsonPath('http_status', 200);

    $this->from('/talkto/connections')
        ->post('/talkto/connections/outgoing/target-alpha/check')
        ->assertRedirect('/talkto/connections')
        ->assertSessionHas('talkto_panel_status', 'Active health check for target-alpha is healthy.');
});

test('connection check route authorization applies', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    config([
        'talkto.panel.authorization.enabled' => true,
        'talkto.panel.health.active_checks.enabled' => true,
    ]);

    $this->postJson('/talkto/connections/outgoing/target-alpha/check')->assertForbidden();

    Gate::define('viewTalktoPanel', fn (AuthenticatableUser $user): bool => true);
    $user = new class extends AuthenticatableUser {};
    $user->id = 1;
    $this->actingAs($user);

    Http::fake([
        'https://target-alpha.test/health*' => Http::response('', 200),
    ]);

    $this->postJson('/talkto/connections/outgoing/target-alpha/check')->assertOk();
});

test('connections view shows active health status and gated check form', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    config(['talkto.panel.health.active_checks.enabled' => true]);
    Http::fake([
        'https://target-alpha.test/health*' => Http::response('', 200),
    ]);

    $this->get('/talkto/connections')
        ->assertOk()
        ->assertSee('Active endpoint check')
        ->assertSee('Check now')
        ->assertSee('api_token=[redacted]')
        ->assertDontSee('outgoing-secret');

    config([
        'talkto.panel.health.active_checks.enabled' => false,
        'talkto.panel.actions.active_health_checks_enabled' => false,
    ]);

    $this->get('/talkto/connections')
        ->assertOk()
        ->assertSee('disabled')
        ->assertDontSee('Check now');

    config([
        'talkto.panel.health.active_checks.enabled' => true,
        'talkto.outgoing.target-alpha.health.url' => null,
    ]);

    $this->get('/talkto/connections')
        ->assertOk()
        ->assertSee('not configured')
        ->assertDontSee('Check now');
});

function p5PanelUseEnv(array $values = []): void
{
    p5PanelClearEnv();

    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function p5PanelClearEnv(): void
{
    foreach ([
        'TALKTO_PANEL_ENABLED',
        'TALKTO_PANEL_PREFIX',
        'TALKTO_PANEL_DOMAIN',
        'TALKTO_PANEL_ROUTE_NAME',
        'TALKTO_PANEL_AUTHORIZATION_ENABLED',
        'TALKTO_PANEL_GATE',
        'TALKTO_PANEL_HEALTH_WINDOW_MINUTES',
        'TALKTO_PANEL_HEALTH_CACHE_SECONDS',
        'TALKTO_PANEL_ACTIVE_HEALTH_CHECKS_ENABLED',
        'TALKTO_PANEL_HEALTH_TIMEOUT_SECONDS',
        'TALKTO_ROUTES_ENABLED',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

function p5PanelMessage(string $messageId, string $direction, string $status, array $attributes = []): TalktoMessage
{
    $createdAt = $attributes['created_at'] ?? now();

    $message = TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'corr-'.$messageId,
        'direction' => $direction,
        'source_service' => $direction === 'incoming' ? 'source-alpha' : 'panel-test',
        'target_service' => $direction === 'outgoing' ? 'target-alpha' : 'panel-test',
        'command' => $direction === 'incoming' ? 'website.event-created' : 'domain.sync',
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
