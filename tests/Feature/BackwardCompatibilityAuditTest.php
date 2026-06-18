<?php

use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoIncomingHandler;
use Mrezdev\LaravelTalkto\Exceptions\UnknownTalktoIncomingCommand;
use Mrezdev\LaravelTalkto\Handlers\NoopIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Handlers\SkippedIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResolver;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingTarget;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

beforeEach(function (): void {
    config([
        'talkto.service' => 'target-app',
        'talkto.security.require_signature' => true,
        'talkto.security.signature_version' => 'v1',
        'talkto.security.accept_versions' => ['v1', 'v2'],
        'talkto.incoming.source-app' => [
            'secret' => 'test-secret',
            'allowed_commands' => [
                'domain.command' => [
                    'driver' => 'none',
                ],
            ],
        ],
    ]);
});

test('v1 security remains the default and missing version header verifies as v1', function (): void {
    config([
        'talkto.outgoing.target-app' => [
            'url' => 'https://target.test',
            'secret' => 'test-secret',
        ],
    ]);

    $message = compatibilityOutgoingModel('compat-v1-default');
    $headers = app(TalktoOutgoingEnvelopeBuilder::class)->buildHeaders($message);

    expect(config('talkto.security.signature_version'))->toBe('v1')
        ->and($headers)->not->toHaveKey('X-Talkto-Signature-Version');

    $payload = ['id' => 'compat-v1-incoming'];
    $envelope = compatibilityEnvelope('compat-v1-incoming', $payload);
    $timestamp = now()->toIso8601String();
    $headers = [
        'X-Talkto-Signature' => app(TalktoSigner::class)->sign(
            'compat-v1-incoming',
            $timestamp,
            'source-app',
            'target-app',
            'domain.command',
            $envelope['payload_hash'],
            'test-secret'
        ),
        'X-Talkto-Timestamp' => $timestamp,
        'X-Talkto-Message-Id' => 'compat-v1-incoming',
    ];

    expect(app(TalktoSignatureVerifier::class)->verifyEnvelope($envelope, $headers))->toMatchArray([
        'ok' => true,
        'status' => 200,
    ]);
});

test('v2 signing is opt in and accepted versions control verification', function (): void {
    config([
        'talkto.security.signature_version' => 'v2',
        'talkto.outgoing.target-app' => [
            'url' => 'https://target.test',
            'secret' => 'test-secret',
        ],
    ]);

    $headers = app(TalktoOutgoingEnvelopeBuilder::class)->buildHeaders(compatibilityOutgoingModel('compat-v2-outgoing'));

    expect($headers['X-Talkto-Signature-Version'])->toBe('v2')
        ->and($headers)->toHaveKey('X-Talkto-Nonce');

    config(['talkto.security.accept_versions' => ['v2']]);
    $payload = ['id' => 'compat-v1-disabled'];

    expect(app(TalktoSignatureVerifier::class)->verifyEnvelope(
        compatibilityEnvelope('compat-v1-disabled', $payload),
        compatibilityV1Headers('compat-v1-disabled', $payload)
    ))->toMatchArray([
        'ok' => false,
        'error' => 'unsupported_signature_version',
    ]);
});

test('legacy outgoing target config shapes remain compatible', function (): void {
    $urlTarget = new TalktoOutgoingTarget('url-target', [
        'url' => 'https://peer.test',
        'endpoint' => '/api/talkto/receive',
        'secret' => 'secret',
        'headers' => ['X-Custom' => 'yes'],
        'timeout' => 7,
    ]);
    $baseUrlTarget = new TalktoOutgoingTarget('base-url-target', [
        'base_url' => 'https://base.test/',
        'endpoint' => 'receive',
        'signing_secret' => 'secret',
        'timeout_seconds' => 9,
    ]);
    $legacyTarget = new TalktoOutgoingTarget('legacy-target', [
        'rm_url' => 'https://legacy.test/full/path',
        'secret' => 'secret',
        'endpoint' => '/ignored',
    ]);

    expect($urlTarget->endpointUrl())->toBe('https://peer.test/api/talkto/receive')
        ->and($urlTarget->headers())->toBe(['X-Custom' => 'yes'])
        ->and($urlTarget->timeout())->toBe(7)
        ->and($baseUrlTarget->endpointUrl())->toBe('https://base.test/receive')
        ->and($baseUrlTarget->secret())->toBe('secret')
        ->and($baseUrlTarget->timeout())->toBe(9)
        ->and($legacyTarget->endpointUrl())->toBe('https://legacy.test/full/path');
});

test('aliases resolve to canonical targets and stored messages use the canonical name', function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'source-app',
        'talkto.aliases.billing' => 'billing-service',
        'talkto.outgoing.billing-service' => [
            'url' => 'https://billing.test',
            'secret' => 'secret',
        ],
    ]);

    $message = app(TalktoOutgoingMessageFactory::class)->create(
        target: 'billing',
        command: 'domain.command',
        payload: ['id' => 1],
        options: ['message_id' => 'compat-alias-message']
    );

    expect(app(TalktoOutgoingTargetRegistryContract::class)->get('billing')->name())->toBe('billing-service')
        ->and($message->target_service)->toBe('billing-service');
});

test('programmatic outgoing target registration overrides config for canonical names', function (): void {
    config([
        'talkto.aliases.peer' => 'peer-service',
        'talkto.outgoing.peer-service' => [
            'url' => 'https://config.test',
            'secret' => 'config-secret',
        ],
    ]);

    $registry = app(TalktoOutgoingTargetRegistryContract::class);
    $registry->register('peer-service', [
        'url' => 'https://registered.test',
        'secret' => 'registered-secret',
    ]);

    $target = $registry->get('peer');

    expect($target->endpointUrl())->toBe('https://registered.test/api/talkto/receive')
        ->and($target->secret())->toBe('registered-secret');
});

test('incoming command compatibility covers null config missing commands skip strategy and handlers', function (): void {
    config([
        'talkto.incoming.source-app.allowed_commands' => [
            'null.command' => null,
            'config.handler' => [
                'handler' => CompatibilityAuditHandler::class,
            ],
        ],
    ]);

    $resolver = app(TalktoIncomingCommandResolver::class);

    expect($resolver->resolve(compatibilityIncomingMessage('null.command')))->toBeInstanceOf(NoopIncomingCommandHandler::class)
        ->and($resolver->resolve(compatibilityIncomingMessage('config.handler')))->toBeInstanceOf(CompatibilityAuditHandler::class);

    expect(fn () => $resolver->resolve(compatibilityIncomingMessage('missing.command')))
        ->toThrow(UnknownTalktoIncomingCommand::class);

    config(['talkto.incoming.unknown_command_strategy' => 'skip']);

    expect($resolver->resolve(compatibilityIncomingMessage('missing.command')))->toBeInstanceOf(SkippedIncomingCommandHandler::class);
});

test('incoming handler registry supports programmatic registration and rejects invalid handlers', function (): void {
    $registry = app(TalktoIncomingHandlerRegistryContract::class);
    $registry->register('programmatic.handler', CompatibilityAuditHandler::class);

    expect($registry->resolve('programmatic.handler'))->toBeInstanceOf(CompatibilityAuditHandler::class);

    $registry->register('invalid.handler', stdClass::class);

    expect(fn () => $registry->resolve('invalid.handler'))->toThrow(InvalidTalktoIncomingHandler::class);
});

test('commands options publish tags and required config keys remain stable', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('talkto:retry-failed')
        ->and($commands['talkto:retry-failed']->getDefinition()->hasOption('direction'))->toBeTrue()
        ->and($commands['talkto:retry-failed']->getDefinition()->hasOption('limit'))->toBeTrue()
        ->and($commands['talkto:retry-failed']->getDefinition()->hasOption('dry-run'))->toBeTrue()
        ->and($commands)->toHaveKey('talkto:dlq-reprocess')
        ->and($commands['talkto:dlq-reprocess']->getDefinition()->hasOption('id'))->toBeTrue()
        ->and($commands['talkto:dlq-reprocess']->getDefinition()->hasOption('message-id'))->toBeTrue()
        ->and($commands['talkto:dlq-reprocess']->getDefinition()->hasOption('force'))->toBeTrue()
        ->and($commands)->toHaveKey('talkto:report')
        ->and($commands['talkto:report']->getDefinition()->hasOption('hours'))->toBeTrue()
        ->and($commands['talkto:report']->getDefinition()->hasOption('from'))->toBeTrue()
        ->and($commands['talkto:report']->getDefinition()->hasOption('to'))->toBeTrue()
        ->and($commands['talkto:report']->getDefinition()->hasOption('json'))->toBeTrue();

    foreach (['laravel-talkto-config', 'talkto-config', 'laravel-talkto-migrations', 'talkto-migrations'] as $tag) {
        expect(ServiceProvider::pathsToPublish(LaravelTalktoServiceProvider::class, $tag))->not->toBeEmpty();
    }

    foreach (['incoming', 'outgoing', 'aliases', 'retry', 'dead_letter', 'security', 'observability'] as $key) {
        expect(config("talkto.{$key}"))->not->toBeNull();
    }
});

function compatibilityEnvelope(string $messageId, array $payload): array
{
    return [
        'message_id' => $messageId,
        'source' => 'source-app',
        'target' => 'target-app',
        'command' => 'domain.command',
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'payload' => $payload,
    ];
}

function compatibilityV1Headers(string $messageId, array $payload): array
{
    $timestamp = now()->toIso8601String();
    $payloadHash = app(TalktoPayloadHasher::class)->hash($payload);

    return [
        'X-Talkto-Signature' => app(TalktoSigner::class)->sign(
            $messageId,
            $timestamp,
            'source-app',
            'target-app',
            'domain.command',
            $payloadHash,
            'test-secret'
        ),
        'X-Talkto-Timestamp' => $timestamp,
        'X-Talkto-Message-Id' => $messageId,
    ];
}

function compatibilityOutgoingModel(string $messageId): TalktoMessage
{
    $payload = ['id' => $messageId];

    return new TalktoMessage([
        'message_id' => $messageId,
        'source_service' => 'target-app',
        'target_service' => 'target-app',
        'command' => 'domain.command',
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'payload' => $payload,
        'schema_version' => 1,
    ]);
}

function compatibilityIncomingMessage(string $command): TalktoMessage
{
    return new TalktoMessage([
        'message_id' => 'compat-'.$command,
        'direction' => 'incoming',
        'source_service' => 'source-app',
        'target_service' => 'target-app',
        'command' => $command,
    ]);
}

class CompatibilityAuditHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        return TalktoIncomingCommandResult::succeeded();
    }
}
