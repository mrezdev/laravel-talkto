<?php

use Illuminate\Support\Facades\Route;
use Mrezdev\LaravelTalkto\Http\Controllers\Panel\TalktoPanelConnectionsController;
use Mrezdev\LaravelTalkto\Http\Controllers\Panel\TalktoPanelDeadLetterActionsController;
use Mrezdev\LaravelTalkto\Http\Controllers\Panel\TalktoPanelController;
use Mrezdev\LaravelTalkto\Http\Controllers\Panel\TalktoPanelMessageActionsController;
use Mrezdev\LaravelTalkto\Http\Controllers\Panel\TalktoPanelMessagesController;

Route::get('/', [TalktoPanelController::class, 'index'])->name('index');
Route::get('/messages', [TalktoPanelMessagesController::class, 'index'])->name('messages.index');
Route::post('/messages/{message}/retry', [TalktoPanelMessageActionsController::class, 'retry'])->name('messages.retry');
Route::get('/messages/{message}/trace', [TalktoPanelMessageActionsController::class, 'trace'])->name('messages.trace');
Route::get('/messages/{message}', [TalktoPanelMessagesController::class, 'show'])->name('messages.show');
Route::post('/dead-letters/{deadLetter}/reprocess', [TalktoPanelDeadLetterActionsController::class, 'reprocess'])->name('dead-letters.reprocess');
Route::get('/connections', [TalktoPanelConnectionsController::class, 'index'])->name('connections.index');
Route::post('/connections/{direction}/{service}/check', [TalktoPanelConnectionsController::class, 'check'])->name('connections.check');
