<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoOutgoingTarget;
use Mrezdev\LaravelTalkto\Exceptions\UnknownTalktoOutgoingTarget;

class TalktoOutgoingTargetRegistry implements TalktoOutgoingTargetRegistryContract
{
    private array $targets = [];

    public function register(string $name, array|TalktoOutgoingTarget $target): void
    {
        $name = trim($name);

        if ($name === '') {
            return;
        }

        $this->targets[$name] = $target;
    }

    public function has(string $name): bool
    {
        return $this->resolve($name) !== null;
    }

    public function get(string $name): TalktoOutgoingTarget
    {
        $target = $this->resolve($name);

        if (! $target) {
            throw UnknownTalktoOutgoingTarget::forTarget($name);
        }

        return $target;
    }

    public function resolve(string $name): ?TalktoOutgoingTarget
    {
        $resolvedName = $this->resolveAlias($name);
        $targets = $this->all();

        if (! array_key_exists($resolvedName, $targets)) {
            return null;
        }

        $target = $targets[$resolvedName];

        if ($target instanceof TalktoOutgoingTarget) {
            return $target;
        }

        if (! is_array($target)) {
            throw InvalidTalktoOutgoingTarget::forTarget($resolvedName, 'configuration must be an array');
        }

        return new TalktoOutgoingTarget($resolvedName, $target);
    }

    public function all(): array
    {
        $configured = config('talkto.outgoing', []);
        $configured = is_array($configured) ? $configured : [];

        return array_merge($configured, $this->targets);
    }

    private function resolveAlias(string $name): string
    {
        $alias = config("talkto.aliases.{$name}");

        return is_string($alias) && $alias !== '' ? $alias : $name;
    }
}
