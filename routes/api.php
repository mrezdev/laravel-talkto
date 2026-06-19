<?php

use Illuminate\Support\Facades\Route;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoReceiveController;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoResultCallbackController;

$prefix = config('talkto.routes.prefix', 'api');
$middleware = (static function (): array {
    $middleware = config('talkto.routes.middleware');

    if (is_string($middleware)) {
        $middleware = array_map('trim', explode(',', $middleware));
    }

    if (is_array($middleware) && $middleware !== []) {
        return array_values(array_filter($middleware, static fn ($name): bool => is_string($name) && $name !== ''));
    }

    $default = ['api'];

    if ((bool) config('talkto.routes.rate_limit.enabled', true)) {
        $limiterName = config('talkto.routes.rate_limit.name', 'talkto');
        $default[] = 'throttle:'.(is_string($limiterName) && $limiterName !== '' ? $limiterName : 'talkto');
    }

    return $default;
})();
$receiveUri = config('talkto.routes.receive_uri', 'talkto/receive');
$receiveName = config('talkto.routes.receive_name', 'talkto.receive');
$callbackUri = config('talkto.routes.callback_uri', 'talkto/callback');
$callbackName = config('talkto.routes.callback_name', 'talkto.callback');
$callbacksEnabled = config('talkto.callbacks.enabled', true);

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(function () use ($receiveUri, $receiveName, $callbackUri, $callbackName, $callbacksEnabled): void {
        Route::post($receiveUri, TalktoReceiveController::class)
            ->name($receiveName);

        if ($callbacksEnabled) {
            Route::post($callbackUri, TalktoResultCallbackController::class)
                ->name($callbackName);
        }
    });
