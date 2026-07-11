<?php

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoReceiveController;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoResultCallbackController;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoJsonEncoder;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    RawSignedJsonBodyHandler::$payloads = [];

    config([
        'talkto.service' => 'target-service',
        'talkto.security.require_signature' => true,
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.timestamp_tolerance_seconds' => 300,
        'talkto.security.replay_protection.enabled' => true,
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
        'talkto.incoming.source-service' => [
            'secret' => 'raw-json-secret',
            'allowed_commands' => [
                'domain.command' => [
                    'driver' => 'handler',
                    'handler' => RawSignedJsonBodyHandler::class,
                ],
            ],
        ],
    ]);
});

test('signed JSON receive uses the raw body instead of Laravel-mutated input', function (): void {
    Queue::fake();

    $payload = [
        'user_id' => 42,
        'name' => ' Amir ',
        'empty_note' => '',
        'unicode' => 'درخواست با فاصله ',
        'nested' => [
            'value' => ' keep me ',
            'empty' => '',
        ],
    ];

    $envelope = rawSignedJsonEnvelope('raw-json-receive-original', $payload);
    $request = rawSignedJsonRequest('/api/talkto/receive', $envelope, rawSignedJsonHeaders($envelope));

    $response = rawSignedJsonThroughTransforms($request, function (Request $request): JsonResponse {
        $parsed = $request->all();

        expect($parsed['payload']['name'])->toBe('Amir')
            ->and($parsed['payload']['empty_note'])->toBeNull()
            ->and($parsed['payload']['nested']['value'])->toBe('keep me')
            ->and($parsed['payload']['nested']['empty'])->toBeNull();

        return app(TalktoReceiveController::class)(
            $request,
            app(TalktoSignatureVerifier::class)
        );
    });

    expect($response->getStatusCode())->toBe(202);

    $message = TalktoMessage::query()->where('message_id', 'raw-json-receive-original')->sole();

    expect($message->payload)->toMatchArray($payload);

    (new ProcessIncomingTalktoMessage($message->id))->handle();

    expect(RawSignedJsonBodyHandler::$payloads)->toHaveCount(1)
        ->and(RawSignedJsonBodyHandler::$payloads[0])->toMatchArray($payload);
});

test('signed JSON receive rejects payload tampering against the raw body', function (): void {
    Queue::fake();

    $signedEnvelope = rawSignedJsonEnvelope('raw-json-receive-tampered-body', [
        'name' => ' Amir ',
        'empty_note' => '',
    ]);
    $headers = rawSignedJsonHeaders($signedEnvelope);

    $tamperedEnvelope = $signedEnvelope;
    $tamperedEnvelope['payload']['name'] = 'Amir';
    $tamperedEnvelope['payload']['empty_note'] = null;

    $response = rawSignedJsonReceive(rawSignedJsonRequest('/api/talkto/receive', $tamperedEnvelope, $headers));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error'])->toBe('payload_hash_mismatch')
        ->and(TalktoMessage::query()->where('message_id', 'raw-json-receive-tampered-body')->exists())->toBeFalse()
        ->and(RawSignedJsonBodyHandler::$payloads)->toBe([]);
});

test('signed JSON receive rejects invalid signatures and payload hashes before persistence', function (string $case): void {
    Queue::fake();

    $payload = ['value' => ' original '];
    $envelope = rawSignedJsonEnvelope('raw-json-receive-'.$case, $payload);
    $headers = rawSignedJsonHeaders($envelope);

    if ($case === 'invalid-signature') {
        $headers['X-Talkto-Signature'] = str_repeat('a', 64);
        $expectedStatus = 401;
        $expectedError = 'invalid_signature';
    } else {
        $envelope['payload_hash'] = str_repeat('0', 64);
        $headers = rawSignedJsonHeaders($envelope);
        $expectedStatus = 422;
        $expectedError = 'payload_hash_mismatch';
    }

    $response = rawSignedJsonReceive(rawSignedJsonRequest('/api/talkto/receive', $envelope, $headers));

    expect($response->getStatusCode())->toBe($expectedStatus)
        ->and($response->getData(true)['error'])->toBe($expectedError)
        ->and(TalktoMessage::query()->where('message_id', 'raw-json-receive-'.$case)->exists())->toBeFalse()
        ->and(RawSignedJsonBodyHandler::$payloads)->toBe([]);
})->with([
    'invalid-signature',
    'invalid-payload-hash',
]);

test('signed JSON receive keeps nonce replay protection unchanged', function (): void {
    Queue::fake();

    $first = rawSignedJsonEnvelope('raw-json-replay-first', ['value' => 'first']);
    $firstResponse = rawSignedJsonReceive(rawSignedJsonRequest(
        '/api/talkto/receive',
        $first,
        rawSignedJsonHeaders($first, 'shared-raw-json-nonce')
    ));

    $second = rawSignedJsonEnvelope('raw-json-replay-second', ['value' => 'second']);
    $secondResponse = rawSignedJsonReceive(rawSignedJsonRequest(
        '/api/talkto/receive',
        $second,
        rawSignedJsonHeaders($second, 'shared-raw-json-nonce')
    ));

    expect($firstResponse->getStatusCode())->toBe(202)
        ->and($secondResponse->getStatusCode())->toBe(409)
        ->and($secondResponse->getData(true)['error'])->toBe('replay_nonce_reused')
        ->and(TalktoMessage::query()->where('message_id', 'raw-json-replay-first')->exists())->toBeTrue()
        ->and(TalktoMessage::query()->where('message_id', 'raw-json-replay-second')->exists())->toBeFalse();
});

test('signed JSON receive accepts JSON content type variants', function (string $contentType): void {
    Queue::fake();

    $messageId = 'raw-json-content-'.str_replace(['/', '+', ';', '=', ' '], '-', strtolower($contentType));
    $envelope = rawSignedJsonEnvelope($messageId, ['value' => ' ok ']);

    $response = rawSignedJsonReceive(rawSignedJsonRequest(
        '/api/talkto/receive',
        $envelope,
        rawSignedJsonHeaders($envelope),
        $contentType
    ));

    expect($response->getStatusCode())->toBe(202)
        ->and(TalktoMessage::query()->where('message_id', $messageId)->exists())->toBeTrue();
})->with([
    'application/json',
    'application/json; charset=UTF-8',
    'application/vnd.talkto+json',
]);

test('signed JSON receive rejects malformed JSON without parsed-input fallback', function (): void {
    Queue::fake();

    $request = Request::create(
        '/api/talkto/receive',
        'POST',
        ['message_id' => 'raw-json-malformed-fallback'],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        '{"message_id":'
    );

    $response = rawSignedJsonReceive($request);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error'])->toBe('invalid_json')
        ->and($response->getData(true))->not->toHaveKey('details')
        ->and(TalktoMessage::query()->where('message_id', 'raw-json-malformed-fallback')->exists())->toBeFalse();
});

test('signed JSON callback uses the raw body instead of Laravel-mutated input', function (): void {
    rawSignedJsonConfigureCallbackReceiver();

    $original = rawSignedJsonOutgoingMessage('raw-json-callback-original');
    $payload = [
        'original_message_id' => $original->message_id,
        'original_command' => $original->command,
        'status' => 'succeeded',
        'succeeded' => true,
        'retryable' => false,
        'skipped' => false,
        'error_class' => null,
        'error_message' => null,
        'result' => [
            'note' => ' callback value ',
            'empty_value' => '',
        ],
        'meta' => [
            'trace' => ' trace value ',
        ],
    ];

    $envelope = rawSignedJsonCallbackEnvelope('raw-json-callback-message', $original, $payload);
    $request = rawSignedJsonRequest('/api/talkto/callback', $envelope, rawSignedJsonHeaders($envelope));

    $response = rawSignedJsonThroughTransforms($request, function (Request $request): JsonResponse {
        $parsed = $request->all();

        expect($parsed['payload']['result']['note'])->toBe('callback value')
            ->and($parsed['payload']['result']['empty_value'])->toBeNull()
            ->and($parsed['payload']['meta']['trace'])->toBe('trace value');

        return app(TalktoResultCallbackController::class)(
            $request,
            app(ResultCallbackReceiverContract::class)
        );
    });

    $event = TalktoEvent::query()
        ->where('message_id', $original->message_id)
        ->where('event_type', 'result_callback_applied')
        ->sole();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['status'])->toBe('applied')
        ->and($original->fresh()->overall_status)->toBe('completed')
        ->and($event->meta['result']['note'])->toBe(' callback value ')
        ->and($event->meta['result']['empty_value'])->toBe('')
        ->and($event->meta['result_meta']['trace'])->toBe(' trace value ');
});

test('signed JSON callback rejects malformed JSON without applying a callback', function (): void {
    rawSignedJsonConfigureCallbackReceiver();

    $original = rawSignedJsonOutgoingMessage('raw-json-callback-malformed-original');
    $request = Request::create(
        '/api/talkto/callback',
        'POST',
        ['message_id' => 'raw-json-callback-fallback'],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        '{"message_id":'
    );

    $response = rawSignedJsonThroughTransforms(
        $request,
        fn (Request $request): JsonResponse => app(TalktoResultCallbackController::class)(
            $request,
            app(ResultCallbackReceiverContract::class)
        )
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error'])->toBe('invalid_json')
        ->and($original->fresh()->overall_status)->toBe('destination_received')
        ->and(TalktoEvent::query()->where('message_id', $original->message_id)->where('event_type', 'result_callback_applied')->exists())->toBeFalse();
});

function rawSignedJsonEnvelope(string $messageId, array $payload, array $overrides = []): array
{
    $envelope = array_merge([
        'protocol_version' => 2,
        'message_id' => $messageId,
        'source' => 'source-service',
        'target' => 'target-service',
        'command' => 'domain.command',
        'schema_version' => 1,
        'payload' => $payload,
    ], $overrides);

    $envelope['payload_hash'] = $envelope['payload_hash'] ?? app(TalktoPayloadHasher::class)->hash($payload);

    return $envelope;
}

function rawSignedJsonHeaders(array $envelope, ?string $nonce = null): array
{
    $timestamp = now()->toIso8601String();
    $nonce ??= 'nonce-'.(string) $envelope['message_id'];

    return [
        'X-Talkto-Signature-Version' => 'v2',
        'X-Talkto-Timestamp' => $timestamp,
        'X-Talkto-Nonce' => $nonce,
        'X-Talkto-Message-Id' => (string) $envelope['message_id'],
        'X-Talkto-Source' => (string) $envelope['source'],
        'X-Talkto-Target' => (string) $envelope['target'],
        'X-Talkto-Command' => (string) $envelope['command'],
        'X-Talkto-Payload-Hash' => (string) $envelope['payload_hash'],
        'X-Talkto-Signature' => app(TalktoSigner::class)->signV2(
            $timestamp,
            $nonce,
            (string) $envelope['message_id'],
            (string) $envelope['source'],
            (string) $envelope['target'],
            (string) $envelope['command'],
            (string) $envelope['payload_hash'],
            'raw-json-secret'
        ),
    ];
}

function rawSignedJsonRequest(
    string $uri,
    array $envelope,
    array $headers,
    string $contentType = 'application/json'
): Request {
    $server = [
        'CONTENT_TYPE' => $contentType,
        'HTTP_ACCEPT' => 'application/json',
    ];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create(
        $uri,
        'POST',
        [],
        [],
        [],
        $server,
        app(TalktoJsonEncoder::class)->encode($envelope)
    );
}

function rawSignedJsonThroughTransforms(Request $request, Closure $next): JsonResponse
{
    return app(TrimStrings::class)->handle(
        $request,
        fn (Request $request): JsonResponse => app(ConvertEmptyStringsToNull::class)->handle($request, $next)
    );
}

function rawSignedJsonReceive(Request $request): JsonResponse
{
    return rawSignedJsonThroughTransforms(
        $request,
        fn (Request $request): JsonResponse => app(TalktoReceiveController::class)(
            $request,
            app(TalktoSignatureVerifier::class)
        )
    );
}

function rawSignedJsonConfigureCallbackReceiver(): void
{
    config([
        'talkto.service' => 'source-service',
        'talkto.callbacks.enabled' => true,
        'talkto.callbacks.command' => 'talkto.result',
        'talkto.incoming' => [
            'target-service' => [
                'secret' => 'raw-json-secret',
                'allowed_commands' => [
                    'talkto.result' => [
                        'driver' => 'none',
                    ],
                ],
            ],
        ],
    ]);
}

function rawSignedJsonOutgoingMessage(string $messageId): TalktoMessage
{
    $payload = ['request_id' => $messageId];

    return TalktoMessage::query()->create([
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source_service' => 'source-service',
        'target_service' => 'target-service',
        'command' => 'domain.command',
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1,
        'source_action_status' => 'succeeded',
        'transport_status' => 'succeeded',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'destination_received',
        'attempts' => 1,
        'retry_count' => 0,
        'max_attempts' => 5,
        'sent_at' => now(),
    ]);
}

function rawSignedJsonCallbackEnvelope(string $messageId, TalktoMessage $original, array $payload): array
{
    return rawSignedJsonEnvelope($messageId, $payload, [
        'source' => 'target-service',
        'target' => 'source-service',
        'command' => 'talkto.result',
        'parent_message_id' => $original->message_id,
    ]);
}

class RawSignedJsonBodyHandler implements TalktoIncomingCommandHandler
{
    public static array $payloads = [];

    public function handle(TalktoMessage $message): IncomingCommandResultContract
    {
        self::$payloads[] = $message->payload ?? [];

        return TalktoIncomingCommandResult::succeeded(['handled' => true]);
    }
}
