<?php

namespace Ibake\TalktoReliable\Handlers;

use Ibake\TalktoReliable\Contracts\TalktoIncomingCommandHandler;
use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Services\TalktoIncomingCommandResult;

class SkippedIncomingCommandHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        return TalktoIncomingCommandResult::skipped(
            reason: 'unknown_command',
            meta: [
                'command' => $message->command,
                'source' => $message->source_service,
            ]
        );
    }
}
