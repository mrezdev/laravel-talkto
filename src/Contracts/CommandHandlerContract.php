<?php

namespace Mrezdev\LaravelTalkto\Contracts;

use Mrezdev\LaravelTalkto\Models\TalktoMessage;

interface CommandHandlerContract
{
    public function handle(TalktoMessage $message): IncomingCommandResultContract;
}
