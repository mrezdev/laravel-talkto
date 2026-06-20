<?php

namespace Mrezdev\LaravelTalkto\Services;

use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract;
use Mrezdev\LaravelTalkto\Exceptions\UnknownTalktoIncomingCommand;
use Mrezdev\LaravelTalkto\Handlers\NoopIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Handlers\SkippedIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

/**
 * @internal Runtime resolver behind incoming command processing.
 */
class TalktoIncomingCommandResolver
{
    public function __construct(private readonly TalktoIncomingHandlerRegistryContract $registry) {}

    public function resolve(TalktoMessage $message): TalktoIncomingCommandHandler
    {
        $registered = $this->registry->resolve((string) $message->command);

        if ($registered) {
            return $registered;
        }

        [$commandConfigured, $config] = $this->commandConfigState($message);

        if (is_array($config) && isset($config['handler'])) {
            return app(TalktoIncomingHandlerRegistry::class)->resolveHandlerClass((string) $config['handler']);
        }

        $driver = $this->driverForConfig($commandConfigured, $config);

        if ($driver === 'none') {
            return app(NoopIncomingCommandHandler::class);
        }

        if (! $commandConfigured && $this->unknownCommandStrategy() === 'skip') {
            return app(SkippedIncomingCommandHandler::class);
        }

        if (! $commandConfigured) {
            throw UnknownTalktoIncomingCommand::forCommand((string) $message->command, $message->source_service);
        }

        throw new InvalidArgumentException("Unsupported Talkto incoming command driver [{$driver}].");
    }

    public function commandConfig(TalktoMessage $message): ?array
    {
        [, $config] = $this->commandConfigState($message);

        return $config;
    }

    public function driverFor(TalktoMessage $message): ?string
    {
        [$commandConfigured, $config] = $this->commandConfigState($message);

        return $this->driverForConfig($commandConfigured, $config);
    }

    private function commandConfigState(TalktoMessage $message): array
    {
        $commands = config("talkto.incoming.{$message->source_service}.allowed_commands", []);
        $commands = is_array($commands) ? $commands : [];

        if ($this->isList($commands)) {
            return in_array((string) $message->command, $commands, true)
                ? [true, null]
                : [false, null];
        }

        if (! array_key_exists((string) $message->command, $commands)) {
            return [false, null];
        }

        $config = $commands[(string) $message->command];

        if ($config === null) {
            return [true, null];
        }

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unsupported Talkto incoming command driver [{$config}].");
        }

        return [true, $config];
    }

    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function driverForConfig(bool $commandConfigured, ?array $config): ?string
    {
        if ($commandConfigured && $config === null) {
            return 'none';
        }

        return $config['driver'] ?? null;
    }

    private function unknownCommandStrategy(): string
    {
        $strategy = config('talkto.incoming.unknown_command_strategy', 'fail');

        return $strategy === 'skip' ? 'skip' : 'fail';
    }
}
