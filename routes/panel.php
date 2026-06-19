<?php

use Illuminate\Support\Facades\Route;
use Mrezdev\LaravelTalkto\Http\Controllers\Panel\TalktoPanelConnectionsController;
use Mrezdev\LaravelTalkto\Http\Controllers\Panel\TalktoPanelController;
use Mrezdev\LaravelTalkto\Http\Controllers\Panel\TalktoPanelMessagesController;

Route::get('/', [TalktoPanelController::class, 'index'])->name('index');
Route::get('/messages', [TalktoPanelMessagesController::class, 'index'])->name('messages.index');
Route::get('/messages/{message}', [TalktoPanelMessagesController::class, 'show'])->name('messages.show');
Route::get('/connections', [TalktoPanelConnectionsController::class, 'index'])->name('connections.index');
