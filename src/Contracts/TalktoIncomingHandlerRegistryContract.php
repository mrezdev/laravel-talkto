<?php

namespace Ibake\TalktoReliable\Contracts;

interface TalktoIncomingHandlerRegistryContract
{
    public function register(string $command, string $handlerClass): void;

    public function has(string $command): bool;

    public function resolve(string $command): ?TalktoIncomingCommandHandler;

    public function all(): array;
}
