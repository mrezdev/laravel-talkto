<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Support\Facades\Http;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClientWithOptions;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;

/**
 * @internal Default transport behind TalktoHttpClient.
 */
class LaravelTalktoHttpClient implements TalktoHttpClientWithOptions
{
    public function __construct(
        private readonly ?TalktoJsonEncoder $json = null,
        private readonly ?TalktoEnvelopeFieldValidator $fieldValidator = null
    ) {}

    public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
    {
        return $this->postWithOptions($url, $headers, $envelope, $timeout);
    }

    public function postWithOptions(string $url, array $headers, array $envelope, int $timeout, array $options = []): TalktoHttpResponse
    {
        $this->validator()->validateTalktoHeaders($headers, [
            'signature_version_header_name' => config('talkto.security.signature_version_header', 'X-Talkto-Signature-Version'),
            'nonce_header_name' => config('talkto.security.nonce_header', 'X-Talkto-Nonce'),
        ]);

        $request = Http::withHeaders($headers)
            ->withBody($this->encoder()->encode($envelope), 'application/json')
            ->timeout($timeout);

        if ($options !== []) {
            $request = $request->withOptions($options);
        }

        $response = $request->post($url);

        return new TalktoHttpResponse(
            statusCode: $response->status(),
            body: $response->body(),
            headers: $response->headers(),
            successful: $response->successful(),
        );
    }

    private function encoder(): TalktoJsonEncoder
    {
        return $this->json ?? new TalktoJsonEncoder;
    }

    private function validator(): TalktoEnvelopeFieldValidator
    {
        return $this->fieldValidator ?? app(TalktoEnvelopeFieldValidator::class);
    }
}
