<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

afterEach(function (): void {
    p4RouteThrottlingClearEnv();
});

test('routes are disabled by default', function (): void {
    expect(config('talkto.routes.enabled'))->toBeFalse()
        ->and(Route::has('talkto.receive'))->toBeFalse()
        ->and(Route::has('talkto.callback'))->toBeFalse();
});

test('explicitly enabled routes load receive and callback endpoints', function (): void {
    p4RouteThrottlingUseEnv(['TALKTO_ROUTES_ENABLED' => 'true']);

    $this->refreshApplication();

    expect(Route::has('talkto.receive'))->toBeTrue()
        ->and(Route::has('talkto.callback'))->toBeTrue();
});

test('default route middleware includes named talkto throttle', function (): void {
    p4RouteThrottlingUseEnv(['TALKTO_ROUTES_ENABLED' => 'true']);

    $this->refreshApplication();

    $middleware = Route::getRoutes()->getByName('talkto.receive')?->middleware() ?? [];

    expect($middleware)->toContain('api')
        ->and($middleware)->toContain('throttle:talkto');
});

test('named talkto rate limiter is registered when routes and rate limiting are enabled', function (): void {
    p4RouteThrottlingUseEnv(['TALKTO_ROUTES_ENABLED' => 'true']);

    $this->refreshApplication();

    $limiter = RateLimiter::limiter('talkto');
    expect($limiter)->toBeInstanceOf(Closure::class);

    $limit = $limiter(Request::create('/api/talkto/receive', 'POST', ['source' => 'source-service']));

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(120)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe('source:source-service');
});

test('users can override route middleware', function (): void {
    p4RouteThrottlingUseEnv([
        'TALKTO_ROUTES_ENABLED' => 'true',
        'TALKTO_ROUTE_MIDDLEWARE' => 'web,auth:sanctum',
    ]);

    $this->refreshApplication();

    $middleware = Route::getRoutes()->getByName('talkto.receive')?->middleware() ?? [];

    expect($middleware)->toContain('web')
        ->and($middleware)->toContain('auth:sanctum')
        ->and($middleware)->not->toContain('throttle:talkto');
});

test('custom limiter name is respected by middleware and limiter registration', function (): void {
    p4RouteThrottlingUseEnv([
        'TALKTO_ROUTES_ENABLED' => 'true',
        'TALKTO_RATE_LIMIT_NAME' => 'custom-talkto',
    ]);

    $this->refreshApplication();

    $middleware = Route::getRoutes()->getByName('talkto.receive')?->middleware() ?? [];

    expect($middleware)->toContain('throttle:custom-talkto')
        ->and(RateLimiter::limiter('custom-talkto'))->toBeInstanceOf(Closure::class);
});

test('disabling route rate limiting keeps route registration working', function (): void {
    p4RouteThrottlingUseEnv([
        'TALKTO_ROUTES_ENABLED' => 'true',
        'TALKTO_RATE_LIMIT_ENABLED' => 'false',
    ]);

    $this->refreshApplication();

    $middleware = Route::getRoutes()->getByName('talkto.receive')?->middleware() ?? [];

    expect(Route::has('talkto.receive'))->toBeTrue()
        ->and($middleware)->toContain('api')
        ->and($middleware)->not->toContain('throttle:talkto');
});

function p4RouteThrottlingUseEnv(array $values): void
{
    p4RouteThrottlingClearEnv();

    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function p4RouteThrottlingClearEnv(): void
{
    foreach ([
        'TALKTO_ROUTES_ENABLED',
        'TALKTO_ROUTE_MIDDLEWARE',
        'TALKTO_RATE_LIMIT_ENABLED',
        'TALKTO_RATE_LIMIT_NAME',
        'TALKTO_RATE_LIMIT_MAX_ATTEMPTS',
        'TALKTO_RATE_LIMIT_DECAY_MINUTES',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}
