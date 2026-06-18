<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoIncomingHandler;
use Illuminate\Contracts\Container\Container;

class TalktoIncomingHandlerRegistry implements TalktoIncomingHandlerRegistryContract
{
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $command, string $handlerClass): void
    {
        $command = trim($command);

        if ($command === '') {
            return;
        }

        $this->handlers[$command] = $handlerClass;
    }

    public function has(string $command): bool
    {
        return array_key_exists($command, $this->all());
    }

    public function resolve(string $command): ?TalktoIncomingCommandHandler
    {
        $handlerClass = $this->all()[$command] ?? null;

        if (! is_string($handlerClass) || $handlerClass === '') {
            return null;
        }

        return $this->resolveHandlerClass($handlerClass);
    }

    public function resolveHandlerClass(string $handlerClass): TalktoIncomingCommandHandler
    {
        if (! is_a($handlerClass, TalktoIncomingCommandHandler::class, true)) {
            throw InvalidTalktoIncomingHandler::forHandler($handlerClass);
        }

        $handler = $this->container->make($handlerClass);

        if (! $handler instanceof TalktoIncomingCommandHandler) {
            throw InvalidTalktoIncomingHandler::forHandler($handlerClass);
        }

        return $handler;
    }

    public function all(): array
    {
        $configured = config('talkto.incoming.handlers', []);
        $configured = is_array($configured) ? $configured : [];

        return array_merge($configured, $this->handlers);
    }
}
