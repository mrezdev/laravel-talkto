<?php

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as LaravelHttpResponse;
use Illuminate\Support\Facades\Http;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoOutgoingTarget;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\LaravelTalktoHttpClient;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.retry.enabled' => true,
        'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.max_attempts' => 5,
        'talkto.retry.retryable_http_statuses' => [408, 425, 429],
        'talkto.retry.retry_server_errors' => true,
        'talkto.outgoing.peer' => [
            'base_url' => 'https://peer.test',
            'secret' => 'secret',
            'receive_endpoint' => '/api/talkto/receive',
            'callback_endpoint' => '/callbacks/talkto',
            'headers' => ['X-Custom' => 'custom'],
            'timeout' => 13,
        ],
    ]);
});

test('service provider binds talkto http client to the default laravel implementation', function (): void {
    expect(app(TalktoHttpClient::class))->toBeInstanceOf(LaravelTalktoHttpClient::class);
});

test('default laravel implementation sends url headers body and timeout', function (): void {
    $pending = Mockery::mock();

    Http::shouldReceive('withHeaders')
        ->once()
        ->with(['X-Test' => 'yes'])
        ->andReturn($pending);

    $pending->shouldReceive('timeout')
        ->once()
        ->with(17)
        ->andReturnSelf();

    $pending->shouldReceive('post')
        ->once()
        ->with('https://peer.test/api/talkto/receive', ['hello' => 'world'])
        ->andReturn(new LaravelHttpResponse(new PsrResponse(
            202,
            ['X-Reply' => ['accepted']],
            '{"accepted":true}'
        )));

    $response = app(LaravelTalktoHttpClient::class)->post(
        'https://peer.test/api/talkto/receive',
        ['X-Test' => 'yes'],
        ['hello' => 'world'],
        17
    );

    expect($response)->toBeInstanceOf(TalktoHttpResponse::class)
        ->and($response->status())->toBe(202)
        ->and($response->successful())->toBeTrue()
        ->and($response->body())->toBe('{"accepted":true}')
        ->and($response->headers())->toHaveKey('X-Reply')
        ->and($response->json('accepted'))->toBeTrue();
});

test('existing outgoing send succeeds using the default implementation', function (): void {
    Http::fake(fn (Request $request) => Http::response([
        'received' => true,
        'status' => 'accepted',
    ], 200));

    $message = httpClientOutgoingMessage('http-default-success');

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://peer.test/api/talkto/receive'
            && $request->hasHeader('X-Custom', 'custom')
            && $request->hasHeader('X-Talkto-Signature')
            && ($request->data()['payload_hash'] ?? null) === app(TalktoPayloadHasher::class)->hash(['id' => 'http-default-success']);
    });

    $message = $message->fresh();

    expect($message->overall_status)->toBe('destination_received')
        ->and($message->transport_status)->toBe('sent')
        ->and($message->last_http_status)->toBe(200)
        ->and($message->last_response)->toContain('accepted');
});

test('existing outgoing failure retry behavior works using the default implementation', function (): void {
    Http::fake(['*' => Http::response('temporary failure', 503)]);
    $message = httpClientOutgoingMessage('http-default-failure');

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_retryable')
        ->and($message->transport_status)->toBe('failed')
        ->and($message->last_http_status)->toBe(503)
        ->and($message->last_response)->toBe('temporary failure')
        ->and($message->next_retry_at)->not->toBeNull();
});

test('custom http client binding is used by the outgoing send pipeline', function (): void {
    $client = new RecordingTalktoHttpClient(new TalktoHttpResponse(
        statusCode: 200,
        body: '{"received":true,"status":"queued"}',
        headers: ['X-Custom-Client' => ['yes']],
    ));
    app()->instance(TalktoHttpClient::class, $client);

    $message = httpClientOutgoingMessage('http-custom-success');

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect($client->requests)->toHaveCount(1)
        ->and($client->requests[0]['url'])->toBe('https://peer.test/api/talkto/receive')
        ->and($client->requests[0]['headers']['X-Custom'])->toBe('custom')
        ->and($client->requests[0]['headers'])->toHaveKey('X-Talkto-Signature')
        ->and($client->requests[0]['envelope']['message_id'])->toBe('http-custom-success')
        ->and($client->requests[0]['timeout'])->toBe(13)
        ->and($message->fresh()->overall_status)->toBe('destination_received')
        ->and($message->fresh()->destination_action_status)->toBe('queued');
});

test('custom client failed response triggers existing retry behavior', function (): void {
    $client = new RecordingTalktoHttpClient(new TalktoHttpResponse(
        statusCode: 503,
        body: 'custom temporary failure',
        headers: ['X-Custom-Client' => ['yes']],
        successful: false,
    ));
    app()->instance(TalktoHttpClient::class, $client);

    $message = httpClientOutgoingMessage('http-custom-failure');

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();

    expect($client->requests)->toHaveCount(1)
        ->and($message->overall_status)->toBe('failed_retryable')
        ->and($message->transport_status)->toBe('failed')
        ->and($message->last_http_status)->toBe(503)
        ->and($message->last_response)->toBe('custom temporary failure')
        ->and($message->next_retry_at)->not->toBeNull();
});

test('durable callback send uses callback endpoint and completes accepted response', function (): void {
    $incoming = httpClientIncomingMessage('http-callback-applied');
    $callback = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(['processed' => true])
    );
    $client = new RecordingTalktoHttpClient(new TalktoHttpResponse(
        statusCode: 200,
        body: json_encode([
            'accepted' => true,
            'status' => 'applied',
            'message_id' => $callback->message_id,
            'original_message_id' => $incoming->message_id,
            'duplicate' => false,
        ]),
    ));
    app()->instance(TalktoHttpClient::class, $client);

    (new SendTalktoMessage($callback->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $callback->refresh();

    expect($client->requests)->toHaveCount(1)
        ->and($client->requests[0]['url'])->toBe('https://peer.test/callbacks/talkto')
        ->and($client->requests[0]['headers'])->toHaveKey('X-Talkto-Signature')
        ->and($client->requests[0]['envelope']['command'])->toBe('talkto.result')
        ->and($client->requests[0]['envelope']['payload']['original_message_id'])->toBe('http-callback-applied')
        ->and($client->requests[0]['timeout'])->toBe(13)
        ->and($callback->transport_status)->toBe('sent')
        ->and($callback->destination_receive_status)->toBe('received')
        ->and($callback->destination_action_status)->toBe('applied')
        ->and($callback->overall_status)->toBe('completed')
        ->and($callback->completed_at)->not->toBeNull()
        ->and($callback->last_http_status)->toBe(200)
        ->and($callback->failed_at)->toBeNull()
        ->and($callback->last_error)->toBeNull()
        ->and(TalktoEvent::query()->where('message_id', $callback->message_id)->where('event_type', 'message_sent')->where('meta->result_callback_delivery', true)->exists())->toBeTrue();
});

test('normal outgoing uses explicit receive url', function (): void {
    config([
        'talkto.outgoing.peer' => [
            'receive_url' => 'https://service.test/custom/receive',
            'callback_url' => 'https://service.test/custom/callback',
            'secret' => 'secret',
        ],
    ]);

    $client = new RecordingTalktoHttpClient(httpClientReceiveResponse());
    app()->instance(TalktoHttpClient::class, $client);
    $normal = httpClientOutgoingMessage('http-target-receive-url-normal');

    (new SendTalktoMessage($normal->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect($client->requests[0]['url'])->toBe('https://service.test/custom/receive');
});

test('durable callback send uses explicit callback url', function (): void {
    config([
        'talkto.outgoing.peer' => [
            'receive_url' => 'https://service.test/custom/receive',
            'callback_url' => 'https://service.test/custom/callback',
            'secret' => 'secret',
        ],
    ]);

    $incoming = httpClientIncomingMessage('http-target-callback-url');
    $callback = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(['processed' => true])
    );
    $callbackClient = new RecordingTalktoHttpClient(httpClientAcceptedCallbackResponse($callback, $incoming));
    app()->instance(TalktoHttpClient::class, $callbackClient);

    (new SendTalktoMessage($callback->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect($callbackClient->requests[0]['url'])->toBe('https://service.test/custom/callback');
});

test('base url joins receive endpoint and callback endpoint', function (): void {
    config([
        'talkto.outgoing.peer' => [
            'base_url' => 'https://service.test/root',
            'receive_endpoint' => '/talkto/receive',
            'callback_endpoint' => '/talkto/callback',
            'secret' => 'secret',
        ],
    ]);

    $normalClient = new RecordingTalktoHttpClient(httpClientReceiveResponse());
    app()->instance(TalktoHttpClient::class, $normalClient);
    $normal = httpClientOutgoingMessage('http-target-base-url-normal');

    (new SendTalktoMessage($normal->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $incoming = httpClientIncomingMessage('http-target-base-url-callback');
    $callback = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(['processed' => true])
    );
    $callbackClient = new RecordingTalktoHttpClient(httpClientAcceptedCallbackResponse($callback, $incoming));
    app()->instance(TalktoHttpClient::class, $callbackClient);

    (new SendTalktoMessage($callback->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect($normalClient->requests[0]['url'])->toBe('https://service.test/root/talkto/receive')
        ->and($callbackClient->requests[0]['url'])->toBe('https://service.test/root/talkto/callback');
});

test('base url and url aliases must be absolute http urls', function (string $key, string $value, string $expected): void {
    config(['talkto.outgoing.peer' => [
        $key => $value,
        'secret' => 'secret',
        'receive_endpoint' => '/api/talkto/receive',
    ]]);

    $message = httpClientOutgoingMessage("http-target-invalid-{$key}");

    expect(fn () => app(TalktoOutgoingEnvelopeBuilder::class)->endpointFor($message))
        ->toThrow(InvalidTalktoOutgoingTarget::class, $expected);
})->with([
    'relative base_url' => ['base_url', '/relative/path', 'base_url'],
    'relative url alias' => ['url', '/relative/path', 'url'],
    'unsupported base_url scheme' => ['base_url', 'ftp://service.test', 'HTTP or HTTPS'],
]);

test('url and endpoint aliases still work for normal outgoing', function (): void {
    config([
        'talkto.outgoing.peer' => [
            'url' => 'https://service.test/root',
            'endpoint' => 'alias/receive',
            'secret' => 'secret',
        ],
    ]);

    $client = new RecordingTalktoHttpClient(httpClientReceiveResponse());
    app()->instance(TalktoHttpClient::class, $client);
    $normal = httpClientOutgoingMessage('http-target-alias-endpoint-normal');

    (new SendTalktoMessage($normal->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect($client->requests[0]['url'])->toBe('https://service.test/root/alias/receive');
});

test('durable callback send safely derives callback url from receive url', function (): void {
    config([
        'talkto.outgoing.peer' => [
            'receive_url' => 'https://legacy.test/api/talkto/receive',
            'callback_endpoint' => '/api/talkto/callback',
            'secret' => 'secret',
        ],
    ]);

    $incoming = httpClientIncomingMessage('http-target-safe-receive-callback');
    $callback = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(['processed' => true])
    );
    $client = new RecordingTalktoHttpClient(httpClientAcceptedCallbackResponse($callback, $incoming));
    app()->instance(TalktoHttpClient::class, $client);

    (new SendTalktoMessage($callback->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect($client->requests[0]['url'])->toBe('https://legacy.test/api/talkto/callback');
});

test('unsafe receive url cannot infer durable callback url', function (): void {
    config(['talkto.outgoing.peer' => [
        'receive_url' => 'https://service.test/full/path',
        'secret' => 'secret',
    ]]);

    $incoming = httpClientIncomingMessage('http-target-unsafe-receive-callback');
    $callback = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(['processed' => true])
    );

    expect(fn () => app(TalktoOutgoingEnvelopeBuilder::class)->endpointFor($callback))
        ->toThrow(InvalidTalktoOutgoingTarget::class, 'callback_url');
});

test('missing receive url fails when only an unknown url key is present', function (): void {
    config(['talkto.outgoing.peer' => [
        'unsupported_url' => 'https://service.test/custom/receive',
        'secret' => 'secret',
    ]]);

    $normal = httpClientOutgoingMessage('http-target-unknown-url-key');

    expect(fn () => app(TalktoOutgoingEnvelopeBuilder::class)->endpointFor($normal))
        ->toThrow(InvalidTalktoOutgoingTarget::class, 'URL is not configured');
});

test('full receive and callback urls must be absolute http urls', function (string $key, string $value): void {
    config(['talkto.outgoing.peer' => [
        $key => $value,
        'secret' => 'secret',
    ]]);

    $message = $key === 'callback_url'
        ? app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
            httpClientIncomingMessage('http-target-invalid-full-callback-url'),
            TalktoIncomingCommandResult::succeeded(['processed' => true])
        )
        : httpClientOutgoingMessage('http-target-invalid-full-receive-url');

    expect(fn () => app(TalktoOutgoingEnvelopeBuilder::class)->endpointFor($message))
        ->toThrow(InvalidTalktoOutgoingTarget::class, $key);
})->with([
    ['receive_url', '/relative/receive'],
    ['callback_url', 'ftp://service.test/callback'],
]);

test('durable callback duplicate and stale responses complete delivery with callback status', function (): void {
    foreach (['duplicate', 'stale_ignored'] as $status) {
        $incoming = httpClientIncomingMessage("http-callback-{$status}");
        $callback = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
            $incoming,
            TalktoIncomingCommandResult::succeeded(['processed' => true])
        );
        $client = new RecordingTalktoHttpClient(new TalktoHttpResponse(
            statusCode: 200,
            body: json_encode([
                'accepted' => true,
                'status' => $status,
                'message_id' => $callback->message_id,
                'original_message_id' => $incoming->message_id,
                'duplicate' => $status === 'duplicate',
            ]),
        ));
        app()->instance(TalktoHttpClient::class, $client);

        (new SendTalktoMessage($callback->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

        $callback->refresh();

        expect($callback->overall_status)->toBe('completed')
            ->and($callback->destination_action_status)->toBe($status)
            ->and($callback->completed_at)->not->toBeNull();
    }
});

test('durable callback rejected response becomes final failure', function (): void {
    $incoming = httpClientIncomingMessage('http-callback-rejected');
    $callback = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(['processed' => true])
    );
    $client = new RecordingTalktoHttpClient(new TalktoHttpResponse(
        statusCode: 200,
        body: json_encode([
            'accepted' => false,
            'status' => 'rejected',
            'error' => 'original_message_not_found',
        ]),
    ));
    app()->instance(TalktoHttpClient::class, $client);

    (new SendTalktoMessage($callback->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $callback->refresh();

    expect($callback->overall_status)->toBe('failed_final')
        ->and($callback->transport_status)->toBe('failed_final')
        ->and($callback->destination_receive_status)->toBe('received')
        ->and($callback->destination_action_status)->toBe('rejected')
        ->and($callback->failed_at)->not->toBeNull()
        ->and($callback->last_error)->toContain('original_message_not_found')
        ->and(TalktoEvent::query()->where('message_id', $callback->message_id)->where('event_type', 'message_send_failed')->where('meta->result_callback_delivery', true)->exists())->toBeTrue()
        ->and(TalktoDeadLetter::query()->where('message_id', $callback->message_id)->exists())->toBeTrue();
});

test('durable callback success without accepted field becomes invalid callback response failure', function (): void {
    $incoming = httpClientIncomingMessage('http-callback-invalid-response');
    $callback = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(['processed' => true])
    );
    $client = new RecordingTalktoHttpClient(new TalktoHttpResponse(
        statusCode: 200,
        body: json_encode(['received' => true, 'status' => 'queued']),
    ));
    app()->instance(TalktoHttpClient::class, $client);

    (new SendTalktoMessage($callback->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $callback->refresh();

    expect($callback->overall_status)->toBe('failed_final')
        ->and($callback->destination_action_status)->toBe('rejected')
        ->and($callback->last_error)->toContain('invalid_callback_response');
});

test('custom client exceptions are handled like transport exceptions', function (): void {
    $client = new RecordingTalktoHttpClient(new RuntimeException('custom client transport exception'));
    app()->instance(TalktoHttpClient::class, $client);

    $message = httpClientOutgoingMessage('http-custom-exception');

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();

    expect($client->requests)->toHaveCount(1)
        ->and($message->overall_status)->toBe('failed_retryable')
        ->and($message->transport_status)->toBe('failed')
        ->and($message->last_http_status)->toBeNull()
        ->and($message->last_error)->toContain('custom client transport exception')
        ->and($message->next_retry_at)->not->toBeNull();
});

function httpClientOutgoingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source_service' => 'testing',
        'target_service' => 'peer',
        'command' => 'domain.command',
        'payload' => ['id' => $messageId],
        'payload_hash' => app(TalktoPayloadHasher::class)->hash(['id' => $messageId]),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
    ], $attributes));
}

function httpClientIncomingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    $payload = ['resource_id' => $messageId];

    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'correlation-'.$messageId,
        'direction' => 'incoming',
        'source_service' => 'peer',
        'target_service' => 'testing',
        'command' => 'domain.command',
        'business_key' => 'business-key-'.$messageId,
        'idempotency_key' => 'incoming-'.$messageId,
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1,
        'destination_receive_status' => 'received',
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'attempts' => 1,
        'retry_count' => 0,
        'max_attempts' => 5,
        'received_at' => now(),
    ], $attributes));
}

function httpClientReceiveResponse(): TalktoHttpResponse
{
    return new TalktoHttpResponse(
        statusCode: 200,
        body: '{"received":true,"status":"queued"}',
        headers: ['X-Test' => ['received']],
    );
}

function httpClientAcceptedCallbackResponse(TalktoMessage $callback, TalktoMessage $incoming): TalktoHttpResponse
{
    return new TalktoHttpResponse(
        statusCode: 200,
        body: json_encode([
            'accepted' => true,
            'status' => 'applied',
            'message_id' => $callback->message_id,
            'original_message_id' => $incoming->message_id,
            'duplicate' => false,
        ]),
    );
}

class RecordingTalktoHttpClient implements TalktoHttpClient
{
    public array $requests = [];

    public function __construct(private readonly mixed $response) {}

    public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
    {
        $this->requests[] = [
            'url' => $url,
            'headers' => $headers,
            'envelope' => $envelope,
            'timeout' => $timeout,
        ];

        if ($this->response instanceof Throwable) {
            throw $this->response;
        }

        return $this->response;
    }
}
