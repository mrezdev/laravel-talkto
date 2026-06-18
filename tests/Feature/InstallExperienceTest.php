<?php

use Ibake\TalktoReliable\Contracts\CommandHandlerContract;
use Ibake\TalktoReliable\Contracts\IncomingCommandResultContract;
use Ibake\TalktoReliable\Contracts\ResultCallbackReceiverContract;
use Ibake\TalktoReliable\Contracts\ResultCallbackSenderContract;
use Ibake\TalktoReliable\Contracts\SourceActionContract;
use Ibake\TalktoReliable\Contracts\TalktoIncomingCommandHandler;
use Ibake\TalktoReliable\Exceptions\InvalidTalktoSignatureException;
use Ibake\TalktoReliable\Exceptions\TalktoCommandNotAllowedException;
use Ibake\TalktoReliable\Exceptions\TalktoException;
use Ibake\TalktoReliable\Exceptions\TalktoIdempotencyException;
use Ibake\TalktoReliable\Exceptions\TalktoPayloadHashMismatchException;
use Ibake\TalktoReliable\Models\TalktoAttempt;
use Ibake\TalktoReliable\Models\TalktoEvent;
use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Services\TalktoPayloadHasher;
use Ibake\TalktoReliable\Services\TalktoSigner;
use Ibake\TalktoReliable\TalktoReliableServiceProvider;
use Illuminate\Support\Facades\Route;

test('service provider is discoverable and loaded by testbench', function (): void {
    expect(class_exists(TalktoReliableServiceProvider::class))->toBeTrue()
        ->and(app()->getProvider(TalktoReliableServiceProvider::class))->not->toBeNull();
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
