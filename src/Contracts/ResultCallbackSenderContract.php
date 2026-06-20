<?php

namespace Mrezdev\LaravelTalkto\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Sends result callbacks after an incoming command has been handled.
 */
interface ResultCallbackSenderContract
{
    public function sendResult(Model $message, IncomingCommandResultContract $result, array $options = []): mixed;
}
