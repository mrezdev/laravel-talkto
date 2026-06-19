<?php

namespace Mrezdev\LaravelTalkto\Contracts;

use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;

interface TalktoHttpClient
{
    public function post(
        string $url,
        array $headers,
        array $envelope,
        int $timeout
    ): TalktoHttpResponse;
}
