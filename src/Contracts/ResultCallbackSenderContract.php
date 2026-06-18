<?php

namespace Ibake\TalktoReliable\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ResultCallbackSenderContract
{
    public function sendResult(Model $message, IncomingCommandResultContract $result, array $options = []): mixed;
}
