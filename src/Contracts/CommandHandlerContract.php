<?php

namespace Mrezdev\LaravelTalkto\Contracts;

use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

interface CommandHandlerContract
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult;
}
