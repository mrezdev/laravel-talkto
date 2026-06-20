<?php

namespace Mrezdev\LaravelTalkto\Contracts;

use Mrezdev\LaravelTalkto\Models\TalktoMessage;

/**
 * Handles an incoming Talkto message and returns a normalized package result.
 */
interface CommandHandlerContract
{
    public function handle(TalktoMessage $message): IncomingCommandResultContract;
}
