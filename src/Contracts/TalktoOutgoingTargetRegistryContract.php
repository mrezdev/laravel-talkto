<?php

namespace Ibake\TalktoReliable\Contracts;

use Ibake\TalktoReliable\Services\TalktoOutgoingTarget;

interface TalktoOutgoingTargetRegistryContract
{
    public function register(string $name, array|TalktoOutgoingTarget $target): void;

    public function has(string $name): bool;

    public function get(string $name): TalktoOutgoingTarget;

    public function resolve(string $name): ?TalktoOutgoingTarget;

    public function all(): array;
}
