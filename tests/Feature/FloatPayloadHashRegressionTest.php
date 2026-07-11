<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Exceptions\TalktoJsonEncodingException;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'inventory',
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v1', 'v2'],
        'talkto.security.replay_protection.enabled' => false,
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
        'talkto.outgoing.website' => [
            'base_url' => 'https://website.test',
            'secret' => 'float-secret',
            'receive_endpoint' => '/api/talkto/receive',
            'headers' => ['X-Custom' => 'custom'],
            'timeout' => 7,
        ],
        'talkto.incoming.inventory' => [
            'secret' => 'float-secret',
            'allowed_commands' => [
                'webhook:update-stock' => true,
                'talkto.result' => true,
            ],
        ],
        'talkto.incoming.website' => [
            'secret' => 'float-secret',
            'allowed_commands' => [
                'talkto.result' => true,
            ],
        ],
    ]);
});

test('payload hash stays valid across serialize precision after json database round trip', function (string $createPrecision, string $reloadPrecision): void {
    $originalPrecision = ini_get('serialize_precision');

    try {
        ini_set('serialize_precision', $createPrecision);
        $payload = floatStockPayload();
        $message = app(TalktoOutgoingMessageFactory::class)->create(
            target: 'website',
            command: 'webhook:update-stock',
            payload: $payload,
            options: [
                'message_id' => "float-round-trip-{$createPrecision}-{$reloadPrecision}",
                'business_key' => 'material_detail:31',
                'idempotency_key' => "float-round-trip-{$createPrecision}-{$reloadPrecision}",
            ]
        );

        ini_set('serialize_precision', $reloadPrecision);
        $reloaded = TalktoMessage::query()->whereKey($message->id)->firstOrFail();

        expect(app(TalktoPayloadHasher::class)->hash($reloaded->payload))->toBe($message->payload_hash)
            ->and($reloaded->payload['items'][0]['stock'])->toBeFloat()
            ->and($reloaded->payload['items'][0]['available'])->toBeFloat();
    } finally {
        ini_set('serialize_precision', (string) $originalPrecision);
    }
})->with([
    '53 to -1' => ['53', '-1'],
    '-1 to 53' => ['-1', '53'],
    '14 to 17' => ['14', '17'],
    '17 to 14' => ['17', '14'],
]);

test('default http transport body verifies at receiver under different serialize precision', function (string $createPrecision, string $sendPrecision): void {
    $originalPrecision = ini_get('serialize_precision');
    $capturedBody = null;
    $verification = null;

    try {
        ini_set('serialize_precision', $createPrecision);
        $message = app(TalktoOutgoingMessageFactory::class)->create(
            target: 'website',
            command: 'webhook:update-stock',
            payload: floatStockPayload(),
            options: [
                'message_id' => "float-http-{$createPrecision}-{$sendPrecision}",
                'business_key' => 'material_detail:31',
                'idempotency_key' => "float-http-{$createPrecision}-{$sendPrecision}",
            ]
        );

        Http::fake(function (Request $request) use (&$capturedBody, &$verification, $sendPrecision) {
            $capturedBody = $request->body();
            $decoded = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);
            $senderService = config('talkto.service');

            try {
                config(['talkto.service' => 'website']);
                ini_set('serialize_precision', $sendPrecision);
                $verification = app(TalktoSignatureVerifier::class)->verifyEnvelope($decoded, $request->headers());
            } finally {
                config(['talkto.service' => $senderService]);
            }

            return Http::response([
                'received' => (bool) ($verification['ok'] ?? false),
                'status' => ($verification['ok'] ?? false) ? 'queued' : 'rejected',
                'error' => $verification['error'] ?? null,
            ], (int) ($verification['ok'] ?? false) ? 200 : 422);
        });

        ini_set('serialize_precision', $sendPrecision);
        (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

        $decoded = json_decode((string) $capturedBody, true, 512, JSON_THROW_ON_ERROR);

        expect($verification['ok'] ?? false)->toBeTrue()
            ->and($decoded['payload']['items'][0]['stock'])->toBeFloat()
            ->and($decoded['payload']['items'][0]['stock'])->toBe(79.95)
            ->and($decoded['payload']['items'][0]['available'])->toBe(77.95)
            ->and($message->fresh()->overall_status)->toBe('destination_received');
    } finally {
        ini_set('serialize_precision', (string) $originalPrecision);
    }
})->with([
    '53 to -1' => ['53', '-1'],
    '-1 to 53' => ['-1', '53'],
]);

test('signature verification accepts v1 and v2 float payloads and rejects tampering', function (string $version): void {
    config([
        'talkto.service' => 'website',
        'talkto.security.accept_versions' => [$version],
    ]);

    $payload = floatStockPayload();
    $payloadHash = app(TalktoPayloadHasher::class)->hash($payload);
    $timestamp = now()->toIso8601String();
    $nonce = 'float-nonce-'.$version;
    $headers = $version === 'v2'
        ? [
            'X-Talkto-Signature-Version' => 'v2',
            'X-Talkto-Signature' => app(TalktoSigner::class)->signV2(
                $timestamp,
                $nonce,
                'float-signed-'.$version,
                'inventory',
                'website',
                'webhook:update-stock',
                $payloadHash,
                'float-secret'
            ),
            'X-Talkto-Timestamp' => $timestamp,
            'X-Talkto-Message-Id' => 'float-signed-'.$version,
            'X-Talkto-Payload-Hash' => $payloadHash,
            'X-Talkto-Nonce' => $nonce,
        ]
        : [
            'X-Talkto-Signature' => app(TalktoSigner::class)->sign(
                'float-signed-'.$version,
                $timestamp,
                'inventory',
                'website',
                'webhook:update-stock',
                $payloadHash,
                'float-secret'
            ),
            'X-Talkto-Timestamp' => $timestamp,
            'X-Talkto-Message-Id' => 'float-signed-'.$version,
        ];

    $envelope = [
        'message_id' => 'float-signed-'.$version,
        'source' => 'inventory',
        'target' => 'website',
        'command' => 'webhook:update-stock',
        'payload_hash' => $payloadHash,
        'payload' => $payload,
    ];

    $tampered = $envelope;
    $tampered['payload']['items'][0]['stock'] = 79.96;

    $badSignature = $headers;
    $badSignature['X-Talkto-Signature'] = 'invalid';

    $randomHash = $envelope;
    $randomHash['payload_hash'] = str_repeat('a', 64);

    expect(app(TalktoSignatureVerifier::class)->verifyEnvelope($envelope, $headers)['ok'])->toBeTrue()
        ->and(app(TalktoSignatureVerifier::class)->verifyEnvelope($tampered, $headers)['error'])->toBe('payload_hash_mismatch')
        ->and(app(TalktoSignatureVerifier::class)->verifyEnvelope($envelope, $badSignature)['error'])->toBe('invalid_signature')
        ->and(app(TalktoSignatureVerifier::class)->verifyEnvelope($randomHash, $headers)['error'])->toBe('payload_hash_mismatch');
})->with([
    'v1' => ['v1'],
    'v2' => ['v2'],
]);

test('stale stored payload hash is caught locally before http and is not retryable', function (): void {
    Http::fake();

    $payload = floatStockPayload();
    $message = TalktoMessage::query()->create([
        'message_id' => 'float-stale-local-guard',
        'direction' => 'outgoing',
        'source_service' => 'inventory',
        'target_service' => 'website',
        'command' => 'webhook:update-stock',
        'business_key' => 'material_detail:31',
        'idempotency_key' => 'float-stale-local-guard',
        'payload' => $payload,
        'payload_hash' => legacyFloatPayloadHash($payload, '53'),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
    ]);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    Http::assertNothingSent();

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_final')
        ->and($message->transport_status)->toBe('failed_final')
        ->and($message->next_retry_at)->toBeNull()
        ->and($message->last_error)->toBe('stored_payload_hash_mismatch')
        ->and(TalktoAttempt::query()->where('message_id', 'float-stale-local-guard')->where('error_class', 'stored_payload_hash_mismatch')->exists())->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', 'float-stale-local-guard')->where('event_type', 'message_send_failed')->where('meta->error_code', 'stored_payload_hash_mismatch')->exists())->toBeTrue()
        ->and(TalktoDeadLetter::query()->where('message_id', 'float-stale-local-guard')->exists())->toBeTrue();

    Queue::fake();
    expect(Artisan::call('talkto:retry-failed', ['--dry-run' => true]))->toBe(0);
    Queue::assertNothingPushed();
});

test('durable result callback with floats remains verifiable after persistence reload', function (): void {
    $originalPrecision = ini_get('serialize_precision');

    try {
        ini_set('serialize_precision', '53');
        $incoming = TalktoMessage::query()->create([
            'message_id' => 'float-callback-original',
            'direction' => 'incoming',
            'source_service' => 'website',
            'target_service' => 'inventory',
            'command' => 'webhook:update-stock',
            'business_key' => 'material_detail:31',
            'idempotency_key' => 'float-callback-original',
            'payload' => floatStockPayload(),
            'payload_hash' => app(TalktoPayloadHasher::class)->hash(floatStockPayload()),
            'schema_version' => 1,
            'destination_receive_status' => 'received',
            'destination_action_status' => 'succeeded',
            'overall_status' => 'succeeded',
            'attempts' => 1,
            'retry_count' => 0,
            'max_attempts' => 5,
            'received_at' => now(),
        ]);

        $callback = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
            $incoming,
            TalktoIncomingCommandResult::succeeded([
                'stock' => 79.95,
                'available' => 77.95,
            ])
        );

        ini_set('serialize_precision', '-1');
        $callback = TalktoMessage::query()->whereKey($callback->id)->firstOrFail();

        expect(app(TalktoPayloadHasher::class)->hash($callback->payload))->toBe($callback->payload_hash);

        $envelope = app(TalktoOutgoingEnvelopeBuilder::class)->buildEnvelope($callback);
        $headers = app(TalktoOutgoingEnvelopeBuilder::class)->buildHeaders($callback);
        config(['talkto.service' => 'website']);

        $result = app(TalktoSignatureVerifier::class)->verifyEnvelope($envelope, $headers);

        expect($result['ok'])->toBeTrue()
            ->and($envelope['command'])->toBe('talkto.result')
            ->and($envelope['payload']['result']['stock'])->toBe(79.95)
            ->and($envelope['payload']['result']['available'])->toBe(77.95);
    } finally {
        ini_set('serialize_precision', (string) $originalPrecision);
    }
});

test('repair command is dry run by default and confirmed repair enables deliberate dlq reprocess', function (): void {
    Queue::fake();
    $payload = floatStockPayload();
    $message = TalktoMessage::query()->create([
        'message_id' => 'float-repair-command',
        'direction' => 'outgoing',
        'source_service' => 'inventory',
        'target_service' => 'website',
        'command' => 'webhook:update-stock',
        'business_key' => 'material_detail:31',
        'idempotency_key' => 'float-repair-command',
        'payload' => $payload,
        'payload_hash' => legacyFloatPayloadHash($payload, '53'),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'failed_final',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'rejected',
        'overall_status' => 'failed_final',
        'attempts' => 1,
        'retry_count' => 0,
        'max_attempts' => 5,
        'last_http_status' => 422,
        'last_response' => '{"received":false,"status":"rejected","error":"payload_hash_mismatch"}',
        'last_error' => 'HTTP transport failed with status [422].',
        'failed_at' => now(),
    ]);
    $originalPayload = $message->payload;
    $originalMessageId = $message->message_id;
    $originalFingerprint = $message->idempotency_fingerprint;

    TalktoDeadLetter::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'direction' => 'outgoing',
        'source' => 'inventory',
        'target' => 'website',
        'command' => 'webhook:update-stock',
        'payload' => $payload,
        'failure_reason' => 'payload_hash_mismatch',
        'failed_status' => 'failed_final',
        'status' => 'open',
    ]);

    expect(Artisan::call('talkto:repair-payload-hash', ['message_id' => 'float-repair-command']))->toBe(0)
        ->and($message->fresh()->payload_hash)->toBe(legacyFloatPayloadHash($payload, '53'));

    Queue::assertNothingPushed();

    expect(Artisan::call('talkto:repair-payload-hash', [
        'message_id' => 'float-repair-command',
        '--confirm' => true,
    ]))->toBe(1);

    expect(Artisan::call('talkto:repair-payload-hash', [
        'message_id' => 'float-repair-command',
        '--confirm' => true,
        '--reason' => 'confirmed legacy serialize_precision hash drift',
    ]))->toBe(0);

    $message = $message->fresh();

    expect($message->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($message->payload))
        ->and($message->payload)->toBe($originalPayload)
        ->and($message->message_id)->toBe($originalMessageId)
        ->and($message->idempotency_fingerprint)->toBe($originalFingerprint)
        ->and(TalktoEvent::query()->where('message_id', 'float-repair-command')->where('event_type', 'payload_hash_repaired')->exists())->toBeTrue();

    Queue::assertNothingPushed();

    expect(Artisan::call('talkto:dlq-reprocess', ['--message-id' => 'float-repair-command']))->toBe(0);
    Queue::assertPushed(SendTalktoMessage::class, 1);
});

test('repair command permits dead lettered stale outgoing hashes without dispatching', function (): void {
    Queue::fake();
    Http::fake();

    $payload = floatStockPayload();
    $message = createFloatRepairMessage('float-repair-dead-lettered', [
        'overall_status' => 'dead_lettered',
        'transport_status' => 'failed_final',
        'last_response' => '{"received":false,"status":"rejected","error":"payload_hash_mismatch"}',
    ]);
    $originalPayload = $message->payload;
    $originalMessageId = $message->message_id;
    $originalFingerprint = $message->idempotency_fingerprint;

    TalktoDeadLetter::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'direction' => 'outgoing',
        'source' => 'inventory',
        'target' => 'website',
        'command' => 'webhook:update-stock',
        'payload' => $payload,
        'failure_reason' => 'payload_hash_mismatch',
        'failed_status' => 'failed_final',
        'status' => 'open',
    ]);

    expect(Artisan::call('talkto:repair-payload-hash', [
        'message_id' => 'float-repair-dead-lettered',
        '--confirm' => true,
        '--reason' => 'confirmed stopped dead letter repair',
    ]))->toBe(0);

    $message = $message->fresh();

    expect($message->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($message->payload))
        ->and($message->payload)->toBe($originalPayload)
        ->and($message->message_id)->toBe($originalMessageId)
        ->and($message->idempotency_fingerprint)->toBe($originalFingerprint)
        ->and(TalktoEvent::query()->where('message_id', 'float-repair-dead-lettered')->where('event_type', 'payload_hash_repaired')->exists())->toBeTrue();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('repair command refuses failed retryable stale hashes without mutation or dispatching', function (): void {
    Queue::fake();
    Http::fake();

    $nextRetryAt = now()->addMinutes(10)->startOfSecond();
    $nextAttemptAt = now()->addMinutes(11)->startOfSecond();
    $message = createFloatRepairMessage('float-repair-retryable-refused', [
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed_retryable',
        'retry_count' => 1,
        'next_retry_at' => $nextRetryAt,
        'next_attempt_at' => $nextAttemptAt,
        'last_response' => '{"received":false,"status":"rejected","error":"payload_hash_mismatch"}',
    ]);
    $originalPayload = $message->payload;
    $originalHash = $message->payload_hash;
    $originalFingerprint = $message->idempotency_fingerprint;

    expect(Artisan::call('talkto:repair-payload-hash', [
        'message_id' => 'float-repair-retryable-refused',
        '--confirm' => true,
        '--reason' => 'test repair',
    ]))->toBe(1);

    $output = Artisan::output();
    $message = $message->fresh();

    expect($output)->toContain('Message is not in a repairable stopped state')
        ->and($message->payload)->toBe($originalPayload)
        ->and($message->payload_hash)->toBe($originalHash)
        ->and($message->idempotency_fingerprint)->toBe($originalFingerprint)
        ->and($message->overall_status)->toBe('failed_retryable')
        ->and($message->next_retry_at?->toIso8601String())->toBe($nextRetryAt->toIso8601String())
        ->and($message->next_attempt_at?->toIso8601String())->toBe($nextAttemptAt->toIso8601String())
        ->and(TalktoEvent::query()->where('message_id', 'float-repair-retryable-refused')->where('event_type', 'payload_hash_repaired')->exists())->toBeFalse();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('repair command handles deterministic hash encoding exceptions without leaking details', function (): void {
    Queue::fake();
    Http::fake();

    $message = createFloatRepairMessage('float-repair-encoding-exception', [
        'overall_status' => 'failed_final',
        'transport_status' => 'failed_final',
        'last_response' => '{"received":false,"status":"rejected","error":"payload_hash_mismatch"}',
    ]);
    $originalPayload = $message->payload;
    $originalHash = $message->payload_hash;
    $originalFingerprint = $message->idempotency_fingerprint;
    $originalStatus = $message->overall_status;

    $this->app->instance(TalktoPayloadHasher::class, new class extends TalktoPayloadHasher
    {
        public function hash(mixed $payload): string
        {
            throw new TalktoJsonEncodingException('secret payload details stock=79.95');
        }
    });

    try {
        expect(Artisan::call('talkto:repair-payload-hash', [
            'message_id' => 'float-repair-encoding-exception',
            '--confirm' => true,
            '--reason' => 'test repair',
        ]))->toBe(1);
    } finally {
        $this->app->forgetInstance(TalktoPayloadHasher::class);
    }

    $output = Artisan::output();
    $message = $message->fresh();

    expect($output)->toContain('Unable to calculate the deterministic payload hash for this message.')
        ->and($output)->not->toContain('secret payload details')
        ->and($output)->not->toContain('stock=79.95')
        ->and($output)->not->toContain('Stack trace')
        ->and($message->payload)->toBe($originalPayload)
        ->and($message->payload_hash)->toBe($originalHash)
        ->and($message->idempotency_fingerprint)->toBe($originalFingerprint)
        ->and($message->overall_status)->toBe($originalStatus)
        ->and(TalktoEvent::query()->where('message_id', 'float-repair-encoding-exception')->where('event_type', 'payload_hash_repaired')->exists())->toBeFalse();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('repair command refuses wrong direction inappropriate status unrelated failures and noops already correct hashes', function (): void {
    $payload = floatStockPayload();

    TalktoMessage::query()->create([
        'message_id' => 'float-repair-incoming',
        'direction' => 'incoming',
        'source_service' => 'website',
        'target_service' => 'inventory',
        'command' => 'webhook:update-stock',
        'payload' => $payload,
        'payload_hash' => legacyFloatPayloadHash($payload, '53'),
        'schema_version' => 1,
        'overall_status' => 'failed_final',
    ]);

    TalktoMessage::query()->create([
        'message_id' => 'float-repair-pending',
        'direction' => 'outgoing',
        'source_service' => 'inventory',
        'target_service' => 'website',
        'command' => 'webhook:update-stock',
        'payload' => $payload,
        'payload_hash' => legacyFloatPayloadHash($payload, '53'),
        'schema_version' => 1,
        'overall_status' => 'waiting_to_send',
    ]);

    TalktoMessage::query()->create([
        'message_id' => 'float-repair-unrelated',
        'direction' => 'outgoing',
        'source_service' => 'inventory',
        'target_service' => 'website',
        'command' => 'webhook:update-stock',
        'payload' => $payload,
        'payload_hash' => legacyFloatPayloadHash($payload, '53'),
        'schema_version' => 1,
        'overall_status' => 'failed_final',
        'last_error' => 'route_not_found',
    ]);

    TalktoMessage::query()->create([
        'message_id' => 'float-repair-correct',
        'direction' => 'outgoing',
        'source_service' => 'inventory',
        'target_service' => 'website',
        'command' => 'webhook:update-stock',
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1,
        'overall_status' => 'failed_final',
        'last_response' => 'payload_hash_mismatch',
    ]);

    expect(Artisan::call('talkto:repair-payload-hash', ['message_id' => 'float-repair-incoming']))->toBe(1)
        ->and(Artisan::call('talkto:repair-payload-hash', ['message_id' => 'float-repair-pending']))->toBe(1)
        ->and(Artisan::call('talkto:repair-payload-hash', ['message_id' => 'float-repair-unrelated']))->toBe(1)
        ->and(Artisan::call('talkto:repair-payload-hash', ['message_id' => 'float-repair-correct']))->toBe(0)
        ->and(TalktoEvent::query()->where('event_type', 'payload_hash_repaired')->exists())->toBeFalse();
});

function floatStockPayload(): array
{
    return [
        'schema_version' => 1,
        'source_service' => 'inventory',
        'target_service' => 'website',
        'command' => 'webhook:update-stock',
        'items' => [
            [
                'entity_type' => 'material_detail',
                'id' => 31,
                'stock' => 79.95,
                'reserv' => 2,
                'available' => 77.95,
            ],
        ],
    ];
}

function createFloatRepairMessage(string $messageId, array $overrides = []): TalktoMessage
{
    $payload = floatStockPayload();

    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source_service' => 'inventory',
        'target_service' => 'website',
        'command' => 'webhook:update-stock',
        'business_key' => 'material_detail:31',
        'idempotency_key' => $messageId,
        'payload' => $payload,
        'payload_hash' => legacyFloatPayloadHash($payload, '53'),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'failed_final',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'rejected',
        'overall_status' => 'failed_final',
        'attempts' => 1,
        'retry_count' => 0,
        'max_attempts' => 5,
        'last_http_status' => 422,
        'last_error' => 'HTTP transport failed with status [422].',
        'failed_at' => now(),
    ], $overrides));
}

function legacyFloatPayloadHash(array $payload, string $precision): string
{
    $originalPrecision = ini_get('serialize_precision');

    try {
        ini_set('serialize_precision', $precision);
        $normalized = app(TalktoPayloadHasher::class)->normalize($payload);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $json);
    } finally {
        ini_set('serialize_precision', (string) $originalPrecision);
    }
}
