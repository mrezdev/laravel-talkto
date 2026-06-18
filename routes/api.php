<?php

use Ibake\TalktoReliable\Http\Controllers\TalktoReceiveController;
use Illuminate\Support\Facades\Route;

$prefix = config('talkto.routes.prefix', 'api');
$middleware = config('talkto.routes.middleware', ['api']);
$receiveUri = config('talkto.routes.receive_uri', 'talkto/receive');
$receiveName = config('talkto.routes.receive_name', 'talkto.receive');

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(function () use ($receiveUri, $receiveName): void {
        Route::post($receiveUri, TalktoReceiveController::class)
            ->name($receiveName);
    });
