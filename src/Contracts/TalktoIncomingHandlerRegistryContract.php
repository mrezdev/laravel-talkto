<?php

namespace Mrezdev\LaravelTalkto\Contracts;

/**
 * Registry contract for programmatic incoming command handler registration.
 */
interface TalktoIncomingHandlerRegistryContract
{
    public function register(string $command, string $handlerClass): void;

    public function has(string $command): bool;

    public function resolve(string $command): ?TalktoIncomingCommandHandler;

    public function all(): array;
}
