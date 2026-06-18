<?php

namespace Mrezdev\LaravelTalkto\Handlers;

use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

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
