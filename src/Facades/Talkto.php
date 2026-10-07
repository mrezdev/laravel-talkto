<?php

namespace Mrezdev\LaravelTalkto\Facades;

use Illuminate\Support\Facades\Facade;
use LogicException;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Testing\TalktoFake;

/**
 * Testing assertions for persisted outgoing Talkto messages.
 */
class Talkto extends Facade
{
    public static function fake(): void
    {
        static::swap(new TalktoFake);
    }

    public static function assertSent(string $target, string $command, ?callable $callback = null): void
    {
        self::testingFake()->assertSent($target, $command, $callback);
    }

    public static function assertNotSent(string $target, string $command, ?callable $callback = null): void
    {
        self::testingFake()->assertNotSent($target, $command, $callback);
    }

    public static function assertNothingSent(): void
    {
        self::testingFake()->assertNothingSent();
    }

    public static function assertSentTimes(string $target, string $command, int $times, ?callable $callback = null): void
    {
        self::testingFake()->assertSentTimes($target, $command, $times, $callback);
    }

    protected static function getFacadeAccessor(): string
    {
        return TalktoHttpClient::class;
    }

    private static function testingFake(): TalktoFake
    {
        $client = static::getFacadeRoot();

        if (! $client instanceof TalktoFake) {
            throw new LogicException('Call Talkto::fake() before using Talkto assertions.');
        }

        return $client;
    }
}
