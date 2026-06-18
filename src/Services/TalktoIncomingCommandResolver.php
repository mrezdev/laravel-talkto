<?php

namespace Ibake\TalktoReliable\Services;

use Ibake\TalktoReliable\Contracts\TalktoIncomingCommandHandler;
use Ibake\TalktoReliable\Handlers\NoopIncomingCommandHandler;
use Ibake\TalktoReliable\Models\TalktoMessage;
use InvalidArgumentException;

class TalktoIncomingCommandResolver
{
    public function resolve(TalktoMessage $message): TalktoIncomingCommandHandler
    {
        $driver = $this->driverFor($message);

        if ($driver === 'none') {
            return app(NoopIncomingCommandHandler::class);
        }

        if ($driver === 'artisan') {
            throw new InvalidArgumentException('Talkto artisan driver is not implemented yet.');
        }

        if ($driver === 'handler') {
            throw new InvalidArgumentException('Talkto handler driver is not implemented yet.');
        }

        throw new InvalidArgumentException("Unsupported Talkto incoming command driver [{$driver}].");
    }

    public function commandConfig(TalktoMessage $message): ?array
    {
        $missing = new \stdClass;
        $config = config(
            "talkto.incoming.{$message->source_service}.allowed_commands.{$message->command}",
            $missing
        );

        if ($config === $missing) {
            throw new InvalidArgumentException(
                "Talkto command is not configured for source [{$message->source_service}] and command [{$message->command}]."
            );
        }

        if ($config === null) {
            return null;
        }

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unsupported Talkto incoming command driver [{$config}].");
        }

        return $config;
    }

    public function driverFor(TalktoMessage $message): ?string
    {
        $config = $this->commandConfig($message);

        return $config['driver'] ?? 'none';
    }
}
