<?php

namespace Ibake\TalktoReliable\Contracts;

interface ResultCallbackReceiverContract
{
    public function receiveResult(array $envelope, array $headers = []): mixed;
}
