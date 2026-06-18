<?php

namespace Mrezdev\LaravelTalkto\Handlers;

use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

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
