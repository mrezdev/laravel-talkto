<?php

namespace Mrezdev\LaravelTalkto\Support;

use Closure;

/**
 * @internal No-op production hooks used by deterministic concurrency tests.
 */
final class TalktoDispatchTestHooks
{
    /**
     * @var array<string, list<Closure(array<string, mixed>): void>>
     */
    private static array $hooks = [];

    public static function set(string $name, callable $callback): void
    {
        self::$hooks[$name] = [Closure::fromCallable($callback)];
    }

    public static function push(string $name, callable $callback): void
    {
        self::$hooks[$name][] = Closure::fromCallable($callback);
    }

    public static function reset(?string $name = null): void
    {
        if ($name === null) {
            self::$hooks = [];

            return;
        }

        unset(self::$hooks[$name]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function fire(string $name, array $context = []): void
    {
        foreach (self::$hooks[$name] ?? [] as $callback) {
            $callback($context);
        }
    }
}
