<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;

afterEach(function (): void {
    p2ProviderClearEnv();
});

test('missing routes enabled config does not load package api routes', function (): void {
    app('config')->offsetUnset('talkto.routes.enabled');

    app()->getProvider(LaravelTalktoServiceProvider::class)->boot();

    expect(Route::has('talkto.receive'))->toBeFalse()
        ->and(Route::has('talkto.callback'))->toBeFalse();
});

test('explicitly enabling routes loads package api routes', function (): void {
    p2ProviderUseEnv(['TALKTO_ROUTES_ENABLED' => 'true']);

    $this->refreshApplication();

    expect(Route::has('talkto.receive'))->toBeTrue()
        ->and(Route::has('talkto.callback'))->toBeTrue();
});

test('missing migrations enabled config does not load package migrations', function (): void {
    app('config')->offsetUnset('talkto.migrations.enabled');

    app()->getProvider(LaravelTalktoServiceProvider::class)->boot();

    expect($this->artisan('migrate')->run())->toBe(0);
    expect(Schema::hasTable('talkto_messages'))->toBeFalse();
});

test('explicitly enabling migrations loads package migrations', function (): void {
    config(['talkto.migrations.enabled' => true]);

    app()->getProvider(LaravelTalktoServiceProvider::class)->boot();

    expect($this->artisan('migrate')->run())->toBe(0);
    expect(Schema::hasTable('talkto_messages'))->toBeTrue()
        ->and(Schema::hasTable('talkto_dead_letters'))->toBeTrue();
});

test('panel remains disabled when enabled config is missing', function (): void {
    app('config')->offsetUnset('talkto.panel.enabled');

    app()->getProvider(LaravelTalktoServiceProvider::class)->boot();

    expect(Route::has('talkto.panel.index'))->toBeFalse();
});

function p2ProviderUseEnv(array $values): void
{
    p2ProviderClearEnv();

    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function p2ProviderClearEnv(): void
{
    foreach (['TALKTO_ROUTES_ENABLED'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}
