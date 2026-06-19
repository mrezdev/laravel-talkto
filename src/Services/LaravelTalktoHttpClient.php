<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Support\Facades\Http;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;

class LaravelTalktoHttpClient implements TalktoHttpClient
{
    public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
    {
        $response = Http::withHeaders($headers)
            ->timeout($timeout)
            ->post($url, $envelope);

        return new TalktoHttpResponse(
            statusCode: $response->status(),
            body: $response->body(),
            headers: $response->headers(),
            successful: $response->successful(),
        );
    }
}
