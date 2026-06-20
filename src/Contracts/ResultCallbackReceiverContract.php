<?php

namespace Mrezdev\LaravelTalkto\Contracts;

/**
 * Receives signed result callback envelopes for host callback integrations.
 */
interface ResultCallbackReceiverContract
{
    public function receiveResult(array $envelope, array $headers = []): mixed;
}
