<?php

use Illuminate\Support\Facades\Route;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoReceiveController;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoResultCallbackController;

$prefix = config('talkto.routes.prefix', 'api');
$middleware = config('talkto.routes.middleware', ['api']);
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
