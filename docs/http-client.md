# HTTP Client Extension

Outgoing Talkto messages use `Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient` for the transport call. The default binding is `Mrezdev\LaravelTalkto\Services\LaravelTalktoHttpClient`, which uses Laravel's HTTP client and preserves the package's existing URL, headers, body, timeout, response, and exception behavior.

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
