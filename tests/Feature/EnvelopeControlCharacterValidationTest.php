<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Exceptions\TalktoInvalidEnvelopeFieldException;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoNonce;
use Mrezdev\LaravelTalkto\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\LaravelTalktoHttpClient;
use Mrezdev\LaravelTalkto\Services\TalktoEnvelopeFieldValidator;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoJsonEncoder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingTargetRegistry;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackReceiver;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    P4PayloadCaptureHandler::$payload = null;

    config([
        'talkto.service' => 'inventory',
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v1', 'v2'],
        'talkto.security.require_signature' => true,
        'talkto.security.replay_protection.enabled' => true,
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
        'talkto.dead_letter.enabled' => true,
        'talkto.dead_letter.auto_store_on_final_failure' => true,
        'talkto.outgoing.website' => [
            'base_url' => 'https://website.test',
            'secret' => 'phase4-secret',
            'receive_endpoint' => '/api/talkto/receive',
            'callback_endpoint' => '/api/talkto/callback',
        ],
        'talkto.incoming.website' => [
            'secret' => 'phase4-secret',
            'allowed_commands' => [
                'catalog.sync' => [
                    'driver' => 'handler',
                    'handler' => P4PayloadCaptureHandler::class,
                ],
            ],
        ],
    ]);
});

test('envelope field validator accepts ordinary non control identifiers', function (): void {
    $validator = app(TalktoEnvelopeFieldValidator::class);

    foreach ([
        'abcXYZ123',
        'service-name_1.domain/path:part',
        'value with spaces',
        'متن فارسی',
        'نص عربي',
        'équipe.service',
        '命令.更新',
        'emoji-🚀',
    ] as $value) {
        expect($validator->validateIdentifier('command', $value))->toBe($value);
    }
});

test('envelope field validator rejects ascii controls del and unicode separators without leaking values', function (): void {
    $validator = app(TalktoEnvelopeFieldValidator::class);
    $badValues = [];

    for ($byte = 0; $byte <= 31; $byte++) {
        $badValues[] = 'safe'.chr($byte).'tail';
    }

    $badValues[] = 'safe'.chr(127).'tail';
    $badValues[] = "safe\u{2028}tail";
    $badValues[] = "safe\u{2029}tail";

    foreach ($badValues as $value) {
        try {
            $validator->validateIdentifier('command', $value);

            $this->fail('Expected invalid envelope field exception.');
        } catch (TalktoInvalidEnvelopeFieldException $exception) {
            expect($exception->field)->toBe('command')
                ->and($exception->errorCode)->toBe('invalid_envelope_field')
                ->and($exception->reason)->toBe('control_character')
                ->and($exception->getMessage())->toContain('[command]')
                ->and($exception->getMessage())->not->toContain($value);
        }
    }
});

test('header name validator accepts rfc token names and rejects unsafe names without leaking values', function (): void {
    $validator = app(TalktoEnvelopeFieldValidator::class);

    foreach ([
        'X-Talkto-Nonce',
        'X-Talkto-Signature-Version',
        'Content-Type',
        'Accept',
        'X-Internal-Trace',
        'X_API_COMPAT',
    ] as $name) {
        expect($validator->validateHeaderName('header_name', $name))->toBe($name);
    }

    $badNames = [
        '',
        'Header With Space',
        "Header\tName",
        "Header\rName",
        "Header\nName",
        "Header\0Name",
        'Header:Name',
        'Header/Name',
        'هدربد',
    ];

    for ($byte = 0; $byte <= 31; $byte++) {
        $badNames[] = 'Header'.chr($byte).'Name';
    }

    $badNames[] = 'Header'.chr(127).'Name';

    foreach ($badNames as $name) {
        try {
            $validator->validateHeaderName('custom_header_name', $name);

            $this->fail('Expected invalid header name exception.');
        } catch (TalktoInvalidEnvelopeFieldException $exception) {
            expect($exception->field)->toBe('custom_header_name')
                ->and($exception->reason)->toBe('header_name')
                ->and($exception->errorCode)->toBe('invalid_envelope_field')
                ->and($exception->getMessage())->toBe('Invalid HTTP header name configured for Talkto.');

            if ($name !== '') {
                expect($exception->getMessage())->not->toContain($name);
            }
        }
    }
});

test('outgoing factory rejects unsafe identifiers before persistence dispatch or http', function (string $field): void {
    Queue::fake();
    Http::fake();

    $target = 'website';
    $command = 'catalog.sync';
    $options = ['message_id' => 'phase4-outgoing-'.$field];

    if ($field === 'target_service') {
        $target = "website\nshadow";
    } elseif ($field === 'source_service') {
        $options['source_service'] = "inventory\nshadow";
    } elseif ($field === 'command') {
        $command = "catalog.sync\nshadow";
    } elseif ($field === 'message_id') {
        $options['message_id'] = "phase4\nmessage";
    } elseif ($field === 'parent_message_id') {
        $options['parent_message_id'] = "parent\rshadow";
    } elseif ($field === 'correlation_id') {
        $options['correlation_id'] = "correlation\0shadow";
    }

    expect(fn () => app(TalktoOutgoingMessageFactory::class)->create($target, $command, ['ok' => true], $options))
        ->toThrow(TalktoInvalidEnvelopeFieldException::class);

    expect(TalktoMessage::query()->count())->toBe(0)
        ->and(TalktoEvent::query()->count())->toBe(0)
        ->and(TalktoAttempt::query()->count())->toBe(0)
        ->and(TalktoDeadLetter::query()->count())->toBe(0)
        ->and(TalktoNonce::query()->count())->toBe(0);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'target_service',
    'source_service',
    'command',
    'message_id',
    'parent_message_id',
    'correlation_id',
]);

test('unsafe configured protocol header names fail outgoing and incoming safely', function (string $configKey, string $unsafeName, string $field): void {
    Http::fake();

    $payload = ['ok' => true];
    $message = TalktoMessage::query()->create([
        'message_id' => 'phase4-config-header-'.$field,
        'direction' => 'outgoing',
        'source_service' => 'inventory',
        'target_service' => 'website',
        'command' => 'catalog.sync',
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
    ]);

    config()->set($configKey, $unsafeName);

    try {
        app(TalktoOutgoingEnvelopeBuilder::class)->buildHeaders($message);

        $this->fail('Expected configured header name to fail outgoing header construction.');
    } catch (TalktoInvalidEnvelopeFieldException $exception) {
        expect($exception->field)->toBe($field)
            ->and($exception->reason)->toBe('header_name')
            ->and($exception->getMessage())->not->toContain($unsafeName);
    }

    Http::assertNothingSent();

    $response = p4Receive(p4SignedEnvelope('phase4-incoming-config-header-'.$field, ['ok' => true]));
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(422)
        ->and($data['error'])->toBe('invalid_envelope_field')
        ->and($data['field'])->toBe($field)
        ->and(json_encode($data, JSON_THROW_ON_ERROR))->not->toContain($unsafeName)
        ->and(TalktoNonce::query()->count())->toBe(0)
        ->and(P4PayloadCaptureHandler::$payload)->toBeNull();
})->with([
    'signature version header name' => [
        'talkto.security.signature_version_header',
        "X-Talkto-Version\rInjected",
        'signature_version_header_name',
    ],
    'nonce header name' => [
        'talkto.security.nonce_header',
        "X-Talkto-Nonce\nInjected",
        'nonce_header_name',
    ],
]);

test('unsafe configured custom outgoing header name fails before persistence or http', function (): void {
    Http::fake();
    Queue::fake();

    config()->set('talkto.outgoing.website.headers', [
        "X-Talkto-Custom\rInjected" => 'safe',
    ]);

    expect(fn () => app(TalktoOutgoingMessageFactory::class)->create('website', 'catalog.sync', ['ok' => true], [
        'message_id' => 'phase4-custom-header-create',
    ]))->toThrow(TalktoInvalidEnvelopeFieldException::class);

    expect(TalktoMessage::query()->count())->toBe(0)
        ->and(TalktoEvent::query()->count())->toBe(0)
        ->and(TalktoAttempt::query()->count())->toBe(0)
        ->and(TalktoDeadLetter::query()->count())->toBe(0);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('programmatic target registry rejects unsafe names and aliases validate final resolved targets', function (): void {
    $registry = app(TalktoOutgoingTargetRegistry::class);

    expect(fn () => $registry->register("unsafe\nservice", ['base_url' => 'https://unsafe.test', 'secret' => 'secret']))
        ->toThrow(TalktoInvalidEnvelopeFieldException::class);

    config([
        'talkto.aliases.short' => 'website',
    ]);

    $message = app(TalktoOutgoingMessageFactory::class)->create('short', 'catalog.sync', ['ok' => true], [
        'message_id' => 'phase4-alias-safe',
    ]);

    expect($message->target_service)->toBe('website');
});

test('programmatic target registry rejects unsafe custom header names', function (): void {
    $registry = app(TalktoOutgoingTargetRegistry::class);
    $unsafeName = "X-Talkto-Custom\nInjected";

    try {
        $registry->register('unsafe-header-target', [
            'base_url' => 'https://unsafe.test',
            'secret' => 'secret',
            'headers' => [
                $unsafeName => 'safe',
            ],
        ]);

        $this->fail('Expected invalid custom header name exception.');
    } catch (TalktoInvalidEnvelopeFieldException $exception) {
        expect($exception->field)->toBe('header_name')
            ->and($exception->reason)->toBe('header_name')
            ->and($exception->getMessage())->not->toContain($unsafeName);
    }
});

test('signer refuses unsafe canonical fields and preserves valid v1 and v2 canonical strings', function (): void {
    $signer = app(TalktoSigner::class);
    $payloadHash = str_repeat('a', 64);

    expect(fn () => $signer->sign('message-1', '1700000000', 'website', 'inventory', "catalog\nsync", $payloadHash, 'secret'))
        ->toThrow(TalktoInvalidEnvelopeFieldException::class);

    expect(fn () => $signer->signV2('1700000000', "nonce\n1", 'message-1', 'website', 'inventory', 'catalog.sync', $payloadHash, 'secret'))
        ->toThrow(TalktoInvalidEnvelopeFieldException::class);

    $v1Canonical = 'message-1.1700000000.website.inventory.catalog.sync.'.$payloadHash;
    $v2Canonical = "v2\n1700000000\nnonce-1\nmessage-1\nwebsite\ninventory\ncatalog.sync\n".$payloadHash;

    expect($signer->canonicalString('message-1', '1700000000', 'website', 'inventory', 'catalog.sync', $payloadHash))->toBe($v1Canonical)
        ->and($signer->sign('message-1', '1700000000', 'website', 'inventory', 'catalog.sync', $payloadHash, 'secret'))->toBe(hash_hmac('sha256', $v1Canonical, 'secret'))
        ->and($signer->canonicalStringV2('1700000000', 'nonce-1', 'message-1', 'website', 'inventory', 'catalog.sync', $payloadHash))->toBe($v2Canonical)
        ->and($signer->signV2('1700000000', 'nonce-1', 'message-1', 'website', 'inventory', 'catalog.sync', $payloadHash, 'secret'))->toBe(hash_hmac('sha256', $v2Canonical, 'secret'));
});

test('receive endpoint rejects unsafe envelope and header fields before nonce persistence or handling', function (string $field): void {
    $envelope = p4SignedEnvelope('phase4-receive-'.$field, ['ok' => true]);
    $headers = [];

    if ($field === 'source_service') {
        $envelope['source'] = "website\nshadow";
    } elseif ($field === 'target_service') {
        $envelope['target'] = "inventory\rshadow";
    } elseif ($field === 'command') {
        $envelope['command'] = "catalog.sync\nshadow";
    } elseif ($field === 'message_id') {
        $envelope['message_id'] = "phase4\0message";
    } elseif ($field === 'nonce') {
        $headers['X-Talkto-Nonce'] = "nonce\nshadow";
    } elseif ($field === 'signature_version') {
        $headers['X-Talkto-Signature-Version'] = "v2\nv1";
    } elseif ($field === 'timestamp') {
        $headers['X-Talkto-Timestamp'] = time()."\nshadow";
    } elseif ($field === 'payload_hash') {
        $headers['X-Talkto-Payload-Hash'] = $envelope['payload_hash']."\nshadow";
    }

    $response = p4Receive($envelope, $headers);

    expect($response->getStatusCode())->toBe(422);

    $data = $response->getData(true);
    expect($data['received'])->toBeFalse()
        ->and($data['error'])->toBe('invalid_envelope_field')
        ->and($data)->toHaveKey('field')
        ->and(TalktoMessage::query()->count())->toBe(0)
        ->and(TalktoEvent::query()->count())->toBe(0)
        ->and(TalktoNonce::query()->count())->toBe(0)
        ->and(P4PayloadCaptureHandler::$payload)->toBeNull();
})->with([
    'source_service',
    'target_service',
    'command',
    'message_id',
    'nonce',
    'signature_version',
    'timestamp',
    'payload_hash',
]);

test('direct verifier rejects unsafe header names immediately', function (): void {
    $unsafeName = "X-Talkto-Bad\rName";
    $verification = app(TalktoSignatureVerifier::class)->verifyEnvelope(
        p4SignedEnvelope('phase4-direct-bad-header-name', ['ok' => true]),
        array_merge(p4HeadersForEnvelope(p4SignedEnvelope('phase4-direct-bad-header-name', ['ok' => true])), [
            $unsafeName => 'safe',
        ])
    );

    expect($verification['ok'])->toBeFalse()
        ->and($verification['status'])->toBe(422)
        ->and($verification['error'])->toBe('invalid_envelope_field')
        ->and($verification['field'])->toBe('header_name')
        ->and(json_encode($verification, JSON_THROW_ON_ERROR))->not->toContain($unsafeName)
        ->and(TalktoNonce::query()->count())->toBe(0);
});

test('multi value custom headers inspect unsafe second item', function (): void {
    $envelope = p4SignedEnvelope('phase4-custom-multi-value', ['ok' => true]);
    $headers = p4HeadersForEnvelope($envelope);
    $headers['X-Internal-Trace'] = ['safe-value', "unsafe\nvalue"];

    $verification = app(TalktoSignatureVerifier::class)->verifyEnvelope($envelope, $headers);

    expect($verification['ok'])->toBeFalse()
        ->and($verification['status'])->toBe(422)
        ->and($verification['error'])->toBe('invalid_envelope_field')
        ->and($verification['field'])->toBe('header_value')
        ->and(TalktoNonce::query()->count())->toBe(0);
});

test('protocol headers reject duplicate logical values instead of selecting first', function (string $header, string $field): void {
    $envelope = p4SignedEnvelope('phase4-duplicate-'.$field, ['ok' => true]);
    $headers = p4HeadersForEnvelope($envelope);
    $headers[$header] = ['safe-one', 'safe-two'];

    $verification = app(TalktoSignatureVerifier::class)->verifyEnvelope($envelope, $headers);

    expect($verification['ok'])->toBeFalse()
        ->and($verification['status'])->toBe(422)
        ->and($verification['error'])->toBe('invalid_header_value_count')
        ->and($verification['field'])->toBe($field)
        ->and(TalktoNonce::query()->count())->toBe(0);
})->with([
    'timestamp' => ['X-Talkto-Timestamp', 'timestamp'],
    'signature' => ['X-Talkto-Signature', 'signature'],
    'signature version' => ['X-Talkto-Signature-Version', 'signature_version'],
    'nonce' => ['X-Talkto-Nonce', 'nonce'],
    'payload hash' => ['X-Talkto-Payload-Hash', 'payload_hash'],
    'message id' => ['X-Talkto-Message-Id', 'message_id'],
]);

test('valid multi value custom headers remain supported by validator', function (): void {
    app(TalktoEnvelopeFieldValidator::class)->validateTalktoHeaders([
        'X-Internal-Trace' => ['trace-1', 'trace-2'],
        'X-Service-Region' => ['eu-west', 'us-east'],
        'X_API_COMPAT' => [1, 2],
    ]);

    expect(true)->toBeTrue();
});

test('unsafe source is rejected as invalid envelope field instead of unknown source', function (): void {
    $envelope = p4SignedEnvelope('phase4-unsafe-source', ['ok' => true]);
    $envelope['source'] = "unknown\nsource";

    $data = p4Receive($envelope)->getData(true);

    expect($data['error'])->toBe('invalid_envelope_field')
        ->and($data['field'])->toBe('source_service')
        ->and(TalktoMessage::query()->count())->toBe(0);
});

test('callback receiver rejects unsafe callback identity without applying callback or storing nonce', function (string $field): void {
    config([
        'talkto.service' => 'website',
        'talkto.incoming.inventory' => [
            'secret' => 'phase4-secret',
            'allowed_commands' => [
                'talkto.result' => true,
            ],
        ],
    ]);

    $original = p4OutgoingMessageForCallback('phase4-callback-original');
    $envelope = p4CallbackEnvelope($original);
    $headers = [];

    if ($field === 'command') {
        $envelope['command'] = "talkto.result\nshadow";
    } elseif ($field === 'original_message_id') {
        $envelope['payload']['original_message_id'] = "phase4-callback-original\nshadow";
        $envelope['payload_hash'] = app(TalktoPayloadHasher::class)->hash($envelope['payload']);
    } elseif ($field === 'nonce') {
        $headers['X-Talkto-Nonce'] = "nonce\rshadow";
    }

    $response = app(TalktoResultCallbackReceiver::class)->receiveResult(
        $envelope,
        p4HeadersForEnvelope($envelope, $headers)
    );

    expect($response['accepted'])->toBeFalse()
        ->and($response['error'])->toBe('invalid_envelope_field')
        ->and($response)->toHaveKey('field')
        ->and($original->fresh()->overall_status)->toBe('waiting_to_send')
        ->and(TalktoEvent::query()->where('event_type', 'result_callback_received')->exists())->toBeFalse()
        ->and(TalktoNonce::query()->count())->toBe(0);
})->with([
    'command',
    'original_message_id',
    'nonce',
]);

test('callback receiver rejects unsafe header name without applying callback or storing nonce', function (): void {
    config([
        'talkto.service' => 'website',
        'talkto.incoming.inventory' => [
            'secret' => 'phase4-secret',
            'allowed_commands' => [
                'talkto.result' => true,
            ],
        ],
    ]);

    $unsafeName = "X-Talkto-Bad\nName";
    $original = p4OutgoingMessageForCallback('phase4-callback-bad-header-name');
    $envelope = p4CallbackEnvelope($original);
    $headers = p4HeadersForEnvelope($envelope);
    $headers[$unsafeName] = 'safe';

    $response = app(TalktoResultCallbackReceiver::class)->receiveResult($envelope, $headers);

    expect($response['accepted'])->toBeFalse()
        ->and($response['error'])->toBe('invalid_envelope_field')
        ->and($response['field'])->toBe('header_name')
        ->and(json_encode($response, JSON_THROW_ON_ERROR))->not->toContain($unsafeName)
        ->and($original->fresh()->overall_status)->toBe('waiting_to_send')
        ->and(TalktoEvent::query()->where('event_type', 'result_callback_received')->exists())->toBeFalse()
        ->and(TalktoNonce::query()->count())->toBe(0);
});

test('payload newlines tabs trailing spaces and empty strings remain valid and unchanged', function (): void {
    $payload = [
        'message' => "line one\nline two",
        'description' => "\tindented payload value",
        'trailing' => 'keep ',
        'empty' => '',
    ];
    $envelope = p4SignedEnvelope('phase4-payload-newlines', $payload);
    $response = p4Receive($envelope);

    expect($response->getStatusCode())->toBe(202)
        ->and(TalktoMessage::query()->where('message_id', 'phase4-payload-newlines')->exists())->toBeTrue();

    $message = TalktoMessage::query()->where('message_id', 'phase4-payload-newlines')->sole();

    (new ProcessIncomingTalktoMessage($message->id))->handle();

    expect(P4PayloadCaptureHandler::$payload)->toBe($payload);
});

test('payload multiline unicode and common valid identifiers remain accepted', function (): void {
    foreach ([
        'talkto.result',
        'webhook:update-stock',
        'app:get-message',
        'inventory',
        'payment',
        'chat',
        'website',
    ] as $identifier) {
        expect(app(TalktoEnvelopeFieldValidator::class)->validateIdentifier('command', $identifier))->toBe($identifier);
    }

    $payload = [
        'message' => "line one\nline two",
        'tabbed' => "\tvalue",
        'empty' => '',
        'persian' => "متن\nچندخطی",
    ];

    $response = p4Receive(p4SignedEnvelope('phase4-payload-unicode-multiline', $payload));

    expect($response->getStatusCode())->toBe(202);

    $message = TalktoMessage::query()->where('message_id', 'phase4-payload-unicode-multiline')->sole();
    (new ProcessIncomingTalktoMessage($message->id))->handle();

    expect(P4PayloadCaptureHandler::$payload)->toBe($payload);
});

test('ordinary unicode identifiers and generated ids nonces and callback command remain accepted', function (): void {
    config([
        'talkto.service' => 'موجودی',
        'talkto.outgoing.وبسایت' => [
            'base_url' => 'https://website.test',
            'secret' => 'phase4-secret',
        ],
    ]);

    $message = app(TalktoOutgoingMessageFactory::class)->create('وبسایت', 'کاتالوگ.همگام‌سازی 🚀', ['ok' => true]);
    $headers = app(TalktoOutgoingEnvelopeBuilder::class)->buildHeaders($message);

    expect($message->message_id)->not->toBe('')
        ->and($message->correlation_id)->not->toBe('')
        ->and($message->source_service)->toBe('موجودی')
        ->and($message->target_service)->toBe('وبسایت')
        ->and($headers['X-Talkto-Signature-Version'])->toBe('v2')
        ->and($headers['X-Talkto-Nonce'])->not->toBe('')
        ->and($headers['X-Talkto-Nonce'])->not->toContain("\n");

    config([
        'talkto.service' => 'inventory',
    ]);

    $incoming = p4IncomingMessage('phase4-generated-callback-original');
    $callback = app(TalktoResultCallbackMessageFactory::class)
        ->createForIncomingResult($incoming, TalktoIncomingCommandResult::succeeded());

    expect($callback->message_id)->toStartWith('cb-')
        ->and($callback->command)->toBe('talkto.result');
});

test('historical corrupted outgoing row fails locally without http retry loop or unsafe event details', function (): void {
    Http::fake();

    $payload = ['ok' => true];
    $message = TalktoMessage::query()->create([
        'message_id' => 'phase4-history-corrupt',
        'direction' => 'outgoing',
        'source_service' => 'inventory',
        'target_service' => 'website',
        'command' => "catalog.sync\nX-Fake: yes",
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
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
    $attempt = TalktoAttempt::query()->where('message_id', 'phase4-history-corrupt')->sole();
    $event = TalktoEvent::query()->where('message_id', 'phase4-history-corrupt')->where('event_type', 'message_send_failed')->sole();

    expect($message->overall_status)->toBe('failed_final')
        ->and($message->next_retry_at)->toBeNull()
        ->and($message->last_error)->toBe('invalid_envelope_field')
        ->and($attempt->error_class)->toBe('invalid_envelope_field')
        ->and($attempt->error_message)->toContain('[command]')
        ->and($attempt->error_message)->not->toContain("catalog.sync\nX-Fake")
        ->and($attempt->meta['field_name'])->toBe('command')
        ->and($event->meta['field_name'])->toBe('command')
        ->and(json_encode($event->meta, JSON_THROW_ON_ERROR))->not->toContain("catalog.sync\nX-Fake");
});

test('historical outgoing row with unsafe target header config fails locally without leaking header name', function (): void {
    Http::fake();

    $unsafeName = "X-Talkto-Custom\rInjected";
    config()->set('talkto.outgoing.website.headers', [
        $unsafeName => 'safe',
    ]);

    $payload = ['ok' => true];
    $message = TalktoMessage::query()->create([
        'message_id' => 'phase4-history-bad-header-config',
        'direction' => 'outgoing',
        'source_service' => 'inventory',
        'target_service' => 'website',
        'command' => 'catalog.sync',
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
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
    $attempt = TalktoAttempt::query()->where('message_id', 'phase4-history-bad-header-config')->latest('id')->firstOrFail();
    $event = TalktoEvent::query()->where('message_id', 'phase4-history-bad-header-config')->where('event_type', 'message_send_failed')->sole();

    expect($message->overall_status)->toBe('failed_final')
        ->and($message->next_retry_at)->toBeNull()
        ->and($message->last_error)->toBe('invalid_envelope_field')
        ->and($attempt->error_class)->toBe('invalid_envelope_field')
        ->and($attempt->error_message)->toBe('Invalid HTTP header name configured for Talkto.')
        ->and($attempt->error_message)->not->toContain($unsafeName)
        ->and($attempt->meta['field_name'])->toBe('header_name')
        ->and($event->meta['field_name'])->toBe('header_name')
        ->and(json_encode($event->meta, JSON_THROW_ON_ERROR))->not->toContain($unsafeName);
});

test('envelope builder rejects unsafe persisted identifiers before header or body construction', function (): void {
    $payload = ['ok' => true];
    $message = TalktoMessage::query()->create([
        'message_id' => 'phase4-builder-corrupt',
        'direction' => 'outgoing',
        'source_service' => 'inventory',
        'target_service' => 'website',
        'command' => "catalog.sync\rshadow",
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
    ]);

    expect(fn () => app(TalktoOutgoingEnvelopeBuilder::class)->buildEnvelope($message))
        ->toThrow(TalktoInvalidEnvelopeFieldException::class);

    expect(fn () => app(TalktoOutgoingEnvelopeBuilder::class)->buildHeaders($message))
        ->toThrow(TalktoInvalidEnvelopeFieldException::class);
});

test('default http client rejects unsafe talkto headers before laravel http receives them', function (): void {
    Http::fake();

    expect(fn () => app(LaravelTalktoHttpClient::class)->post(
        'https://website.test/api/talkto/receive',
        ['X-Talkto-Message-Id' => "phase4\rheader"],
        ['message_id' => 'phase4-header'],
        5
    ))->toThrow(TalktoInvalidEnvelopeFieldException::class);

    Http::assertNothingSent();
});

function p4SignedEnvelope(string $messageId, array $payload, array $overrides = []): array
{
    $payloadHash = app(TalktoPayloadHasher::class)->hash($payload);

    return array_replace([
        'protocol_version' => 2,
        'message_id' => $messageId,
        'correlation_id' => 'phase4-correlation',
        'parent_message_id' => null,
        'source' => 'website',
        'target' => 'inventory',
        'command' => 'catalog.sync',
        'business_key' => null,
        'idempotency_key' => null,
        'schema_version' => 1,
        'created_at' => '2026-01-02T03:04:05+00:00',
        'payload_hash' => $payloadHash,
        'payload' => $payload,
    ], $overrides);
}

function p4HeadersForEnvelope(array $envelope, array $overrides = []): array
{
    $timestamp = $overrides['X-Talkto-Timestamp'] ?? (string) time();
    $nonce = $overrides['X-Talkto-Nonce'] ?? 'phase4-nonce-'.$envelope['message_id'];
    $version = $overrides['X-Talkto-Signature-Version'] ?? 'v2';
    $payloadHash = $overrides['X-Talkto-Payload-Hash'] ?? $envelope['payload_hash'];
    $messageId = $overrides['X-Talkto-Message-Id'] ?? $envelope['message_id'];
    $signature = p4LegacyV2Signature(
        (string) $timestamp,
        (string) $nonce,
        (string) $messageId,
        (string) $envelope['source'],
        (string) $envelope['target'],
        (string) $envelope['command'],
        (string) $envelope['payload_hash']
    );

    return array_replace([
        'X-Talkto-Signature' => $signature,
        'X-Talkto-Timestamp' => $timestamp,
        'X-Talkto-Message-Id' => $messageId,
        'X-Talkto-Protocol-Version' => '2',
        'X-Talkto-Signature-Version' => $version,
        'X-Talkto-Payload-Hash' => $payloadHash,
        'X-Talkto-Nonce' => $nonce,
    ], $overrides);
}

function p4Receive(array $envelope, array $headerOverrides = []): JsonResponse
{
    $headers = p4HeadersForEnvelope($envelope, $headerOverrides);
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $request = Request::create(
        '/api/talkto/receive',
        'POST',
        [],
        [],
        [],
        $server,
        app(TalktoJsonEncoder::class)->encode($envelope)
    );

    return app(ReceiveIncomingTalktoMessagePipeline::class)->receive($request, app(TalktoSignatureVerifier::class));
}

function p4LegacyV2Signature(
    string $timestamp,
    string $nonce,
    string $messageId,
    string $source,
    string $target,
    string $command,
    string $payloadHash
): string {
    return hash_hmac('sha256', implode("\n", [
        'v2',
        $timestamp,
        $nonce,
        $messageId,
        $source,
        $target,
        $command,
        $payloadHash,
    ]), 'phase4-secret');
}

function p4OutgoingMessageForCallback(string $messageId): TalktoMessage
{
    $payload = ['original' => true];

    return TalktoMessage::query()->create([
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source_service' => 'website',
        'target_service' => 'inventory',
        'command' => 'catalog.sync',
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'waiting_for_callback',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'waiting_to_send',
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
    ]);
}

function p4CallbackEnvelope(TalktoMessage $original): array
{
    $payload = [
        'original_message_id' => $original->message_id,
        'original_command' => $original->command,
        'status' => 'succeeded',
        'succeeded' => true,
        'retryable' => false,
        'skipped' => false,
        'error_class' => null,
        'error_message' => null,
        'result' => [],
        'meta' => [],
    ];

    return [
        'protocol_version' => 2,
        'message_id' => 'cb-phase4-'.$original->message_id,
        'correlation_id' => 'phase4-callback-correlation',
        'parent_message_id' => $original->message_id,
        'source' => 'inventory',
        'target' => 'website',
        'command' => 'talkto.result',
        'business_key' => null,
        'idempotency_key' => null,
        'schema_version' => 1,
        'created_at' => '2026-01-02T03:04:05+00:00',
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'payload' => $payload,
    ];
}

function p4IncomingMessage(string $messageId): TalktoMessage
{
    $payload = ['original' => true];

    return TalktoMessage::query()->create([
        'message_id' => $messageId,
        'direction' => 'incoming',
        'source_service' => 'website',
        'target_service' => 'inventory',
        'command' => 'catalog.sync',
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
    ]);
}

class P4PayloadCaptureHandler implements TalktoIncomingCommandHandler
{
    public static ?array $payload = null;

    public function handle(TalktoMessage $message): TalktoIncomingCommandResult
    {
        self::$payload = $message->payload;

        return TalktoIncomingCommandResult::succeeded();
    }
}
