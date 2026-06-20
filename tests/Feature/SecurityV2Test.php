<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoSignatureException;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoReceiveController;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoNonce;
use Mrezdev\LaravelTalkto\Services\TalktoNonceLedger;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoSecurityAuditor;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'target-service',
        'talkto.security.require_signature' => true,
        'talkto.security.signature_version' => 'v1',
        'talkto.security.accept_versions' => ['v1', 'v2'],
        'talkto.security.timestamp_tolerance_seconds' => 300,
        'talkto.security.replay_protection.require_nonce_for_v2' => false,
        'talkto.incoming.source-service' => [
            'secret' => 'fake-test-secret',
            'allowed_commands' => [
                'domain.command' => [
                    'driver' => 'none',
                ],
            ],
        ],
    ]);
});

test('default v2 security profile accepts nonce and rejects missing or modified nonce', function (): void {
    Queue::fake();
    config([
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
    ]);

    $validPayload = ['id' => 'security-default-v2'];
    $valid = securityReceive(
        securityEnvelope('security-default-v2', $validPayload),
        securityV2Headers('security-default-v2', $validPayload)
    );

    $missingPayload = ['id' => 'security-default-v2-missing'];
    $missingHeaders = securityV2Headers('security-default-v2-missing', $missingPayload);
    unset($missingHeaders['X-Talkto-Nonce']);
    $missing = securityReceive(securityEnvelope('security-default-v2-missing', $missingPayload), $missingHeaders);

    $modifiedPayload = ['id' => 'security-default-v2-modified'];
    $modifiedHeaders = securityV2Headers('security-default-v2-modified', $modifiedPayload);
    $modifiedHeaders['X-Talkto-Nonce'] = 'changed-nonce';
    $modified = securityReceive(securityEnvelope('security-default-v2-modified', $modifiedPayload), $modifiedHeaders);

    expect($valid->getStatusCode())->toBe(202)
        ->and($missing->getStatusCode())->toBe(401)
        ->and($missing->getData(true)['error'])->toBe('missing_nonce')
        ->and($modified->getStatusCode())->toBe(401)
        ->and($modified->getData(true)['error'])->toBe('invalid_signature');
});

test('v1 is rejected by default and passes only when explicitly accepted', function (): void {
    config([
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
    ]);

    $rejected = securityReceive(
        securityEnvelope('security-v1-default-rejected', ['id' => 'security-v1-default-rejected']),
        securityV1Headers('security-v1-default-rejected', ['id' => 'security-v1-default-rejected'])
    );

    config(['talkto.security.accept_versions' => ['v1']]);

    $accepted = securityReceive(
        securityEnvelope('security-v1-explicit-accepted', ['id' => 'security-v1-explicit-accepted']),
        securityV1Headers('security-v1-explicit-accepted', ['id' => 'security-v1-explicit-accepted'])
    );

    expect($rejected->getStatusCode())->toBe(401)
        ->and($rejected->getData(true)['error'])->toBe('unsupported_signature_version')
        ->and($accepted->getStatusCode())->toBe(202);
});

test('v1 signed request remains backward compatible without version header', function (): void {
    Queue::fake();
    $payload = ['id' => 'security-v1'];
    $response = securityReceive(
        securityEnvelope('security-v1', $payload),
        securityV1Headers('security-v1', $payload)
    );

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getData(true))->toMatchArray([
            'received' => true,
            'status' => 'queued',
            'message_id' => 'security-v1',
        ]);

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 1);
});

test('v1 tampered payload is rejected', function (): void {
    $payload = ['id' => 'security-v1-tamper'];
    $envelope = securityEnvelope('security-v1-tamper', ['id' => 'changed']);

    $response = securityReceive($envelope, securityV1Headers('security-v1-tamper', $payload));

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('invalid_signature');
});

test('v2 outgoing headers include version timestamp payload hash signature and nonce', function (): void {
    config([
        'talkto.service' => 'source-service',
        'talkto.security.signature_version' => 'v2',
        'talkto.outgoing.target-service' => [
            'url' => 'https://target.test',
            'secret' => 'fake-test-secret',
        ],
    ]);

    $message = app(TalktoOutgoingMessageFactory::class)->create(
        target: 'target-service',
        command: 'domain.command',
        payload: ['id' => 'security-v2-outgoing'],
        options: ['message_id' => 'security-v2-outgoing']
    );
    $builder = app(TalktoOutgoingEnvelopeBuilder::class);
    $envelope = $builder->buildEnvelope($message);
    $headers = $builder->buildHeaders($message, now()->toIso8601String());

    expect($headers)->toHaveKey('X-Talkto-Signature-Version')
        ->and($headers['X-Talkto-Signature-Version'])->toBe('v2')
        ->and($headers)->toHaveKey('X-Talkto-Timestamp')
        ->and($headers)->toHaveKey('X-Talkto-Payload-Hash')
        ->and($headers)->toHaveKey('X-Talkto-Nonce')
        ->and($headers)->toHaveKey('X-Talkto-Signature')
        ->and($headers['X-Talkto-Payload-Hash'])->toBe($message->payload_hash);

    config([
        'talkto.service' => 'target-service',
        'talkto.incoming.source-service.secret' => 'fake-test-secret',
    ]);

    expect(app(TalktoSignatureVerifier::class)->verifyEnvelope($envelope, $headers)['ok'])->toBeTrue();
});

test('invalid outgoing signature version throws a safe package exception', function (): void {
    config([
        'talkto.service' => 'source-service',
        'talkto.security.signature_version' => 'v3',
        'talkto.outgoing.target-service' => [
            'url' => 'https://target.test',
            'secret' => 'fake-test-secret',
        ],
    ]);

    $message = app(TalktoOutgoingMessageFactory::class)->create(
        target: 'target-service',
        command: 'domain.command',
        payload: ['id' => 'security-invalid-version'],
        options: ['message_id' => 'security-invalid-version']
    );

    try {
        app(TalktoOutgoingEnvelopeBuilder::class)->buildHeaders($message);

        expect()->fail('Expected invalid signature version exception.');
    } catch (InvalidTalktoSignatureException $exception) {
        expect($exception->getMessage())->toContain('v3')
            ->and($exception->getMessage())->not->toContain('fake-test-secret');
    }
});

test('v2 incoming verification accepts valid requests and rejects invalid signature payload and version', function (): void {
    Queue::fake();
    $payload = ['id' => 'security-v2-valid'];
    $response = securityReceive(
        securityEnvelope('security-v2-valid', $payload),
        securityV2Headers('security-v2-valid', $payload)
    );

    expect($response->getStatusCode())->toBe(202);
    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 1);

    $badSignature = securityV2Headers('security-v2-bad-signature', ['id' => 'security-v2-bad-signature']);
    $badSignature['X-Talkto-Signature'] = 'invalid';
    $response = securityReceive(securityEnvelope('security-v2-bad-signature', ['id' => 'security-v2-bad-signature']), $badSignature);

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('invalid_signature');

    $response = securityReceive(
        securityEnvelope('security-v2-bad-payload', ['id' => 'changed']),
        securityV2Headers('security-v2-bad-payload', ['id' => 'original'])
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error'])->toBe('payload_hash_mismatch');

    $headers = securityV2Headers('security-v2-unsupported', ['id' => 'security-v2-unsupported']);
    $headers['X-Talkto-Signature-Version'] = 'v9';
    $response = securityReceive(securityEnvelope('security-v2-unsupported', ['id' => 'security-v2-unsupported']), $headers);

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('unsupported_signature_version');
});

test('signed requests always require timestamp', function (): void {
    $v1Headers = securityV1Headers('security-v1-missing-timestamp', ['id' => 'security-v1-missing-timestamp']);
    unset($v1Headers['X-Talkto-Timestamp']);

    $response = securityReceive(
        securityEnvelope('security-v1-missing-timestamp', ['id' => 'security-v1-missing-timestamp']),
        $v1Headers
    );

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('missing_signature_header');

    $v2Headers = securityV2Headers('security-v2-missing-timestamp', ['id' => 'security-v2-missing-timestamp']);
    unset($v2Headers['X-Talkto-Timestamp']);

    $response = securityReceive(
        securityEnvelope('security-v2-missing-timestamp', ['id' => 'security-v2-missing-timestamp']),
        $v2Headers
    );

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('missing_signature_header');
});

test('timestamp tolerance rejects old and future timestamps but accepts current timestamps', function (): void {
    Queue::fake();
    config(['talkto.security.timestamp_tolerance_seconds' => 60]);

    $old = now()->subSeconds(61)->toIso8601String();
    $response = securityReceive(
        securityEnvelope('security-old-timestamp', ['id' => 'security-old-timestamp']),
        securityV1Headers('security-old-timestamp', ['id' => 'security-old-timestamp'], $old)
    );
    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('timestamp_outside_tolerance');

    $future = now()->addSeconds(61)->toIso8601String();
    $response = securityReceive(
        securityEnvelope('security-future-timestamp', ['id' => 'security-future-timestamp']),
        securityV1Headers('security-future-timestamp', ['id' => 'security-future-timestamp'], $future)
    );
    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('timestamp_outside_tolerance');

    $response = securityReceive(
        securityEnvelope('security-current-timestamp', ['id' => 'security-current-timestamp']),
        securityV1Headers('security-current-timestamp', ['id' => 'security-current-timestamp'])
    );

    expect($response->getStatusCode())->toBe(202);
});

test('unsigned requests follow configured timestamp policy', function (): void {
    Queue::fake();
    config([
        'talkto.security.require_signature' => false,
        'talkto.security.require_timestamp' => true,
        'talkto.security.timestamp_tolerance_seconds' => 60,
    ]);

    $response = securityReceive(
        securityEnvelope('security-unsigned-no-timestamp', ['id' => 'security-unsigned-no-timestamp']),
        []
    );

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('missing_timestamp');

    $response = securityReceive(
        securityEnvelope('security-unsigned-valid-timestamp', ['id' => 'security-unsigned-valid-timestamp']),
        ['X-Talkto-Timestamp' => now()->toIso8601String()]
    );

    expect($response->getStatusCode())->toBe(202);

    $response = securityReceive(
        securityEnvelope('security-unsigned-old-timestamp', ['id' => 'security-unsigned-old-timestamp']),
        ['X-Talkto-Timestamp' => now()->subSeconds(61)->toIso8601String()]
    );

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('timestamp_outside_tolerance');

    $response = securityReceive(
        securityEnvelope('security-unsigned-future-timestamp', ['id' => 'security-unsigned-future-timestamp']),
        ['X-Talkto-Timestamp' => now()->addSeconds(61)->toIso8601String()]
    );

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('timestamp_outside_tolerance');

    config(['talkto.security.require_timestamp' => false]);

    $response = securityReceive(
        securityEnvelope('security-unsigned-timestamp-disabled', ['id' => 'security-unsigned-timestamp-disabled']),
        []
    );

    expect($response->getStatusCode())->toBe(202);
});

test('replay protection uses message id ledger and v2 nonce is signed', function (): void {
    Queue::fake();
    $payload = ['id' => 'security-replay'];
    $envelope = securityEnvelope('security-replay', $payload);
    $headers = securityV2Headers('security-replay', $payload);

    $first = securityReceive($envelope, $headers);
    $second = securityReceive($envelope, $headers);

    expect($first->getStatusCode())->toBe(202)
        ->and($second->getStatusCode())->toBe(200)
        ->and($second->getData(true))->toMatchArray([
            'received' => true,
            'duplicate' => true,
            'status' => 'already_received',
            'message_id' => 'security-replay',
        ])
        ->and(TalktoMessage::query()->where('message_id', 'security-replay')->count())->toBe(1);

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 1);

    $tamperedNonce = securityV2Headers('security-nonce-tamper', ['id' => 'security-nonce-tamper']);
    $tamperedNonce['X-Talkto-Nonce'] = 'changed-nonce';
    $response = securityReceive(securityEnvelope('security-nonce-tamper', ['id' => 'security-nonce-tamper']), $tamperedNonce);

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('invalid_signature');
});

test('nonce ledger stores hashes and rejects reused v2 nonce with different message id', function (): void {
    Queue::fake();
    config([
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
    ]);

    $nonce = 'raw-shared-nonce-value';
    $firstPayload = ['id' => 'security-nonce-ledger-first'];
    $secondPayload = ['id' => 'security-nonce-ledger-second'];

    $first = securityReceive(
        securityEnvelope('security-nonce-ledger-first', $firstPayload),
        securityV2Headers('security-nonce-ledger-first', $firstPayload, nonce: $nonce)
    );
    $second = securityReceive(
        securityEnvelope('security-nonce-ledger-second', $secondPayload),
        securityV2Headers('security-nonce-ledger-second', $secondPayload, nonce: $nonce)
    );

    $stored = TalktoNonce::query()->firstOrFail();

    expect($first->getStatusCode())->toBe(202)
        ->and($second->getStatusCode())->toBe(409)
        ->and($second->getData(true))->toMatchArray([
            'received' => false,
            'status' => 'rejected',
            'error' => 'replay_nonce_reused',
        ])
        ->and(TalktoNonce::query()->count())->toBe(1)
        ->and($stored->nonce_hash)->toBe(app(TalktoNonceLedger::class)->nonceHash('v2', 'source-service', 'target-service', $nonce))
        ->and($stored->nonce_hash)->not->toBe($nonce)
        ->and(json_encode($second->getData(true)))->not->toContain($nonce);
});

test('reusing v2 nonce with changed payload is rejected before business execution', function (): void {
    Queue::fake();
    config([
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
    ]);

    $nonce = 'raw-payload-change-nonce';
    $originalPayload = ['id' => 'original'];
    $changedPayload = ['id' => 'changed'];

    $first = securityReceive(
        securityEnvelope('security-nonce-changed-payload-first', $originalPayload),
        securityV2Headers('security-nonce-changed-payload-first', $originalPayload, nonce: $nonce)
    );
    $changed = securityReceive(
        securityEnvelope('security-nonce-changed-payload-second', $changedPayload),
        securityV2Headers('security-nonce-changed-payload-second', $originalPayload, nonce: $nonce)
    );

    expect($first->getStatusCode())->toBe(202)
        ->and($changed->getStatusCode())->toBe(422)
        ->and($changed->getData(true)['error'])->toBe('payload_hash_mismatch');

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 1);
});

test('same message id with new nonce keeps existing idempotent duplicate behavior', function (): void {
    Queue::fake();
    config([
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
    ]);

    $payload = ['id' => 'security-same-message-new-nonce'];
    $envelope = securityEnvelope('security-same-message-new-nonce', $payload);

    $first = securityReceive($envelope, securityV2Headers('security-same-message-new-nonce', $payload, nonce: 'nonce-one'));
    $second = securityReceive($envelope, securityV2Headers('security-same-message-new-nonce', $payload, nonce: 'nonce-two'));

    expect($first->getStatusCode())->toBe(202)
        ->and($second->getStatusCode())->toBe(200)
        ->and($second->getData(true)['status'])->toBe('already_received')
        ->and(TalktoMessage::query()->where('message_id', 'security-same-message-new-nonce')->count())->toBe(1)
        ->and(TalktoNonce::query()->count())->toBe(1);

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 1);
});

test('nonce replay ledger can be disabled explicitly', function (): void {
    Queue::fake();
    config([
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.replay_protection.enabled' => false,
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
    ]);

    $nonce = 'disabled-ledger-shared-nonce';

    $first = securityReceive(
        securityEnvelope('security-disabled-ledger-first', ['id' => 'security-disabled-ledger-first']),
        securityV2Headers('security-disabled-ledger-first', ['id' => 'security-disabled-ledger-first'], nonce: $nonce)
    );
    $second = securityReceive(
        securityEnvelope('security-disabled-ledger-second', ['id' => 'security-disabled-ledger-second']),
        securityV2Headers('security-disabled-ledger-second', ['id' => 'security-disabled-ledger-second'], nonce: $nonce)
    );

    expect($first->getStatusCode())->toBe(202)
        ->and($second->getStatusCode())->toBe(202)
        ->and(TalktoNonce::query()->count())->toBe(0);

    Queue::assertPushed(ProcessIncomingTalktoMessage::class, 2);
});

test('raw nonce is not exposed in nonce storage events responses or audit output', function (): void {
    config([
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
    ]);

    $nonce = 'raw-nonce-never-exposed';
    $payload = ['id' => 'security-raw-nonce-safe'];
    $first = securityReceive(
        securityEnvelope('security-raw-nonce-safe', $payload),
        securityV2Headers('security-raw-nonce-safe', $payload, nonce: $nonce)
    );
    $replay = securityReceive(
        securityEnvelope('security-raw-nonce-replay', ['id' => 'security-raw-nonce-replay']),
        securityV2Headers('security-raw-nonce-replay', ['id' => 'security-raw-nonce-replay'], nonce: $nonce)
    );

    $encodedEvents = json_encode(TalktoEvent::query()->get()->toArray());
    $encodedAudit = json_encode(app(TalktoSecurityAuditor::class)->audit()->toArray());

    expect($first->getStatusCode())->toBe(202)
        ->and($replay->getStatusCode())->toBe(409)
        ->and(TalktoNonce::query()->value('nonce_hash'))->not->toBe($nonce)
        ->and(json_encode($replay->getData(true)))->not->toContain($nonce)
        ->and($encodedEvents)->not->toContain($nonce)
        ->and($encodedAudit)->not->toContain($nonce);
});

test('v2 nonce can be required by config', function (): void {
    config(['talkto.security.replay_protection.require_nonce_for_v2' => true]);

    $payload = ['id' => 'security-nonce-required'];
    $headers = securityV2Headers('security-nonce-required', $payload);
    unset($headers['X-Talkto-Nonce']);

    $response = securityReceive(securityEnvelope('security-nonce-required', $payload), $headers);

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('missing_nonce');
});

test('accept versions config can reject v1 while accepting both versions by default', function (): void {
    config(['talkto.security.accept_versions' => ['v2']]);
    $response = securityReceive(
        securityEnvelope('security-v1-disabled', ['id' => 'security-v1-disabled']),
        securityV1Headers('security-v1-disabled', ['id' => 'security-v1-disabled'])
    );

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error'])->toBe('unsupported_signature_version');

    config(['talkto.security.accept_versions' => ['v1', 'v2']]);

    expect(securityReceive(
        securityEnvelope('security-v1-enabled', ['id' => 'security-v1-enabled']),
        securityV1Headers('security-v1-enabled', ['id' => 'security-v1-enabled'])
    )->getStatusCode())->toBe(202);

    expect(securityReceive(
        securityEnvelope('security-v2-enabled', ['id' => 'security-v2-enabled']),
        securityV2Headers('security-v2-enabled', ['id' => 'security-v2-enabled'])
    )->getStatusCode())->toBe(202);
});

test('security responses do not leak configured secret', function (): void {
    $headers = securityV2Headers('security-secret-safe', ['id' => 'security-secret-safe']);
    $headers['X-Talkto-Signature'] = 'invalid';

    $response = securityReceive(securityEnvelope('security-secret-safe', ['id' => 'security-secret-safe']), $headers);

    expect(json_encode($response->getData(true)))->not->toContain('fake-test-secret')
        ->and($response->getData(true)['error'])->toBe('invalid_signature');
});

test('incoming command authorization rejects missing and empty allowlists', function (): void {
    config(['talkto.incoming.source-service' => [
        'secret' => 'fake-test-secret',
    ]]);

    $missing = securityReceive(
        securityEnvelope('security-allowlist-missing', ['id' => 'security-allowlist-missing']),
        securityV1Headers('security-allowlist-missing', ['id' => 'security-allowlist-missing'])
    );

    config(['talkto.incoming.source-service.allowed_commands' => []]);

    $empty = securityReceive(
        securityEnvelope('security-allowlist-empty', ['id' => 'security-allowlist-empty']),
        securityV1Headers('security-allowlist-empty', ['id' => 'security-allowlist-empty'])
    );

    expect($missing->getStatusCode())->toBe(403)
        ->and($missing->getData(true)['error'])->toBe('command_not_allowed')
        ->and($empty->getStatusCode())->toBe(403)
        ->and($empty->getData(true)['error'])->toBe('command_not_allowed');
});

test('incoming indexed command allowlist permits listed commands and rejects unlisted commands', function (): void {
    Queue::fake();
    config(['talkto.incoming.source-service.allowed_commands' => [
        'orders.mark-paid',
        'invoices.sync-status',
    ]]);

    $listed = securityReceive(
        securityEnvelope('security-indexed-allowed', ['id' => 'security-indexed-allowed'], 'orders.mark-paid'),
        securityV1Headers('security-indexed-allowed', ['id' => 'security-indexed-allowed'], command: 'orders.mark-paid')
    );

    $unlisted = securityReceive(
        securityEnvelope('security-indexed-rejected', ['id' => 'security-indexed-rejected'], 'orders.cancelled'),
        securityV1Headers('security-indexed-rejected', ['id' => 'security-indexed-rejected'], command: 'orders.cancelled')
    );

    expect($listed->getStatusCode())->toBe(202)
        ->and($unlisted->getStatusCode())->toBe(403)
        ->and($unlisted->getData(true)['error'])->toBe('command_not_allowed');
});

test('incoming associative command allowlist permits listed commands and rejects unlisted commands', function (): void {
    Queue::fake();
    config(['talkto.incoming.source-service.allowed_commands' => [
        'orders.mark-paid' => [
            'driver' => 'none',
        ],
        'invoices.sync-status' => true,
    ]]);

    $arrayConfig = securityReceive(
        securityEnvelope('security-assoc-array-allowed', ['id' => 'security-assoc-array-allowed'], 'orders.mark-paid'),
        securityV1Headers('security-assoc-array-allowed', ['id' => 'security-assoc-array-allowed'], command: 'orders.mark-paid')
    );
    $trueConfig = securityReceive(
        securityEnvelope('security-assoc-true-allowed', ['id' => 'security-assoc-true-allowed'], 'invoices.sync-status'),
        securityV1Headers('security-assoc-true-allowed', ['id' => 'security-assoc-true-allowed'], command: 'invoices.sync-status')
    );
    $unlisted = securityReceive(
        securityEnvelope('security-assoc-rejected', ['id' => 'security-assoc-rejected'], 'orders.cancelled'),
        securityV1Headers('security-assoc-rejected', ['id' => 'security-assoc-rejected'], command: 'orders.cancelled')
    );

    expect($arrayConfig->getStatusCode())->toBe(202)
        ->and($trueConfig->getStatusCode())->toBe(202)
        ->and($unlisted->getStatusCode())->toBe(403)
        ->and($unlisted->getData(true)['error'])->toBe('command_not_allowed');
});

test('allow all commands only permits missing allowlist when explicitly true', function (): void {
    Queue::fake();
    config(['talkto.incoming.source-service' => [
        'secret' => 'fake-test-secret',
        'allow_all_commands' => true,
    ]]);

    $allowed = securityReceive(
        securityEnvelope('security-allow-all-true', ['id' => 'security-allow-all-true'], 'unlisted.internal-command'),
        securityV1Headers('security-allow-all-true', ['id' => 'security-allow-all-true'], command: 'unlisted.internal-command')
    );

    config(['talkto.incoming.source-service' => [
        'secret' => 'fake-test-secret',
        'allow_all_commands' => false,
    ]]);

    $rejected = securityReceive(
        securityEnvelope('security-allow-all-false', ['id' => 'security-allow-all-false'], 'unlisted.internal-command'),
        securityV1Headers('security-allow-all-false', ['id' => 'security-allow-all-false'], command: 'unlisted.internal-command')
    );

    expect($allowed->getStatusCode())->toBe(202)
        ->and($rejected->getStatusCode())->toBe(403)
        ->and($rejected->getData(true)['error'])->toBe('command_not_allowed');
});

function securityEnvelope(string $messageId, array $payload, string $command = 'domain.command'): array
{
    return [
        'message_id' => $messageId,
        'source' => 'source-service',
        'target' => 'target-service',
        'command' => $command,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'payload' => $payload,
    ];
}

function securityV1Headers(string $messageId, array $payload, ?string $timestamp = null, string $command = 'domain.command'): array
{
    $timestamp ??= now()->toIso8601String();
    $payloadHash = app(TalktoPayloadHasher::class)->hash($payload);

    return [
        'X-Talkto-Signature' => app(TalktoSigner::class)->sign(
            $messageId,
            $timestamp,
            'source-service',
            'target-service',
            $command,
            $payloadHash,
            'fake-test-secret'
        ),
        'X-Talkto-Timestamp' => $timestamp,
        'X-Talkto-Message-Id' => $messageId,
    ];
}

function securityV2Headers(string $messageId, array $payload, ?string $timestamp = null, ?string $nonce = null, string $command = 'domain.command'): array
{
    $timestamp ??= now()->toIso8601String();
    $nonce ??= 'nonce-'.$messageId;
    $payloadHash = app(TalktoPayloadHasher::class)->hash($payload);

    return [
        'X-Talkto-Signature-Version' => 'v2',
        'X-Talkto-Signature' => app(TalktoSigner::class)->signV2(
            $timestamp,
            $nonce,
            $messageId,
            'source-service',
            'target-service',
            $command,
            $payloadHash,
            'fake-test-secret'
        ),
        'X-Talkto-Timestamp' => $timestamp,
        'X-Talkto-Message-Id' => $messageId,
        'X-Talkto-Payload-Hash' => $payloadHash,
        'X-Talkto-Nonce' => $nonce,
    ];
}

function securityReceive(array $envelope, array $headers): JsonResponse
{
    $request = Request::create('/api/talkto/receive', 'POST', $envelope);

    foreach ($headers as $key => $value) {
        $request->headers->set($key, $value);
    }

    return app(TalktoReceiveController::class)->__invoke($request, app(TalktoSignatureVerifier::class));
}
