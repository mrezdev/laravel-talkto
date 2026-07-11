# HTTP Client Extension

Outgoing Talkto messages use `Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient` for the transport call. The default binding is `Mrezdev\LaravelTalkto\Services\LaravelTalktoHttpClient`, which uses Laravel's HTTP client and preserves the package's existing URL, headers, timeout, response, and exception behavior.

The default client receives the envelope as an array, then encodes the final raw request body with Talkto's deterministic JSON encoder and sends it as `Content-Type: application/json`. This keeps payload hashes and the actual HTTP body stable for valid JSON floats even when PHP-FPM, CLI, and queue workers have different `serialize_precision` settings.

Host applications can replace the client from their own service provider:

```php
use App\Talkto\CustomTalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;

public function register(): void
{
    $this->app->bind(TalktoHttpClient::class, CustomTalktoHttpClient::class);
}
```

A custom client receives the already-built endpoint URL, signed headers, envelope body, and timeout:

```php
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;

class CustomTalktoHttpClient implements TalktoHttpClient
{
    public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
    {
        // Send the request using the host-owned transport layer.

        return new TalktoHttpResponse(
            statusCode: 200,
            body: '{"received":true,"status":"queued"}',
            headers: [],
        );
    }
}
```

Common reasons to override the client:

- custom proxy routing
- custom TLS or certificate handling
- tracing or OpenTelemetry instrumentation
- circuit breakers
- custom transport logging
- test doubles

Keep the custom client focused on transport only. Envelope creation, signing, idempotency, retry decisions, status transitions, attempts, events, and business behavior remain package responsibilities. The client should return a `TalktoHttpResponse` compatible response or throw transport exceptions that the existing outgoing pipeline can handle.

If a custom client JSON-encodes the envelope itself, it should use deterministic encoding equivalent to the package default and keep numeric JSON values as numbers. The public `TalktoHttpClient` contract remains array-based for backward compatibility, so custom transports do not need a signature change for this hardening patch.
