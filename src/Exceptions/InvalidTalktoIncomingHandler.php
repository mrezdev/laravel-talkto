<?php

namespace Ibake\TalktoReliable\Exceptions;

class InvalidTalktoIncomingHandler extends TalktoException
{
    public static function forHandler(string $handlerClass): self
    {
        return new self("Talkto incoming handler [{$handlerClass}] must implement TalktoIncomingCommandHandler.");
    }
}
