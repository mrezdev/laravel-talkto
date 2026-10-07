<?php

use Illuminate\Database\Eloquent\Model;
use Mrezdev\LaravelTalkto\Contracts\CommandHandlerContract;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Contracts\SourceActionContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClientWithOptions;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoSignatureException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoCommandNotAllowedException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoIdempotencyException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoPayloadHashMismatchException;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingTarget;
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

test('public contracts retain existing method signatures and named parameters', function (): void {
    // Required members only: additional methods and optional parameters remain possible.
    $contracts = [
        CommandHandlerContract::class => ['handle' => [IncomingCommandResultContract::class, ['message' => TalktoMessage::class], 1]],
        TalktoIncomingCommandHandler::class => ['handle' => [IncomingCommandResultContract::class, ['message' => TalktoMessage::class], 1]],
        IncomingCommandResultContract::class => [
            'isSucceeded' => ['bool', [], 0],
            'isRetryable' => ['bool', [], 0],
            'isSkipped' => ['bool', [], 0],
            'errorClass' => ['?string', [], 0],
            'errorMessage' => ['?string', [], 0],
            'result' => ['array', [], 0],
            'meta' => ['array', [], 0],
        ],
        SourceActionContract::class => ['execute' => ['mixed', [], 0]],
        TalktoHttpClient::class => ['post' => [TalktoHttpResponse::class, ['url' => 'string', 'headers' => 'array', 'envelope' => 'array', 'timeout' => 'int'], 4]],
        TalktoHttpClientWithOptions::class => ['postWithOptions' => [TalktoHttpResponse::class, ['url' => 'string', 'headers' => 'array', 'envelope' => 'array', 'timeout' => 'int', 'options' => 'array'], 4]],
        TalktoIncomingHandlerRegistryContract::class => [
            'register' => ['void', ['command' => 'string', 'handlerClass' => 'string'], 2],
            'has' => ['bool', ['command' => 'string'], 1],
            'resolve' => ['?'.TalktoIncomingCommandHandler::class, ['command' => 'string'], 1],
            'all' => ['array', [], 0],
        ],
        TalktoOutgoingTargetRegistryContract::class => [
            'register' => ['void', ['name' => 'string', 'target' => 'array|'.TalktoOutgoingTarget::class], 2],
            'has' => ['bool', ['name' => 'string'], 1],
            'get' => [TalktoOutgoingTarget::class, ['name' => 'string'], 1],
            'resolve' => ['?'.TalktoOutgoingTarget::class, ['name' => 'string'], 1],
            'all' => ['array', [], 0],
        ],
        ResultCallbackSenderContract::class => ['sendResult' => ['mixed', ['message' => Model::class, 'result' => IncomingCommandResultContract::class, 'options' => 'array'], 2]],
        ResultCallbackReceiverContract::class => ['receiveResult' => ['mixed', ['envelope' => 'array', 'headers' => 'array'], 1]],
    ];

    foreach ($contracts as $contract => $methods) {
        foreach ($methods as $name => [$returnType, $parameters, $requiredCount]) {
            $method = new ReflectionMethod($contract, $name);
            expect($method->isPublic())->toBeTrue()
                ->and($method->isStatic())->toBeFalse()
                ->and((string) $method->getReturnType())->toBe($returnType)
                ->and($method->getNumberOfRequiredParameters())->toBe($requiredCount);

            $actualParameters = $method->getParameters();
            foreach (array_keys($parameters) as $position => $parameterName) {
                $parameter = $actualParameters[$position];
                $actualTypes = explode('|', (string) $parameter->getType());
                $expectedTypes = explode('|', $parameters[$parameterName]);
                sort($actualTypes);
                sort($expectedTypes);
                expect($parameter->getName())->toBe($parameterName)
                    ->and($actualTypes)->toBe($expectedTypes)
                    ->and($parameter->isOptional())->toBe($position >= $requiredCount);
                if ($position >= $requiredCount) {
                    expect($parameter->getDefaultValue())->toBe([]);
                }
            }
        }
    }
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
    $signatureV2 = $signer->signV2('2026-01-01T00:00:00+00:00', 'nonce-1', 'message-1', 'source-service', 'target-service', 'domain.command', $hash, 'test-secret');

    // Fixed wire fixtures catch coordinated changes to both signer and verifier.
    expect($hash)->toBe('501834286c85f8e076d3dc1f2dd19e7b1bc884078c5938551f825d3847b11181')
        ->and($hash)->toBe($hasher->hash(['a' => ['a' => 1, 'b' => 2], 'z' => 3]))
        ->and($signature)->toBe('e13ff0156fda9b24b40b66b58d1a2e0a0fdd3a93198f558a1171b9eb014b5e22')
        ->and($signatureV2)->toBe('14bade4070f8cad0a4d085d5fb6dbb0919ef12b8fb48350aef0b479c886df205')
        ->and($signer->verify($signature, 'message-1', '2026-01-01T00:00:00+00:00', 'source-service', 'target-service', 'domain.command', $hash, 'test-secret'))->toBeTrue()
        ->and($signer->verifyV2($signatureV2, '2026-01-01T00:00:00+00:00', 'nonce-1', 'message-1', 'source-service', 'target-service', 'domain.command', $hash, 'test-secret'))->toBeTrue();
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
        'Verify'.'In'.'voice',
        'De'.'mand',
        'Ap'.'peal',
        'Hy'.'brid',
        'Material'.'Detail',
        'create:receive'.'-bulks-hy'.'brid',
        'receive'.'-bulks-hy'.'brid',
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
