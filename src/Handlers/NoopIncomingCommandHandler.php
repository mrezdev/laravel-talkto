<?php

namespace Ibake\TalktoReliable\Handlers;

use Ibake\TalktoReliable\Contracts\TalktoIncomingCommandHandler;
use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Services\TalktoIncomingCommandResult;

class NoopIncomingCommandHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        return TalktoIncomingCommandResult::succeeded(
            result: [
                'driver' => 'none',
                'executed' => false,
            ],
            meta: [
                'command' => $message->command,
                'business_key' => $message->business_key,
            ]
        );
    }
}
