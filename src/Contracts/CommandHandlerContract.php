<?php

namespace Ibake\TalktoReliable\Contracts;

use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Services\TalktoIncomingCommandResult;

interface CommandHandlerContract
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult;
}
