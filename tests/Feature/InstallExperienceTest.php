<?php

use Illuminate\Support\Facades\Route;
use Mrezdev\LaravelTalkto\Contracts\CommandHandlerContract;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Contracts\SourceActionContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoSignatureException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoCommandNotAllowedException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoIdempotencyException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoPayloadHashMismatchException;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;

test('service provider is discoverable and loaded by testbench', function (): void {
    expect(class_exists(LaravelTalktoServiceProvider::class))->toBeTrue()
        ->and(app()->getProvider(LaravelTalktoServiceProvider::class))->not->toBeNull();
});

test('published config defaults are safe for a first install', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';

    expect($defaults)->toBeArray()
        ->and($defaults['routes']['enabled'])->toBeFalse()
        ->and($defaults['migrations']['enabled'])->toBeFalse()
        ->and($defaults['models']['message'])->toBe(TalktoMessage::class)
        ->and($defaults['models']['attempt'])->toBe(TalktoAttempt::class)
        ->and($defaults['models']['event'])->toBe(TalktoEvent::class)
        ->and($defaults['incoming'])->toBeArray()
        ->and($defaults['outgoing'])->toBeArray();
});

test('routes remain disabled until the host opts in', function (): void {
    $routeName = config('talkto.routes.receive_name', 'talkto.receive');

    expect(config('talkto.routes.enabled'))->toBeFalse()
        ->and(Route::getRoutes()->getByName($routeName))->toBeNull();
});

test('core public services resolve without host bindings', function (): void {
    expect(app(TalktoSigner::class))->toBeInstanceOf(TalktoSigner::class)
        ->and(app(TalktoPayloadHasher::class))->toBeInstanceOf(TalktoPayloadHasher::class);
});

test('public contracts and exceptions are available after install', function (): void {
    expect(interface_exists(CommandHandlerContract::class))->toBeTrue()
        ->and(interface_exists(IncomingCommandResultContract::class))->toBeTrue()
        ->and(interface_exists(ResultCallbackReceiverContract::class))->toBeTrue()
        ->and(interface_exists(ResultCallbackSenderContract::class))->toBeTrue()
        ->and(interface_exists(SourceActionContract::class))->toBeTrue()
        ->and(interface_exists(TalktoIncomingCommandHandler::class))->toBeTrue()
        ->and(is_subclass_of(InvalidTalktoSignatureException::class, TalktoException::class))->toBeTrue()
        ->and(is_subclass_of(TalktoCommandNotAllowedException::class, TalktoException::class))->toBeTrue()
        ->and(is_subclass_of(TalktoIdempotencyException::class, TalktoException::class))->toBeTrue()
        ->and(is_subclass_of(TalktoPayloadHashMismatchException::class, TalktoException::class))->toBeTrue();
});

test('default config does not require host application classes', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';
    $values = new RecursiveIteratorIterator(new RecursiveArrayIterator($defaults));
    $hostClassReferences = [];

    foreach ($values as $value) {
        if (is_string($value) && str_starts_with($value, 'App\\')) {
            $hostClassReferences[] = $value;
        }
    }

    expect($hostClassReferences)->toBe([]);
});
