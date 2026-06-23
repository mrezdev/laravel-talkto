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

test('outgoing registry exposes effective ssl verification defaults', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    $array = app(TalktoPanelConnectionRegistry::class)
        ->outgoing()
        ->firstWhere('service', 'target-alpha')
        ->toArray();

    expect($array['ssl_verify_enabled'])->toBeTrue()
        ->and($array['ssl_verify_source'])->toBe('default')
        ->and($array['ca_bundle_configured'])->toBeFalse()
        ->and($array['ca_bundle_status'])->toBe('system_default')
        ->and($array['ca_bundle_source'])->toBe('default')
        ->and($array['ca_bundle_label'])->toBeNull()
        ->and($array['ca_bundle_exists'])->toBeNull()
        ->and($array['ca_bundle_readable'])->toBeNull();
});

test('outgoing registry exposes disabled ssl verification and target override source', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    config(['talkto.http.verify_ssl' => false]);

    $globalDisabled = app(TalktoPanelConnectionRegistry::class)
        ->outgoing()
        ->firstWhere('service', 'target-alpha')
        ->toArray();

    config(['talkto.outgoing.target-alpha.verify_ssl' => true]);

    $targetEnabled = app(TalktoPanelConnectionRegistry::class)
        ->outgoing()
        ->firstWhere('service', 'target-alpha')
        ->toArray();

    expect($globalDisabled['ssl_verify_enabled'])->toBeFalse()
        ->and($globalDisabled['ssl_verify_source'])->toBe('global')
        ->and($globalDisabled['warnings'])->toContain('ssl_verification_disabled')
        ->and($targetEnabled['ssl_verify_enabled'])->toBeTrue()
        ->and($targetEnabled['ssl_verify_source'])->toBe('target')
        ->and($targetEnabled['warnings'])->not->toContain('ssl_verification_disabled');
});

test('outgoing registry exposes safe ca bundle status without full path', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    $caBundle = sys_get_temp_dir().DIRECTORY_SEPARATOR.'panel-internal-ca-'.uniqid().'.pem';
    file_put_contents($caBundle, 'test ca');

    try {
        config(['talkto.outgoing.target-alpha.ca_bundle' => $caBundle]);

        $custom = app(TalktoPanelConnectionRegistry::class)
            ->outgoing()
            ->firstWhere('service', 'target-alpha')
            ->toArray();
        $encoded = json_encode($custom, JSON_THROW_ON_ERROR);

        expect($custom['ca_bundle_status'])->toBe('custom')
            ->and($custom['ca_bundle_configured'])->toBeTrue()
            ->and($custom['ca_bundle_source'])->toBe('target')
            ->and($custom['ca_bundle_label'])->toBe(basename($caBundle))
            ->and($custom['ca_bundle_exists'])->toBeTrue()
            ->and($custom['ca_bundle_readable'])->toBeTrue()
            ->and($encoded)->not->toContain($caBundle)
            ->and($encoded)->not->toContain('outgoing-secret');

        config(['talkto.outgoing.target-alpha.verify_ssl' => false]);

        $ignored = app(TalktoPanelConnectionRegistry::class)
            ->outgoing()
            ->firstWhere('service', 'target-alpha')
            ->toArray();

        expect($ignored['ca_bundle_status'])->toBe('ignored')
            ->and($ignored['ca_bundle_label'])->toBe(basename($caBundle))
            ->and($ignored['warnings'])->toContain('ca_bundle_ignored');
    } finally {
        @unlink($caBundle);
    }
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
        'talkto.outgoing.target-delta' => [
            'receive_url' => 'https://target-delta.test/api/talkto/receive',
            'receive_endpoint' => '/api/talkto/receive',
            'callback_url' => 'https://target-delta.test/api/talkto/callback',
            'secret' => 'delta-secret',
            'health_endpoint' => '/api/talkto/health',
        ],
    ]);

    $outgoing = app(TalktoPanelConnectionRegistry::class)->outgoing();

    expect($outgoing->firstWhere('service', 'target-beta')->activeHealthUrl)->toBe('https://status.target-beta.test/up')
        ->and($outgoing->firstWhere('service', 'target-gamma')->activeHealthUrl)->toBe('https://target-gamma.test/ready')
        ->and($outgoing->firstWhere('service', 'target-delta')->activeHealthUrl)->toBe('https://target-delta.test/api/talkto/health');
});

test('outgoing registry displays normalized receive url target without exposing secrets', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    config(['talkto.outgoing.target-normalized-receive' => [
        'receive_url' => 'https://target-normalized.test/api/talkto/receive',
        'callback_url' => 'https://target-normalized.test/api/talkto/callback?api_token=callback-token&plain=yes',
        'secret' => 'normalized-receive-shared-secret',
    ]]);

    $connection = app(TalktoPanelConnectionRegistry::class)->outgoing()->firstWhere('service', 'target-normalized-receive');
    $array = $connection->toArray();
    $encoded = json_encode($array, JSON_THROW_ON_ERROR);

    expect($connection->configured)->toBeTrue()
        ->and($connection->urlConfigured)->toBeTrue()
        ->and($connection->secretConfigured)->toBeTrue()
        ->and($connection->warnings)->not->toContain('missing_url')
        ->and($array['meta']['receive_url'])->toBe('https://target-normalized.test/api/talkto/receive')
        ->and($array['meta']['callback_url'])->toContain('api_token=[redacted]')
        ->and($array['meta']['callback_url'])->toContain('plain=yes')
        ->and($encoded)->not->toContain('callback-token')
        ->and($encoded)->not->toContain('normalized-receive-shared-secret');
});

test('outgoing registry displays normalized base url target and redacts url metadata', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    config(['talkto.outgoing.target-normalized-base' => [
        'base_url' => 'https://target-normalized.test/root',
        'receive_endpoint' => '/talkto/receive',
        'callback_endpoint' => '/talkto/callback',
        'signing_secret' => 'normalized-base-shared-secret',
        'timeout' => 9,
        'mode' => 'sync',
    ]]);

    $connection = app(TalktoPanelConnectionRegistry::class)->outgoing()->firstWhere('service', 'target-normalized-base');
    $array = $connection->toArray();
    $encoded = json_encode($array, JSON_THROW_ON_ERROR);

    expect($connection->configured)->toBeTrue()
        ->and($connection->urlConfigured)->toBeTrue()
        ->and($connection->secretConfigured)->toBeTrue()
        ->and($connection->warnings)->not->toContain('missing_url')
        ->and($array['endpoint'])->toBe('/talkto/receive')
        ->and($array['meta']['receive_endpoint'])->toBe('/talkto/receive')
        ->and($array['meta']['callback_endpoint'])->toBe('/talkto/callback')
        ->and($array['meta']['transport'])->toBe('sync')
        ->and($array['meta']['timeout_seconds'])->toBe(9)
        ->and($array['meta']['receive_url'])->toBe('https://target-normalized.test/root/talkto/receive')
        ->and($array['meta']['callback_url'])->toBe('https://target-normalized.test/root/talkto/callback')
        ->and($encoded)->not->toContain('normalized-base-shared-secret');
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

test('connections view shows ssl verification and safe ca bundle label', function (): void {
    ($this->bootPanelConnectionHealthApp)();

    $caBundle = sys_get_temp_dir().DIRECTORY_SEPARATOR.'panel-rendered-ca-'.uniqid().'.pem';
    file_put_contents($caBundle, 'test ca');

    try {
        config([
            'talkto.outgoing.target-alpha.verify_ssl' => false,
            'talkto.outgoing.target-alpha.ca_bundle' => $caBundle,
        ]);

        $this->get('/talkto/connections')
            ->assertOk()
            ->assertSee('SSL verification')
            ->assertSee('disabled')
            ->assertSee(basename($caBundle))
            ->assertSee('ignored')
            ->assertDontSee($caBundle)
            ->assertDontSee('outgoing-secret');
    } finally {
        @unlink($caBundle);
    }
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
