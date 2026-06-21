<?php

namespace Mrezdev\LaravelTalkto\Support;

use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoNonce;

/**
 * @internal Resolves configured Talkto model classes with package fallbacks.
 */
class TalktoModelResolver
{
    /**
     * @return class-string<TalktoMessage>
     */
    public function message(): string
    {
        return $this->resolve('message', TalktoMessage::class);
    }

    /**
     * @return class-string<TalktoAttempt>
     */
    public function attempt(): string
    {
        return $this->resolve('attempt', TalktoAttempt::class);
    }

    /**
     * @return class-string<TalktoEvent>
     */
    public function event(): string
    {
        return $this->resolve('event', TalktoEvent::class);
    }

    /**
     * @return class-string<TalktoDeadLetter>
     */
    public function deadLetter(): string
    {
        return $this->resolve('dead_letter', TalktoDeadLetter::class);
    }

    /**
     * @return class-string<TalktoNonce>
     */
    public function nonce(): string
    {
        return $this->resolve('nonce', TalktoNonce::class);
    }

    /**
     * @template TModel of object
     *
     * @param  class-string<TModel>  $default
     * @return class-string<TModel>
     */
    private function resolve(string $key, string $default): string
    {
        $class = config("talkto.models.{$key}", $default);

        return is_string($class) && is_a($class, $default, true)
            ? $class
            : $default;
    }
}
