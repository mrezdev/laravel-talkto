<?php

use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoIncomingHandler;
use Mrezdev\LaravelTalkto\Handlers\NoopIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResolver;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingHandlerRegistry;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.retry.enabled' => true,
        'talkto.retry.incoming_enabled' => false,
        'talkto.incoming.handlers' => [],
        'talkto.incoming.unknown_command_strategy' => 'fail',
    ]);

    IncomingHandlerRegistryCountingHandler::$calls = 0;
});

test('registry contract resolves the same singleton behavior as concrete registry', function (): void {
    $contract = app(TalktoIncomingHandlerRegistryContract::class);
    $concrete = app(TalktoIncomingHandlerRegistry::class);

    $contract->register('registry.contract', IncomingHandlerRegistryConfigHandler::class);

    expect($contract)->toBe($concrete)
        ->and($concrete->resolve('registry.contract'))->toBeInstanceOf(IncomingHandlerRegistryConfigHandler::class);
});

test('registry resolves config handlers through the container', function (): void {
    config(['talkto.incoming.handlers' => [
        'registry.config' => IncomingHandlerRegistryConfigHandler::class,
    ]]);

    $handler = app(TalktoIncomingHandlerRegistry::class)->resolve('registry.config');

    expect($handler)->toBeInstanceOf(IncomingHandlerRegistryConfigHandler::class)
        ->and($handler)->toBeInstanceOf(TalktoIncomingCommandHandler::class);
});

test('resolver depends on registry contract and still resolves config handlers', function (): void {
    config(['talkto.incoming.handlers' => [
        'registry.resolver.config' => IncomingHandlerRegistryConfigHandler::class,
    ]]);

    $parameter = (new ReflectionClass(TalktoIncomingCommandResolver::class))
        ->getConstructor()
        ?->getParameters()[0];
    $message = ihrIncomingMessage('ihr-resolver-config', 'registry.resolver.config');

    expect($parameter?->getType()?->getName())->toBe(TalktoIncomingHandlerRegistryContract::class)
        ->and(app(TalktoIncomingCommandResolver::class)->resolve($message))->toBeInstanceOf(IncomingHandlerRegistryConfigHandler::class);
});

test('registry supports programmatic handler registration', function (): void {
    app()->singleton(IncomingHandlerRegistryDependency::class, fn () => new IncomingHandlerRegistryDependency('container'));

    $registry = app(TalktoIncomingHandlerRegistry::class);
    $registry->register('registry.programmatic', IncomingHandlerRegistryProgrammaticHandler::class);

    $handler = $registry->resolve('registry.programmatic');

    expect($handler)->toBeInstanceOf(IncomingHandlerRegistryProgrammaticHandler::class)
        ->and($handler->dependency->value)->toBe('container');
});

test('registry rejects invalid handler classes', function (): void {
    config(['talkto.incoming.handlers' => [
        'registry.invalid' => IncomingHandlerRegistryInvalidHandler::class,
    ]]);

    app(TalktoIncomingHandlerRegistry::class)->resolve('registry.invalid');
})->throws(InvalidTalktoIncomingHandler::class);

test('known incoming command executes registered handler once', function (): void {
    app(TalktoIncomingHandlerRegistry::class)->register('registry.known', IncomingHandlerRegistryCountingHandler::class);
    $message = ihrIncomingMessage('ihr-known-once', 'registry.known');

    (new ProcessIncomingTalktoMessage($message->id))->handle();
    (new ProcessIncomingTalktoMessage($message->id))->handle();

    expect(IncomingHandlerRegistryCountingHandler::$calls)->toBe(1)
        ->and($message->fresh()->overall_status)->toBe('succeeded');
});

test('unknown incoming command fails by default through existing failure path', function (): void {
    $message = ihrIncomingMessage('ihr-unknown-fail', 'registry.unknown');

    (new ProcessIncomingTalktoMessage($message->id))->handle();

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_retryable')
        ->and($message->destination_action_status)->toBe('failed_retryable')
        ->and($message->last_error)->toContain('No Talkto incoming handler is registered')
        ->and(TalktoEvent::query()->where('message_id', 'ihr-unknown-fail')->where('event_type', 'destination_processing_failed')->exists())->toBeTrue();
});

test('explicit null allowed command config uses noop handler', function (): void {
    config(['talkto.incoming.source.allowed_commands' => [
        'domain.command' => null,
    ]]);
    $message = ihrIncomingMessage('ihr-explicit-null', 'domain.command');

    expect(app(TalktoIncomingCommandResolver::class)->resolve($message))->toBeInstanceOf(NoopIncomingCommandHandler::class);

    (new ProcessIncomingTalktoMessage($message->id))->handle();

    expect($message->fresh()->overall_status)->toBe('succeeded')
        ->and($message->fresh()->destination_action_status)->toBe('succeeded')
        ->and($message->fresh()->last_error)->toBeNull();
});

test('indexed allowed command config uses noop handler when no registry handler exists', function (): void {
    config(['talkto.incoming.source.allowed_commands' => [
        'orders.mark-paid',
        'invoices.sync-status',
    ]]);
    $message = ihrIncomingMessage('ihr-indexed-noop', 'orders.mark-paid');

    expect(app(TalktoIncomingCommandResolver::class)->resolve($message))->toBeInstanceOf(NoopIncomingCommandHandler::class);
});

test('indexed allowed command processes successfully through noop handler', function (): void {
    config(['talkto.incoming.source.allowed_commands' => [
        'orders.mark-paid',
        'invoices.sync-status',
    ]]);
    $message = ihrIncomingMessage('ihr-indexed-success', 'invoices.sync-status');

    (new ProcessIncomingTalktoMessage($message->id))->handle();

    expect($message->fresh()->overall_status)->toBe('succeeded')
        ->and($message->fresh()->destination_action_status)->toBe('succeeded')
        ->and($message->fresh()->last_error)->toBeNull();
});

test('indexed unlisted command still fails by default', function (): void {
    config(['talkto.incoming.source.allowed_commands' => [
        'orders.mark-paid',
    ]]);
    $message = ihrIncomingMessage('ihr-indexed-unlisted', 'orders.cancelled');

    (new ProcessIncomingTalktoMessage($message->id))->handle();

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_retryable')
        ->and($message->destination_action_status)->toBe('failed_retryable')
        ->and($message->last_error)->toContain('No Talkto incoming handler is registered');
});

test('registry handler takes priority over indexed allowed command config', function (): void {
    config(['talkto.incoming.source.allowed_commands' => [
        'registry.indexed',
    ]]);
    app(TalktoIncomingHandlerRegistry::class)->register('registry.indexed', IncomingHandlerRegistryCountingHandler::class);
    $message = ihrIncomingMessage('ihr-indexed-registry-priority', 'registry.indexed');

    expect(app(TalktoIncomingCommandResolver::class)->resolve($message))->toBeInstanceOf(IncomingHandlerRegistryCountingHandler::class);

    (new ProcessIncomingTalktoMessage($message->id))->handle();

    expect(IncomingHandlerRegistryCountingHandler::$calls)->toBe(1)
        ->and($message->fresh()->overall_status)->toBe('succeeded');
});

test('unknown incoming command can be skipped explicitly', function (): void {
    config(['talkto.incoming.unknown_command_strategy' => 'skip']);
    $message = ihrIncomingMessage('ihr-unknown-skip', 'registry.unknown');

    (new ProcessIncomingTalktoMessage($message->id))->handle();

    expect($message->fresh()->overall_status)->toBe('skipped')
        ->and($message->fresh()->destination_action_status)->toBe('skipped')
        ->and(TalktoEvent::query()->where('message_id', 'ihr-unknown-skip')->where('event_type', 'incoming_command_skipped')->exists())->toBeTrue();
});

test('terminal incoming messages do not execute registered handlers', function (): void {
    app(TalktoIncomingHandlerRegistry::class)->register('registry.terminal', IncomingHandlerRegistryCountingHandler::class);

    foreach (['succeeded', 'completed'] as $status) {
        $message = ihrIncomingMessage("ihr-terminal-{$status}", 'registry.terminal', [
            'destination_action_status' => $status,
            'overall_status' => $status,
        ]);

        (new ProcessIncomingTalktoMessage($message->id))->handle();
    }

    expect(IncomingHandlerRegistryCountingHandler::$calls)->toBe(0);
});

function ihrIncomingMessage(string $messageId, string $command, array $attributes = []): TalktoMessage
{
    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'direction' => 'incoming',
        'source_service' => 'source',
        'target_service' => 'testing',
        'command' => $command,
        'payload' => ['id' => $messageId],
        'payload_hash' => 'hash',
        'schema_version' => 1,
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'queued',
        'received_at' => now(),
    ], $attributes));
}

class IncomingHandlerRegistryConfigHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        return TalktoIncomingCommandResult::succeeded();
    }
}

class IncomingHandlerRegistryProgrammaticHandler implements TalktoIncomingCommandHandler
{
    public function __construct(public readonly IncomingHandlerRegistryDependency $dependency) {}

    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        return TalktoIncomingCommandResult::succeeded();
    }
}

class IncomingHandlerRegistryCountingHandler implements TalktoIncomingCommandHandler
{
    public static int $calls = 0;

    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        self::$calls++;

        return TalktoIncomingCommandResult::succeeded();
    }
}

class IncomingHandlerRegistryInvalidHandler {}

class IncomingHandlerRegistryDependency
{
    public function __construct(public readonly string $value) {}
}
