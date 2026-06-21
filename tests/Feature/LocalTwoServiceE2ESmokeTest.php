<?php

use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoReceiveController;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoResultCallbackController;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoNonce;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;

const P06_SOURCE_SERVICE = 'website-service';
const P06_TARGET_SERVICE = 'inventory-service';
const P06_COMMAND = 'catalog:sync-product';
const P06_SOURCE_TO_TARGET_SECRET = 'generated-test-secret-source-to-target';
const P06_TARGET_TO_SOURCE_SECRET = 'generated-test-secret-target-to-source';

beforeEach(function (): void {
    Queue::fake();
    P06InventoryHandler::reset();
    phase6ConfigureDatabases();
    phase6UseWebsiteServiceConfig();
    phase6RunPackageMigrations();
    phase6UseInventoryServiceConfig();
    phase6RunPackageMigrations();
    phase6UseWebsiteServiceConfig();
});

function phase6ConfigureDatabases(): void
{
    foreach (['phase6_source', 'phase6_target'] as $connection) {
        config([
            "database.connections.{$connection}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
    }
}

function phase6RunPackageMigrations(): void
{
    $files = glob(__DIR__.'/../../database/migrations/*.php') ?: [];
    sort($files);

    foreach ($files as $file) {
        $migration = require $file;
        $migration->up();
    }
}

test('local two service source to target command flow signs verifies handles and callbacks without network', function (): void {
    P06InventoryHandler::$sendCallback = true;
    $transport = new P06LocalTwoServiceHttpClient;
    app()->instance(TalktoHttpClient::class, $transport);
    phase6FakeCallbackHttp();

    $outgoing = app(TalktoOutgoingMessageFactory::class)->create(
        target: P06_TARGET_SERVICE,
        command: P06_COMMAND,
        payload: phase6ProductPayload(),
        options: [
            'message_id' => 'phase6-source-to-target',
            'correlation_id' => 'phase6-correlation-source-to-target',
            'business_key' => 'product:local-sku-001',
            'idempotency_key' => 'website-service:catalog-sync-product:local-sku-001',
        ]
    );

    (new SendTalktoMessage($outgoing->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $targetRequest = $transport->requests[0];
    $targetHeaders = $targetRequest['headers'];
    $targetEnvelope = $targetRequest['envelope'];
    $sourceNonce = (string) $targetHeaders['X-Talkto-Nonce'];

    phase6UseInventoryServiceConfig();

    $incoming = TalktoMessage::query()
        ->where('direction', 'incoming')
        ->where('message_id', 'phase6-source-to-target')
        ->firstOrFail();

    expect($transport->requests)->toHaveCount(1)
        ->and($targetRequest['url'])->toBe('https://inventory-service.test/api/talkto/receive')
        ->and($targetHeaders['X-Talkto-Signature-Version'])->toBe('v2')
        ->and($targetHeaders)->toHaveKey('X-Talkto-Signature')
        ->and($targetHeaders)->toHaveKey('X-Talkto-Timestamp')
        ->and($targetHeaders)->toHaveKey('X-Talkto-Payload-Hash')
        ->and($targetHeaders)->toHaveKey('X-Talkto-Nonce')
        ->and($targetHeaders['X-Talkto-Payload-Hash'])->toBe($outgoing->payload_hash)
        ->and($targetEnvelope['payload_hash'])->toBe($outgoing->payload_hash)
        ->and($transport->responses[0]['status'])->toBe(202)
        ->and(phase6NonceRows(P06_SOURCE_SERVICE, P06_TARGET_SERVICE))->toHaveCount(1)
        ->and($incoming->direction)->toBe('incoming')
        ->and($incoming->destination_receive_status)->toBe('received')
        ->and($incoming->destination_action_status)->toBe('queued')
        ->and($incoming->overall_status)->toBe('queued');

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 1);

    (new ProcessIncomingTalktoMessage($incoming->id))->handle();

    $incoming = $incoming->fresh();
    $targetNonceRows = TalktoNonce::query()->get()->toArray();
    $targetEvents = TalktoEvent::query()->get()->toArray();

    phase6UseWebsiteServiceConfig();

    $outgoing = $outgoing->fresh();
    $callbackRequest = P06InventoryHandler::$callbackRequests[0];
    $callbackNonce = (string) $callbackRequest['headers']['X-Talkto-Nonce'];
    $sourceNonceRows = TalktoNonce::query()->get()->toArray();
    $sourceEvents = TalktoEvent::query()->get()->toArray();
    $encodedNonceRows = json_encode(array_merge($targetNonceRows, $sourceNonceRows));
    $encodedEvents = json_encode(array_merge($targetEvents, $sourceEvents));
    $encodedResponses = json_encode([
        'target' => $transport->responses,
        'callback' => P06InventoryHandler::$callbackResponses,
    ]);

    expect(P06InventoryHandler::$calls)->toBe(1)
        ->and(P06InventoryHandler::$commands)->toBe([P06_COMMAND])
        ->and(P06InventoryHandler::$payloads)->toBe([phase6ProductPayload()])
        ->and($incoming->destination_action_status)->toBe('succeeded')
        ->and($incoming->overall_status)->toBe('succeeded')
        ->and(P06InventoryHandler::$callbackSummaries[0])->toMatchArray([
            'sent' => true,
            'status' => 'sent',
            'original_message_id' => 'phase6-source-to-target',
            'http_status' => 200,
        ])
        ->and($callbackRequest['headers']['X-Talkto-Signature-Version'])->toBe('v2')
        ->and($callbackRequest['headers'])->toHaveKey('X-Talkto-Nonce')
        ->and($callbackRequest['headers'])->toHaveKey('X-Talkto-Payload-Hash')
        ->and(phase6NonceRows(P06_TARGET_SERVICE, P06_SOURCE_SERVICE))->toHaveCount(1)
        ->and($outgoing->direction)->toBe('outgoing')
        ->and($outgoing->transport_status)->toBe('sent')
        ->and($outgoing->destination_receive_status)->toBe('received')
        ->and($outgoing->destination_action_status)->toBe('succeeded')
        ->and($outgoing->overall_status)->toBe('completed')
        ->and($encodedNonceRows)->not->toContain($sourceNonce)
        ->and($encodedNonceRows)->not->toContain($callbackNonce)
        ->and($encodedEvents)->not->toContain($sourceNonce)
        ->and($encodedEvents)->not->toContain($callbackNonce)
        ->and($encodedResponses)->not->toContain($sourceNonce)
        ->and($encodedResponses)->not->toContain($callbackNonce);
});

test('local two service replay idempotency and tamper paths do not execute business handler twice', function (): void {
    phase6UseInventoryServiceConfig();

    $firstEnvelope = phase6Envelope(
        'phase6-replay-original',
        'website-service:catalog-sync-product:replay-original',
        phase6ProductPayload('replay-original')
    );
    $firstHeaders = phase6V2Headers($firstEnvelope, 'phase6-source-replay-nonce');

    $first = phase6Receive($firstEnvelope, $firstHeaders);
    $incoming = TalktoMessage::query()->where('message_id', 'phase6-replay-original')->firstOrFail();
    (new ProcessIncomingTalktoMessage($incoming->id))->handle();

    $sameSignedReplay = phase6Receive($firstEnvelope, $firstHeaders);
    $sameMessageNewNonce = phase6Receive($firstEnvelope, phase6V2Headers($firstEnvelope, 'phase6-source-new-nonce'));

    $differentMessageReusedNonceEnvelope = phase6Envelope(
        'phase6-replay-different-message',
        'website-service:catalog-sync-product:replay-different',
        phase6ProductPayload('replay-different')
    );
    $differentMessageReusedNonce = phase6Receive(
        $differentMessageReusedNonceEnvelope,
        phase6V2Headers($differentMessageReusedNonceEnvelope, 'phase6-source-replay-nonce')
    );

    $tamperEnvelope = phase6Envelope(
        'phase6-replay-tampered',
        'website-service:catalog-sync-product:tampered',
        phase6ProductPayload('tampered')
    );
    $tamperHeaders = phase6V2Headers($tamperEnvelope, 'phase6-source-tamper-nonce');
    $tamperEnvelope['payload']['quantity'] = 999;
    $tampered = phase6Receive($tamperEnvelope, $tamperHeaders);

    expect($first->getStatusCode())->toBe(202)
        ->and($sameSignedReplay->getStatusCode())->toBe(200)
        ->and($sameSignedReplay->getData(true))->toMatchArray([
            'received' => true,
            'duplicate' => true,
            'status' => 'already_received',
            'message_id' => 'phase6-replay-original',
        ])
        ->and($sameMessageNewNonce->getStatusCode())->toBe(200)
        ->and($sameMessageNewNonce->getData(true)['status'])->toBe('already_received')
        ->and($differentMessageReusedNonce->getStatusCode())->toBe(409)
        ->and($differentMessageReusedNonce->getData(true)['error'])->toBe('replay_nonce_reused')
        ->and($tampered->getStatusCode())->toBe(422)
        ->and($tampered->getData(true)['error'])->toBe('payload_hash_mismatch')
        ->and(P06InventoryHandler::$calls)->toBe(1)
        ->and(TalktoMessage::query()->where('message_id', 'phase6-replay-original')->count())->toBe(1)
        ->and(TalktoMessage::query()->where('message_id', 'phase6-replay-different-message')->exists())->toBeFalse()
        ->and(TalktoMessage::query()->where('message_id', 'phase6-replay-tampered')->exists())->toBeFalse()
        ->and(phase6NonceRows(P06_SOURCE_SERVICE, P06_TARGET_SERVICE))->toHaveCount(1);

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 1);
});

test('local callback replay cannot regress a later successful source message status', function (): void {
    phase6UseWebsiteServiceConfig();
    $outgoing = app(TalktoOutgoingMessageFactory::class)->create(
        target: P06_TARGET_SERVICE,
        command: P06_COMMAND,
        payload: phase6ProductPayload('callback-regression'),
        options: [
            'message_id' => 'phase6-callback-regression',
            'correlation_id' => 'phase6-correlation-callback-regression',
            'idempotency_key' => 'website-service:catalog-sync-product:callback-regression',
        ]
    );

    [$oldEnvelope, $oldHeaders] = phase6SignedCallback(
        $outgoing,
        TalktoIncomingCommandResult::failedRetryable('Temporary inventory failure.'),
        'phase6-callback-old-failure',
        'phase6-callback-old-nonce'
    );
    [$successEnvelope, $successHeaders] = phase6SignedCallback(
        $outgoing,
        TalktoIncomingCommandResult::succeeded(['synced' => true]),
        'phase6-callback-success',
        'phase6-callback-success-nonce'
    );

    phase6UseWebsiteServiceConfig();
    $old = phase6ReceiveCallback($oldEnvelope, $oldHeaders);
    $success = phase6ReceiveCallback($successEnvelope, $successHeaders);
    $oldReplay = phase6ReceiveCallback($oldEnvelope, $oldHeaders);

    expect($old->getStatusCode())->toBe(200)
        ->and($old->getData(true))->toMatchArray([
            'accepted' => true,
            'status' => 'applied',
            'duplicate' => false,
        ])
        ->and($success->getStatusCode())->toBe(200)
        ->and($success->getData(true))->toMatchArray([
            'accepted' => true,
            'status' => 'applied',
            'duplicate' => false,
        ])
        ->and($oldReplay->getStatusCode())->toBe(409)
        ->and($oldReplay->getData(true))->toMatchArray([
            'accepted' => false,
            'status' => 'rejected',
            'duplicate' => false,
            'error' => 'replay_nonce_reused',
        ])
        ->and($outgoing->fresh()->destination_action_status)->toBe('succeeded')
        ->and($outgoing->fresh()->overall_status)->toBe('completed')
        ->and(phase6NonceRows(P06_TARGET_SERVICE, P06_SOURCE_SERVICE))->toHaveCount(2)
        ->and(json_encode(TalktoNonce::query()->get()->toArray()))->not->toContain('phase6-callback-old-nonce')
        ->and(json_encode(TalktoEvent::query()->get()->toArray()))->not->toContain('phase6-callback-old-nonce');
});

function phase6UseWebsiteServiceConfig(): void
{
    config([
        'talkto.service' => P06_SOURCE_SERVICE,
        'talkto.database.connection' => 'phase6_source',
        'talkto.security.require_signature' => true,
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.timestamp_tolerance_seconds' => 300,
        'talkto.security.replay_protection.enabled' => true,
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
        'talkto.callbacks.enabled' => true,
        'talkto.callbacks.command' => 'talkto.result',
        'talkto.outgoing.'.P06_TARGET_SERVICE => [
            'url' => 'https://inventory-service.test',
            'endpoint' => '/api/talkto/receive',
            'callback_endpoint' => '/api/talkto/callback',
            'secret' => P06_SOURCE_TO_TARGET_SECRET,
            'timeout' => 11,
        ],
        'talkto.incoming.'.P06_TARGET_SERVICE => [
            'secret' => P06_TARGET_TO_SOURCE_SECRET,
            'allowed_commands' => [
                'talkto.result' => [
                    'driver' => 'none',
                ],
            ],
            'allow_all_commands' => false,
        ],
    ]);
}

function phase6UseInventoryServiceConfig(): void
{
    config([
        'talkto.service' => P06_TARGET_SERVICE,
        'talkto.database.connection' => 'phase6_target',
        'talkto.security.require_signature' => true,
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.timestamp_tolerance_seconds' => 300,
        'talkto.security.replay_protection.enabled' => true,
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
        'talkto.callbacks.enabled' => true,
        'talkto.callbacks.command' => 'talkto.result',
        'talkto.incoming.'.P06_SOURCE_SERVICE => [
            'secret' => P06_SOURCE_TO_TARGET_SECRET,
            'allowed_commands' => [
                P06_COMMAND => [
                    'driver' => 'handler',
                    'handler' => P06InventoryHandler::class,
                    'idempotency' => 'required',
                ],
            ],
            'allow_all_commands' => false,
        ],
        'talkto.outgoing.'.P06_SOURCE_SERVICE => [
            'url' => 'https://website-service.test',
            'endpoint' => '/api/talkto/callback',
            'callback_endpoint' => '/api/talkto/callback',
            'secret' => P06_TARGET_TO_SOURCE_SECRET,
            'timeout' => 11,
        ],
    ]);
}

function phase6ProductPayload(string $suffix = 'local-001'): array
{
    return [
        'product_id' => "product-{$suffix}",
        'sku' => "sku-{$suffix}",
        'name' => 'Local Smoke Test Product',
        'quantity' => 7,
    ];
}

function phase6Envelope(string $messageId, string $idempotencyKey, array $payload): array
{
    return [
        'protocol_version' => 2,
        'message_id' => $messageId,
        'correlation_id' => 'phase6-correlation-'.$messageId,
        'source' => P06_SOURCE_SERVICE,
        'target' => P06_TARGET_SERVICE,
        'command' => P06_COMMAND,
        'business_key' => 'product:'.$payload['sku'],
        'idempotency_key' => $idempotencyKey,
        'schema_version' => 1,
        'created_at' => now()->toIso8601String(),
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'payload' => $payload,
    ];
}

function phase6V2Headers(array $envelope, string $nonce, string $secret = P06_SOURCE_TO_TARGET_SECRET): array
{
    $timestamp = now()->toIso8601String();
    $payloadHash = (string) $envelope['payload_hash'];

    return [
        'X-Talkto-Signature' => app(TalktoSigner::class)->signV2(
            $timestamp,
            $nonce,
            (string) $envelope['message_id'],
            (string) $envelope['source'],
            (string) $envelope['target'],
            (string) $envelope['command'],
            $payloadHash,
            $secret
        ),
        'X-Talkto-Timestamp' => $timestamp,
        'X-Talkto-Message-Id' => (string) $envelope['message_id'],
        'X-Talkto-Protocol-Version' => '2',
        'X-Talkto-Signature-Version' => 'v2',
        'X-Talkto-Payload-Hash' => $payloadHash,
        'X-Talkto-Nonce' => $nonce,
    ];
}

function phase6Receive(array $envelope, array $headers): JsonResponse
{
    $request = HttpRequest::create('/api/talkto/receive', 'POST', $envelope);

    foreach ($headers as $key => $value) {
        if (str_starts_with(strtolower((string) $key), 'x-talkto-')) {
            $request->headers->set($key, $value);
        }
    }

    return app(TalktoReceiveController::class)->__invoke($request, app(TalktoSignatureVerifier::class));
}

function phase6ReceiveCallback(array $envelope, array $headers): JsonResponse
{
    $request = HttpRequest::create('/api/talkto/callback', 'POST', $envelope);

    foreach ($headers as $key => $value) {
        if (str_starts_with(strtolower((string) $key), 'x-talkto-')) {
            $request->headers->set($key, $value);
        }
    }

    return app(TalktoResultCallbackController::class)->__invoke($request, app(ResultCallbackReceiverContract::class));
}

function phase6FakeCallbackHttp(): void
{
    Http::fake(function (HttpClientRequest $request) {
        P06InventoryHandler::$callbackRequests[] = [
            'url' => $request->url(),
            'headers' => phase6FlattenHeaders($request->headers()),
            'envelope' => $request->data(),
        ];

        $previousTalktoConfig = config('talkto');
        phase6UseWebsiteServiceConfig();

        try {
            $response = phase6ReceiveCallback(
                $request->data(),
                phase6FlattenHeaders($request->headers())
            );
        } finally {
            config(['talkto' => $previousTalktoConfig]);
        }

        P06InventoryHandler::$callbackResponses[] = [
            'status' => $response->getStatusCode(),
            'body' => $response->getData(true),
        ];

        return Http::response($response->getData(true), $response->getStatusCode());
    });
}

function phase6FlattenHeaders(array $headers): array
{
    $flattened = [];

    foreach ($headers as $key => $value) {
        $flattened[$key] = is_array($value) ? (string) reset($value) : (string) $value;
    }

    return $flattened;
}

function phase6NonceRows(string $source, string $target)
{
    return TalktoNonce::query()
        ->where('source_service', $source)
        ->where('target_service', $target)
        ->get();
}

function phase6SignedCallback(
    TalktoMessage $original,
    TalktoIncomingCommandResult $result,
    string $callbackMessageId,
    string $nonce
): array {
    $previousTalktoConfig = config('talkto');
    phase6UseInventoryServiceConfig();

    try {
        $incoming = new TalktoMessage;
        $incoming->forceFill([
            'message_id' => $original->message_id,
            'source_service' => $original->source_service,
            'target_service' => $original->target_service,
            'command' => $original->command,
            'correlation_id' => $original->correlation_id,
            'business_key' => $original->business_key,
            'idempotency_key' => $original->idempotency_key,
        ]);

        $envelope = TalktoResultCallbackData::fromIncomingMessageResult($incoming, $result, [
            'callback_message_id' => $callbackMessageId,
        ])->toEnvelope();
        $headers = phase6V2Headers($envelope, $nonce, P06_TARGET_TO_SOURCE_SECRET);
    } finally {
        config(['talkto' => $previousTalktoConfig]);
    }

    return [$envelope, $headers];
}

class P06InventoryHandler implements TalktoIncomingCommandHandler
{
    public static int $calls = 0;

    public static bool $sendCallback = false;

    public static array $commands = [];

    public static array $payloads = [];

    public static array $callbackSummaries = [];

    public static array $callbackRequests = [];

    public static array $callbackResponses = [];

    public static function reset(): void
    {
        self::$calls = 0;
        self::$sendCallback = false;
        self::$commands = [];
        self::$payloads = [];
        self::$callbackSummaries = [];
        self::$callbackRequests = [];
        self::$callbackResponses = [];
    }

    public function handle(TalktoMessage $message): IncomingCommandResultContract
    {
        self::$calls++;
        self::$commands[] = (string) $message->command;
        self::$payloads[] = $message->payload;

        $result = TalktoIncomingCommandResult::succeeded([
            'synced' => true,
            'sku' => $message->payload['sku'] ?? null,
        ], [
            'handler' => 'phase6-local-inventory-handler',
        ]);

        if (self::$sendCallback) {
            self::$callbackSummaries[] = app(ResultCallbackSenderContract::class)->sendResult($message, $result, [
                'callback_message_id' => 'phase6-callback-'.$message->message_id,
            ]);
        }

        return $result;
    }
}

class P06LocalTwoServiceHttpClient implements TalktoHttpClient
{
    public array $requests = [];

    public array $responses = [];

    public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
    {
        $this->requests[] = [
            'url' => $url,
            'headers' => $headers,
            'envelope' => $envelope,
            'timeout' => $timeout,
        ];

        $previousTalktoConfig = config('talkto');
        phase6UseInventoryServiceConfig();

        try {
            $response = phase6Receive($envelope, $headers);
        } finally {
            config(['talkto' => $previousTalktoConfig]);
        }

        $body = json_encode($response->getData(true), JSON_UNESCAPED_SLASHES);
        $this->responses[] = [
            'status' => $response->getStatusCode(),
            'body' => $response->getData(true),
        ];

        return new TalktoHttpResponse(
            statusCode: $response->getStatusCode(),
            body: $body === false ? null : $body,
            headers: [],
            successful: $response->getStatusCode() >= 200 && $response->getStatusCode() < 300
        );
    }
}
