<?php

namespace Mrezdev\LaravelTalkto\Exceptions;

class UnknownTalktoIncomingCommand extends TalktoException
{
    public static function forCommand(string $command, ?string $source = null): self
    {
        $suffix = $source !== null && $source !== ''
            ? " from source [{$source}]"
            : '';

        return new self("No Talkto incoming handler is registered for command [{$command}]{$suffix}.");
    }
}
