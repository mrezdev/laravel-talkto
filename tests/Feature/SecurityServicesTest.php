<?php

use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;

test('signer and verifier accept a valid signed envelope', function (): void {
    config([
        'talkto.service' => 'target-service',
        'talkto.security.accept_versions' => ['v1'],
        'talkto.incoming.source-service' => [
            'secret' => 'fake-test-secret',
            'allowed_commands' => [
                'domain.command' => [
                    'idempotency' => 'required',
                ],
            ],
        ],
    ]);

    $payload = ['id' => 123, 'status' => 'ready'];
    $payloadHash = app(TalktoPayloadHasher::class)->hash($payload);
    $timestamp = now()->toIso8601String();
    $signature = app(TalktoSigner::class)->sign(
        'message-1',
        $timestamp,
        'source-service',
        'target-service',
        'domain.command',
        $payloadHash,
        'fake-test-secret',
    );

    $result = app(TalktoSignatureVerifier::class)->verifyEnvelope([
        'message_id' => 'message-1',
        'source' => 'source-service',
        'target' => 'target-service',
        'command' => 'domain.command',
        'idempotency_key' => 'idempotency-1',
        'payload_hash' => $payloadHash,
        'payload' => $payload,
    ], [
        'X-Talkto-Signature' => $signature,
        'X-Talkto-Timestamp' => $timestamp,
        'X-Talkto-Message-Id' => 'message-1',
    ]);

    expect($result)->toMatchArray([
        'ok' => true,
        'status' => 200,
        'error' => null,
    ]);
});

test('payload hash changes when payload is tampered', function (): void {
    $hasher = app(TalktoPayloadHasher::class);

    expect($hasher->hash(['amount' => 10, 'status' => 'ready']))
        ->not->toBe($hasher->hash(['amount' => 11, 'status' => 'ready']));
});

test('public signing methods do not return the raw secret', function (): void {
    $signer = app(TalktoSigner::class);
    $secret = 'fake-test-secret';
    $payloadHash = app(TalktoPayloadHasher::class)->hash(['status' => 'ok']);

    $canonical = $signer->canonicalString(
        'message-1',
        '2026-01-01T00:00:00+00:00',
        'source-service',
        'target-service',
        'domain.command',
        $payloadHash,
    );
    $signature = $signer->sign(
        'message-1',
        '2026-01-01T00:00:00+00:00',
        'source-service',
        'target-service',
        'domain.command',
        $payloadHash,
        $secret,
    );

    expect($canonical)->not->toContain($secret)
        ->and($signature)->not->toBe($secret)
        ->and($signature)->not->toContain($secret);
});
