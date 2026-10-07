<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoIncomingHandler;
use Mrezdev\LaravelTalkto\Exceptions\UnknownTalktoIncomingCommand;
use Mrezdev\LaravelTalkto\Handlers\NoopIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Handlers\SkippedIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoFlowFactory;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResolver;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingTarget;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;

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

test('legacy v1 opt in keeps missing version header verification compatible', function (): void {
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

test('accepted versions control legacy v1 and v2 verification', function (): void {
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

    expect($urlTarget->endpointUrl())->toBe('https://peer.test/api/talkto/receive')
        ->and($urlTarget->headers())->toBe(['X-Custom' => 'yes'])
        ->and($urlTarget->timeout())->toBe(7)
        ->and($baseUrlTarget->endpointUrl())->toBe('https://base.test/receive')
        ->and($baseUrlTarget->secret())->toBe('secret')
        ->and($baseUrlTarget->timeout())->toBe(9);
});

test('aliases resolve to canonical targets and stored messages use the canonical name', function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    expect($this->artisan('migrate')->run())->toBe(0);
    Queue::fake();

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
        options: ['message_id' => 'compat-alias-message', 'correlation_id' => 'compat-correlation']
    );

    expect(app(TalktoOutgoingTargetRegistryContract::class)->get('billing')->name())->toBe('billing-service')
        ->and($message->target_service)->toBe('billing-service')
        ->and($message->source_service)->toBe('source-app')
        ->and($message->direction)->toBe('outgoing')
        ->and($message->command)->toBe('domain.command')
        ->and($message->message_id)->toBe('compat-alias-message')
        ->and($message->correlation_id)->toBe('compat-correlation')
        ->and($message->payload)->toBe(['id' => 1])
        ->and($message->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash(['id' => 1]))
        ->and($message->source_action_status)->toBe('succeeded_assumed')
        ->and($message->transport_status)->toBe('pending')
        ->and($message->overall_status)->toBe('waiting_to_send')
        ->and($message->attempts)->toBe(0)
        ->and($message->max_attempts)->toBe(5);

    $event = TalktoEvent::query()->where('message_id', $message->message_id)->where('event_type', 'message_created')->sole();
    expect($event->talkto_message_id)->toBe($message->id)
        ->and($event->service_name)->toBe('source-app')
        ->and($event->old_status)->toBeNull()
        ->and($event->new_status)->toBe('waiting_to_send');
    Queue::assertNothingPushed();

    Http::fake();
    $queued = app(TalktoFlowFactory::class)->flow('compat-flow')->to('billing')->command('domain.command')
        ->payload(['id' => 2])->correlationId('compat-flow-correlation')->idempotencyKey('compat-flow-key')->send();
    expect(Str::isUuid($queued->message_id))->toBeTrue()
        ->and($queued->correlation_id)->toBe('compat-flow-correlation')
        ->and($queued->idempotency_key)->toBe('compat-flow-key')
        ->and($queued->source_service)->toBe('source-app')
        ->and($queued->target_service)->toBe('billing-service')
        ->and($queued->payload)->toBe(['id' => 2])
        ->and($queued->overall_status)->toBe('waiting_to_send');
    Queue::assertPushed(SendTalktoMessage::class, 1);
    Queue::assertPushed(SendTalktoMessage::class, fn (SendTalktoMessage $job): bool => $job->talktoMessageId === $queued->id && $job->afterCommit === true);
    Http::assertNothingSent();
});

test('transactional outgoing flow records source results and queues only after success', function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    expect($this->artisan('migrate')->run())->toBe(0);
    config(['talkto.outgoing.peer' => ['url' => 'https://peer.test', 'secret' => 'test-secret']]);
    Queue::fake();
    Http::fake();

    $flow = app(TalktoFlowFactory::class)->flow('compat-transactional')->to('peer')->command('domain.command');
    $message = $flow->run(function (): array {
        expect(DB::transactionLevel())->toBeGreaterThan(0);

        return ['payload' => ['id' => 3], 'result' => ['created' => true], 'meta' => ['operation' => 'create']];
    });
    $event = TalktoEvent::query()->where('message_id', $message->message_id)->where('event_type', 'message_created')->sole();
    expect($message->source_action_status)->toBe('succeeded')
        ->and($message->payload)->toBe(['id' => 3])
        ->and(Str::isUuid($message->correlation_id))->toBeTrue()
        ->and($event->meta)->toMatchArray(['flow_name' => 'compat-transactional', 'source_result' => ['created' => true], 'source_meta' => ['operation' => 'create']]);
    Queue::assertPushed(SendTalktoMessage::class, 1);
    Queue::assertPushed(SendTalktoMessage::class, fn (SendTalktoMessage $job): bool => $job->talktoMessageId === $message->id && $job->afterCommit === true);

    Queue::fake();
    expect(fn () => app(TalktoFlowFactory::class)->flow('compat-source-failure')->to('peer')->command('domain.command')
        ->option('message_id', 'compat-source-failed')->run(fn () => throw new RuntimeException('Source failed.')))->toThrow(RuntimeException::class, 'Source failed.');
    $failed = TalktoMessage::query()->where('message_id', 'compat-source-failed')->sole();
    expect($failed->source_action_status)->toBe('failed')
        ->and($failed->transport_status)->toBeNull()
        ->and($failed->overall_status)->toBe('failed')
        ->and($failed->last_error)->toBe('Source failed.');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
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
    $requiredCommands = [
        'talkto:make-incoming' => [['service', 'talktoCommand'], ['force', 'dry-run'], ['base-path' => 'app/Talkto', 'base-namespace' => 'App\\Talkto']],
        'talkto:make-integration' => [['service', 'talktoCommand'], ['outgoing', 'incoming', 'transactional', 'force', 'dry-run'], ['base-path' => 'app/Talkto', 'base-namespace' => 'App\\Talkto']],
        'talkto:make-outgoing' => [['service', 'talktoCommand'], ['force', 'dry-run', 'transactional'], ['base-path' => 'app/Talkto', 'base-namespace' => 'App\\Talkto']],
        'talkto:retry-failed' => [[], ['dry-run'], ['direction' => 'all', 'limit' => '100']],
        'talkto:dlq-reprocess' => [[], ['dry-run', 'force'], ['id' => null, 'message-id' => null, 'direction' => 'all', 'limit' => '50']],
        'talkto:repair-payload-hash' => [['message_id'], ['confirm'], ['reason' => null]],
        'talkto:report' => [[], ['json'], ['hours' => null, 'from' => null, 'to' => null, 'direction' => 'all', 'limit' => null]],
        'talkto:trace' => [[], ['json', 'payload'], ['correlation' => null, 'limit' => '100']],
        'talkto:security-audit' => [[], ['json'], ['fail-on' => null]],
        'talkto:audit-security' => [[], ['json'], []],
        'talkto:prune' => [[], ['dry-run'], ['type' => 'all', 'older-than' => null, 'limit' => '100']],
        'talkto:recover-stale' => [[], ['dry-run'], ['direction' => null, 'older-than' => null, 'limit' => '100']],
    ];

    foreach ($requiredCommands as $name => [$arguments, $flags, $valueOptions]) {
        expect($commands)->toHaveKey($name);
        $definition = $commands[$name]->getDefinition();
        foreach ($arguments as $position => $argument) {
            expect($definition->getArgument($position)->getName())->toBe($argument)
                ->and($definition->getArgument($argument)->isRequired())->toBeTrue();
        }
        foreach ($flags as $flag) {
            expect($definition->getOption($flag)->acceptValue())->toBeFalse()
                ->and($definition->getOption($flag)->getDefault())->toBeFalse();
        }
        foreach ($valueOptions as $option => $default) {
            expect($definition->getOption($option)->acceptValue())->toBeTrue()
                ->and($definition->getOption($option)->getDefault())->toBe($default);
        }
    }
    expect($commands['talkto:trace']->getDefinition()->getArgument(0)->getName())->toBe('message_id')
        ->and($commands['talkto:trace']->getDefinition()->getArgument('message_id')->isRequired())->toBeFalse();

    foreach (['laravel-talkto-config', 'talkto-config', 'laravel-talkto-migrations', 'talkto-migrations', 'talkto-panel-views'] as $tag) {
        expect(ServiceProvider::pathsToPublish(LaravelTalktoServiceProvider::class, $tag))->not->toBeEmpty();
    }

    foreach (['service', 'aliases', 'models', 'database', 'storage', 'security', 'http', 'callbacks', 'migrations', 'routes', 'jobs', 'builders', 'retry', 'dead_letter', 'observability', 'recovery', 'retention', 'panel', 'outgoing', 'incoming'] as $key) {
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
