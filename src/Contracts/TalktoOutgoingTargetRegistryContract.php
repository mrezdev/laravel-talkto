<?php

namespace Mrezdev\LaravelTalkto\Contracts;

use Mrezdev\LaravelTalkto\Services\TalktoOutgoingTarget;

/**
 * Registry contract for programmatic outgoing target registration.
 */
interface TalktoOutgoingTargetRegistryContract
{
    public function register(string $name, array|TalktoOutgoingTarget $target): void;

    public function has(string $name): bool;

    public function get(string $name): TalktoOutgoingTarget;

    public function resolve(string $name): ?TalktoOutgoingTarget;

    public function all(): array;
}
