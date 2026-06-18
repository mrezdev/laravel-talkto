<?php

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
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;

test('public contracts keep the legacy command handler compatible', function (): void {
    expect(is_subclass_of(TalktoIncomingCommandHandler::class, CommandHandlerContract::class))->toBeTrue()
        ->and(interface_exists(IncomingCommandResultContract::class))->toBeTrue()
        ->and(interface_exists(SourceActionContract::class))->toBeTrue()
        ->and(interface_exists(ResultCallbackSenderContract::class))->toBeTrue()
        ->and(interface_exists(ResultCallbackReceiverContract::class))->toBeTrue();
});

test('public exception hierarchy is stable', function (): void {
    expect(is_subclass_of(InvalidTalktoSignatureException::class, TalktoException::class))->toBeTrue()
        ->and(is_subclass_of(TalktoCommandNotAllowedException::class, TalktoException::class))->toBeTrue()
        ->and(is_subclass_of(TalktoPayloadHashMismatchException::class, TalktoException::class))->toBeTrue()
        ->and(is_subclass_of(TalktoIdempotencyException::class, TalktoException::class))->toBeTrue();
});

test('default package config is opt-in for routes and migrations', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';

    expect($defaults['routes']['enabled'])->toBeFalse()
        ->and($defaults['migrations']['enabled'])->toBeFalse()
        ->and($defaults['aliases'])->toBeArray()
        ->and($defaults['incoming'])->toBeArray()
        ->and($defaults['outgoing'])->toBeArray();
});

test('signing and hashing public services remain deterministic', function (): void {
    $hasher = app(TalktoPayloadHasher::class);
    $signer = app(TalktoSigner::class);

    $hash = $hasher->hash(['z' => 3, 'a' => ['b' => 2, 'a' => 1]]);
    $signature = $signer->sign('message-1', '2026-01-01T00:00:00+00:00', 'source-service', 'target-service', 'domain.command', $hash, 'test-secret');

    expect($hash)->toBe($hasher->hash(['a' => ['a' => 1, 'b' => 2], 'z' => 3]))
        ->and($signer->verify($signature, 'message-1', '2026-01-01T00:00:00+00:00', 'source-service', 'target-service', 'domain.command', $hash, 'test-secret'))->toBeTrue();
});

test('package source avoids host business terms', function (): void {
    $root = realpath(__DIR__.'/../../');
    $paths = [
        $root.'/src',
        $root.'/config',
        $root.'/routes',
        $root.'/docs',
        $root.'/README.md',
    ];

    $terms = [
        'Verify'.'Invoice',
        'De'.'mand',
        'Ap'.'peal',
        'Hy'.'brid',
        'Material'.'Detail',
        'create:receive'.'-bulks-hybrid',
        'receive'.'-bulks-hybrid',
        'product'.'_'.'inven'.'tory',
        'ware'.'house',
    ];

    $matches = [];

    foreach ($paths as $path) {
        if (is_file($path)) {
            $files = [$path];
        } elseif (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            $files = iterator_to_array($iterator);
        } else {
            $files = [];
        }

        foreach ($files as $file) {
            $filePath = (string) $file;

            if (! preg_match('/\.(php|md)$/', $filePath)) {
                continue;
            }

            $contents = file_get_contents($filePath) ?: '';

            foreach ($terms as $term) {
                if (str_contains($contents, $term)) {
                    $matches[] = basename($filePath).':'.$term;
                }
            }
        }
    }

    expect($matches)->toBe([]);
});
