<?php

namespace Mrezdev\LaravelTalkto\Contracts;

use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;

/**
 * Outgoing HTTP transport contract for host applications that need custom IO.
 */
interface TalktoHttpClient
{
    public function post(
        string $url,
        array $headers,
        array $envelope,
        int $timeout
    ): TalktoHttpResponse;
}
