<?php

namespace Ibake\TalktoReliable\Services;

use Ibake\TalktoReliable\Contracts\TalktoIncomingCommandHandler;
use Ibake\TalktoReliable\Contracts\TalktoIncomingHandlerRegistryContract;
use Ibake\TalktoReliable\Exceptions\UnknownTalktoIncomingCommand;
use Ibake\TalktoReliable\Handlers\NoopIncomingCommandHandler;
use Ibake\TalktoReliable\Handlers\SkippedIncomingCommandHandler;
use Ibake\TalktoReliable\Models\TalktoMessage;
use InvalidArgumentException;

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
