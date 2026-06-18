<?php

namespace Ibake\TalktoReliable\Exceptions;

class InvalidTalktoOutgoingTarget extends TalktoException
{
    public static function forTarget(string $target, string $reason): self
    {
        return new self("Talkto outgoing target [{$target}] is invalid: {$reason}.");
    }
}
