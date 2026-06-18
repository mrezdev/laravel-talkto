<?php

namespace Mrezdev\LaravelTalkto\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ResultCallbackSenderContract
{
    public function sendResult(Model $message, IncomingCommandResultContract $result, array $options = []): mixed;
}
