<?php

namespace Mrezdev\LaravelTalkto\Contracts;

interface ResultCallbackReceiverContract
{
    public function receiveResult(array $envelope, array $headers = []): mixed;
}
