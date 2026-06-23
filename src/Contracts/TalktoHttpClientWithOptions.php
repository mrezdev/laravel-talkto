<?php

namespace Mrezdev\LaravelTalkto\Contracts;

use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;

/**
 * Optional outgoing HTTP transport contract for clients that accept package HTTP options.
 */
interface TalktoHttpClientWithOptions extends TalktoHttpClient
{
    public function postWithOptions(
        string $url,
        array $headers,
        array $envelope,
        int $timeout,
        array $options = []
    ): TalktoHttpResponse;
}
