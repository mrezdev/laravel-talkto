<?php

namespace Mrezdev\LaravelTalkto\Exceptions;

class UnknownTalktoOutgoingTarget extends TalktoException
{
    public static function forTarget(string $target): self
    {
        return new self("Talkto outgoing target [{$target}] is not configured.");
    }
}
