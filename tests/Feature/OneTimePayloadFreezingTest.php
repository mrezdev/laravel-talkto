<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Data\TalktoIncomingCommandResultData;
use Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData;
use Mrezdev\LaravelTalkto\Exceptions\TalktoUnsupportedPayloadValueException;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoJsonEncoder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackSender;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    P3FailingTalktoEvent::$failOnCreate = false;
    P3CountingEloquentPayload::$relationCalls = 0;

    config([
        'talkto.service' => 'inventory',
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.replay_protection.enabled' => false,
        'talkto.dead_letter.enabled' => true,
        'talkto.dead_letter.auto_store_on_final_failure' => true,
        'talkto.outgoing.website' => [
            'base_url' => 'https://website.test',
            'secret' => 'freeze-secret',
            'receive_endpoint' => '/api/talkto/receive',
            'callback_endpoint' => '/api/talkto/callback',
        ],
        'talkto.incoming.website' => [
            'secret' => 'freeze-secret',
            'allowed_commands' => [
                'talkto.result' => true,
            ],
        ],
    ]);
});

test('outgoing payloads are frozen once and reused for hash persistence envelope and http body', function (): void {
    $nested = new P3CountingJsonSerializable(['json' => 'first']);
    $arrayable = new P3CountingArrayable(['arrayable' => true, 'nested' => new P3CountingJsonSerializable('inside')]);
    $mutable = new P3CountingJsonSerializable(fn (int $calls): array => ['call' => $calls]);
    $carbon = CarbonImmutable::parse('2026-01-03T04:05:06.123456+00:00');
    $object = new P3PublicPayloadObject('public-name', $nested);

    $message = app(TalktoOutgoingMessageFactory::class)->create(
        target: 'website',
        command: 'catalog.sync',
        payload: [
            'items' => [
                ['sku' => 'A-1', 'stock' => 79.95, 'active' => true],
                ['sku' => 'A-2', 'status' => P3BackedFreezingStatus::Ready],
            ],
            'arrayable' => $arrayable,
            'mutable' => $mutable,
            'carbon_date' => $carbon,
            'object' => $object,
        ],
        options: [
            'message_id' => 'freeze-main',
            'idempotency_key' => 'freeze-main',
        ]
    );

    $frozen = $message->fresh()->payload;

    p3AssertPrimitiveTree($frozen);

    expect($nested->calls)->toBe(1)
        ->and($arrayable->calls)->toBe(1)
        ->and($arrayable->payload['nested']->calls)->toBe(1)
        ->and($mutable->calls)->toBe(1)
        ->and($frozen['mutable'])->toBe(['call' => 1])
        ->and($frozen['items'][1]['status'])->toBe('ready')
        ->and($frozen['carbon_date'])->toBe($carbon->jsonSerialize())
        ->and($message->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($frozen));

    $envelope = app(TalktoOutgoingEnvelopeBuilder::class)->buildEnvelope($message->fresh());

    expect($envelope['payload'])->toBe($frozen)
        ->and($envelope['payload_hash'])->toBe($message->payload_hash);

    $capturedEnvelope = null;

    Http::fake(function (Request $request) use (&$capturedEnvelope) {
        $capturedEnvelope = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

        return Http::response(['received' => true, 'status' => 'queued'], 200);
    });

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect($nested->calls)->toBe(1)
        ->and($arrayable->payload['nested']->calls)->toBe(1)
        ->and($mutable->calls)->toBe(1)
        ->and($capturedEnvelope['payload'])->toBe($frozen)
        ->and($capturedEnvelope['payload_hash'])->toBe(app(TalktoPayloadHasher::class)->hash($frozen))
        ->and($message->fresh()->overall_status)->toBe('destination_received');
});

test('shared object instances are converted once and cached as primitive frozen results', function (): void {
    $sharedJson = new P3CountingJsonSerializable(fn (int $calls): array => ['call' => $calls]);
    $sharedArrayable = new P3CountingArrayable(['arrayable' => true]);
    $nestedShared = new P3CountingJsonSerializable(fn (int $calls): array => ['nested_call' => $calls]);
    $model = new P3CountingEloquentPayload([
        'sku' => 'MODEL-1',
        'stock' => '7',
        'active' => 1,
        'secret' => 'hidden-value',
    ]);

    $message = app(TalktoOutgoingMessageFactory::class)->create(
        target: 'website',
        command: 'catalog.sync',
        payload: [
            'json' => [
                'first' => $sharedJson,
                'second' => $sharedJson,
            ],
            'arrayable' => [
                'first' => $sharedArrayable,
                'second' => $sharedArrayable,
            ],
            'items' => [
                ['value' => $nestedShared],
                ['value' => $nestedShared],
            ],
            'model' => [
                'first' => $model,
                'second' => $model,
            ],
        ],
        options: ['message_id' => 'freeze-shared-instances'],
    );

    $frozen = $message->fresh()->payload;

    p3AssertPrimitiveTree($frozen);

    expect($sharedJson->calls)->toBe(1)
        ->and($sharedArrayable->calls)->toBe(1)
        ->and($nestedShared->calls)->toBe(1)
        ->and($model->toArrayCalls)->toBe(1)
        ->and(P3CountingEloquentPayload::$relationCalls)->toBe(0)
        ->and($frozen['json']['first'])->toBe(['call' => 1])
        ->and($frozen['json']['second'])->toBe($frozen['json']['first'])
        ->and($frozen['arrayable']['second'])->toBe($frozen['arrayable']['first'])
        ->and($frozen['items'][0]['value'])->toBe(['nested_call' => 1])
        ->and($frozen['items'][1]['value'])->toBe($frozen['items'][0]['value'])
        ->and($frozen['model']['second'])->toBe($frozen['model']['first'])
        ->and($frozen['model']['first'])->toMatchArray([
            'sku' => 'MODEL-1',
            'stock' => 7,
            'active' => true,
            'label' => 'MODEL-1-label',
        ])
        ->and($frozen['model']['first'])->not->toHaveKey('secret');

    $sharedJson->payload = fn (int $calls): array => ['call' => $calls + 100];
    $sharedArrayable->payload['arrayable'] = false;
    $model->forceFill(['sku' => 'MUTATED', 'stock' => '99']);

    $reloaded = $message->fresh()->payload;

    expect($reloaded)->toBe($frozen)
        ->and($sharedJson->calls)->toBe(1)
        ->and($sharedArrayable->calls)->toBe(1)
        ->and($model->toArrayCalls)->toBe(1);
});

test('collection payloads preserve keys and memoize nested supported objects', function (): void {
    $shared = new P3CountingJsonSerializable(fn (int $calls): array => ['call' => $calls]);
    $model = new P3CountingEloquentPayload([
        'sku' => 'COLL-1',
        'stock' => '3',
        'active' => 0,
        'secret' => 'hidden-value',
    ]);

    $message = app(TalktoOutgoingMessageFactory::class)->create(
        target: 'website',
        command: 'catalog.sync',
        payload: [
            'list' => new Collection([
                ['value' => $shared],
                ['value' => $shared],
            ]),
            'assoc' => new Collection([
                'first' => $shared,
                'model' => $model,
            ]),
            'empty' => new Collection,
        ],
        options: ['message_id' => 'freeze-collections'],
    );

    $frozen = $message->fresh()->payload;

    p3AssertPrimitiveTree($frozen);

    expect($shared->calls)->toBe(1)
        ->and($model->toArrayCalls)->toBe(1)
        ->and($frozen['list'])->toBe([
            ['value' => ['call' => 1]],
            ['value' => ['call' => 1]],
        ])
        ->and($frozen['assoc']['first'])->toBe(['call' => 1])
        ->and($frozen['assoc']['model'])->toMatchArray([
            'sku' => 'COLL-1',
            'stock' => 3,
            'active' => false,
            'label' => 'COLL-1-label',
        ])
        ->and($frozen['empty'])->toBe([]);
});

test('top level payload contract remains compatible with the original factory normalization', function (): void {
    $array = app(TalktoOutgoingMessageFactory::class)->create('website', 'catalog.sync', ['accepted' => true], [
        'message_id' => 'freeze-top-array',
    ]);
    $null = app(TalktoOutgoingMessageFactory::class)->create('website', 'catalog.sync', null, [
        'message_id' => 'freeze-top-null',
    ]);
    $string = app(TalktoOutgoingMessageFactory::class)->create('website', 'catalog.sync', 'top-level-string', [
        'message_id' => 'freeze-top-string',
    ]);
    $jsonScalar = app(TalktoOutgoingMessageFactory::class)->create(
        'website',
        'catalog.sync',
        new P3CountingJsonSerializable('json-scalar'),
        ['message_id' => 'freeze-top-json-scalar'],
    );

    expect($array->fresh()->payload)->toBe(['accepted' => true])
        ->and($null->fresh()->payload)->toBeNull()
        ->and($string->fresh()->payload)->toBe(['value' => 'top-level-string'])
        ->and($jsonScalar->fresh()->payload)->toBe(['value' => 'json-scalar']);

    expect(fn () => app(TalktoOutgoingMessageFactory::class)->create(
        'website',
        'catalog.sync',
        new P3PublicPayloadObject('top', new P3CountingJsonSerializable('nested')),
        ['message_id' => 'freeze-top-public-object']
    ))->toThrow(InvalidArgumentException::class);

    expect(TalktoMessage::query()->where('message_id', 'freeze-top-public-object')->exists())->toBeFalse();
});

test('idempotency duplicates return the persisted message without freezing a second payload', function (): void {
    $firstPayload = new P3CountingJsonSerializable(['value' => 'first']);
    $secondPayload = new P3CountingJsonSerializable(['value' => 'second']);

    $first = app(TalktoOutgoingMessageFactory::class)->create('website', 'catalog.sync', $firstPayload, [
        'message_id' => 'freeze-idempotent-first',
        'idempotency_key' => 'same-business-action',
    ]);
    $second = app(TalktoOutgoingMessageFactory::class)->create('website', 'catalog.sync', $secondPayload, [
        'message_id' => 'freeze-idempotent-second',
        'idempotency_key' => 'same-business-action',
    ]);

    expect($firstPayload->calls)->toBe(1)
        ->and($secondPayload->calls)->toBe(0)
        ->and($second->id)->toBe($first->id)
        ->and(TalktoMessage::query()->where('idempotency_key', 'same-business-action')->count())->toBe(1);
});

test('unsupported payload values fail before any message or event is persisted', function (): void {
    $cases = [
        'closure' => [
            'payload' => fn (): array => ['bad' => fn (): string => 'nope', 'secret' => new P3StringableSecret('secret-token')],
            'error' => 'payload_closure',
        ],
        'generator' => [
            'payload' => fn (): array => ['items' => p3GeneratorPayload()],
            'error' => 'payload_generator',
        ],
        'array object' => [
            'payload' => fn (): array => ['items' => new ArrayObject(['important' => 'value'])],
            'error' => 'payload_traversable_object',
        ],
        'iterator aggregate' => [
            'payload' => fn (): array => ['items' => new P3IteratorAggregatePayload(['important' => 'value'])],
            'error' => 'payload_traversable_object',
        ],
        'unsupported internal object' => [
            'payload' => fn (): array => ['timezone' => new DateTimeZone('UTC')],
            'error' => 'payload_internal_object',
        ],
        'callable object' => [
            'payload' => fn (): array => ['callable' => new P3InvokablePayload(['ambiguous' => true])],
            'error' => 'payload_callable_object',
        ],
        'resource' => [
            'payload' => fn (): array => ['stream' => fopen('php://temp', 'r')],
            'error' => 'payload_resource',
        ],
        'non-finite float' => [
            'payload' => fn (): array => ['stock' => INF],
            'error' => 'payload_non_finite_float',
        ],
        'pure enum' => [
            'payload' => fn (): array => ['status' => P3PureFreezingStatus::Ready],
            'error' => 'payload_unit_enum',
        ],
        'stringable object' => [
            'payload' => fn (): array => ['secret' => new P3StringableSecret('secret-token')],
            'error' => 'payload_stringable_object',
        ],
        'circular object' => [
            'payload' => fn (): array => ['node' => p3CircularObject()],
            'error' => 'payload_circular_reference',
        ],
        'circular array' => [
            'payload' => fn (): array => p3CircularArray(),
            'error' => 'payload_depth_exceeded',
        ],
        'excessive depth' => [
            'payload' => fn (): array => p3ExcessivelyNestedPayload(),
            'error' => 'payload_depth_exceeded',
        ],
        'invalid utf8' => [
            'payload' => fn (): array => ['bad' => "\xB1\x31"],
            'error' => 'payload_invalid_utf8',
        ],
    ];

    foreach ($cases as $name => $case) {
        $payload = $case['payload']();

        try {
            app(TalktoOutgoingMessageFactory::class)->create('website', 'catalog.sync', $payload, [
                'message_id' => 'freeze-reject-'.str_replace(' ', '-', $name),
            ]);

            $this->fail('Expected unsupported payload exception for '.$name.'.');
        } catch (TalktoUnsupportedPayloadValueException $exception) {
            expect($exception->payloadErrorCode)->toBe($case['error'])
                ->and($exception)->toBeInstanceOf(InvalidArgumentException::class)
                ->and($exception->getMessage())->toContain($case['error'])
                ->and($exception->getMessage())->not->toContain('secret-token');
        } finally {
            if (is_array($payload) && is_resource($payload['stream'] ?? null)) {
                fclose($payload['stream']);
            }
        }

        expect(TalktoMessage::query()->where('message_id', 'like', 'freeze-reject-%')->count())->toBe(0)
            ->and(TalktoEvent::query()->where('message_id', 'like', 'freeze-reject-%')->count())->toBe(0);
    }
});

test('dto object policy accepts explicit public data and rejects ambiguous hidden or callable state', function (): void {
    $stdClass = new stdClass;
    $stdClass->name = 'std';
    $stdClass->child = new P3CountingJsonSerializable('std-child');

    $publicDto = new P3PublicPayloadObject('public', new P3CountingJsonSerializable('public-child'));
    $invokableJson = new P3InvokableJsonSerializable(['invokable' => 'json']);

    $message = app(TalktoOutgoingMessageFactory::class)->create(
        target: 'website',
        command: 'catalog.sync',
        payload: [
            'std' => $stdClass,
            'public' => $publicDto,
            'invokable_json' => $invokableJson,
        ],
        options: ['message_id' => 'freeze-dto-policy'],
    );

    $frozen = $message->fresh()->payload;

    expect($stdClass->child->calls)->toBe(1)
        ->and($publicDto->child->calls)->toBe(1)
        ->and($invokableJson->calls)->toBe(1)
        ->and($frozen)->toBe([
            'std' => [
                'name' => 'std',
                'child' => 'std-child',
            ],
            'public' => [
                'name' => 'public',
                'child' => 'public-child',
            ],
            'invokable_json' => [
                'invokable' => 'json',
            ],
        ]);

    foreach ([
        'private-only' => new P3PrivateOnlyPayloadObject('secret'),
        'invokable' => new P3InvokablePayload(['value' => 'ambiguous']),
    ] as $name => $value) {
        expect(fn () => app(TalktoOutgoingMessageFactory::class)->create(
            'website',
            'catalog.sync',
            ['value' => $value],
            ['message_id' => 'freeze-dto-reject-'.$name],
        ))->toThrow(TalktoUnsupportedPayloadValueException::class);

        expect(TalktoMessage::query()->where('message_id', 'freeze-dto-reject-'.$name)->exists())->toBeFalse();
    }
});

test('carbon dates preserve json microseconds while native date time values are rejected explicitly', function (): void {
    $carbon = CarbonImmutable::parse('2026-01-02 03:04:05.987654', 'Asia/Tehran');
    $carbonOffset = CarbonImmutable::parse('2026-01-02T03:04:05.654321+04:30');

    $message = app(TalktoOutgoingMessageFactory::class)->create(
        target: 'website',
        command: 'catalog.sync',
        payload: [
            'carbon' => $carbon,
            'carbon_offset' => $carbonOffset,
        ],
        options: ['message_id' => 'freeze-carbon-dates'],
    );

    $reloaded = $message->fresh();

    expect($reloaded->payload)->toBe([
        'carbon' => $carbon->jsonSerialize(),
        'carbon_offset' => $carbonOffset->jsonSerialize(),
    ])
        ->and($reloaded->payload['carbon'])->toContain('.987654Z')
        ->and($reloaded->payload['carbon_offset'])->toContain('.654321Z')
        ->and($reloaded->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($reloaded->payload));

    foreach ([
        'datetime' => new DateTime('2026-01-02 03:04:05.987654', new DateTimeZone('UTC')),
        'datetime-immutable' => new DateTimeImmutable('2026-01-02 03:04:05.987654', new DateTimeZone('UTC')),
    ] as $name => $value) {
        try {
            app(TalktoOutgoingMessageFactory::class)->create(
                'website',
                'catalog.sync',
                ['date' => $value],
                ['message_id' => 'freeze-native-date-'.$name],
            );

            $this->fail('Expected native DateTime rejection for '.$name.'.');
        } catch (TalktoUnsupportedPayloadValueException $exception) {
            expect($exception)->toBeInstanceOf(InvalidArgumentException::class)
                ->and($exception->payloadErrorCode)->toBe('payload_datetime_unsupported');
        }

        expect(TalktoMessage::query()->where('message_id', 'freeze-native-date-'.$name)->exists())->toBeFalse();
    }
});

test('primitive payload hash and json output remain unchanged for already primitive payloads', function (): void {
    $payload = [
        'sku' => 'PRIM-1',
        'stock' => 79.95,
        'active' => true,
        'notes' => null,
        'tags' => ['a', 'b'],
        'meta' => [
            'b' => 2,
            'a' => 1,
        ],
    ];
    $expectedHash = app(TalktoPayloadHasher::class)->hash($payload);
    $expectedJson = app(TalktoJsonEncoder::class)->encode($payload);

    $message = app(TalktoOutgoingMessageFactory::class)->create('website', 'catalog.sync', $payload, [
        'message_id' => 'freeze-primitive-compatibility',
    ]);
    $reloaded = $message->fresh();

    expect($reloaded->payload)->toBe($payload)
        ->and($reloaded->payload_hash)->toBe($expectedHash)
        ->and(app(TalktoJsonEncoder::class)->encode($reloaded->payload))->toBe($expectedJson);

    $capturedEnvelope = null;

    Http::fake(function (Request $request) use (&$capturedEnvelope) {
        $capturedEnvelope = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

        return Http::response(['received' => true, 'status' => 'queued'], 200);
    });

    (new SendTalktoMessage($reloaded->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect($capturedEnvelope['payload'])->toBe($payload)
        ->and($capturedEnvelope['payload_hash'])->toBe($expectedHash);
});

test('message and event creation roll back together after payload freezing succeeds', function (): void {
    config(['talkto.models.event' => P3FailingTalktoEvent::class]);
    P3FailingTalktoEvent::$failOnCreate = true;
    $payload = new P3CountingJsonSerializable(['ready' => true]);

    expect(fn () => app(TalktoOutgoingMessageFactory::class)->create('website', 'catalog.sync', $payload, [
        'message_id' => 'freeze-rollback',
    ]))->toThrow(RuntimeException::class, 'Phase 3 event create failure.');

    expect($payload->calls)->toBe(1)
        ->and(TalktoMessage::query()->where('message_id', 'freeze-rollback')->exists())->toBeFalse()
        ->and(TalktoEvent::query()->where('message_id', 'freeze-rollback')->exists())->toBeFalse();
});

test('result callback payloads are frozen once before callback dispatch is queued', function (): void {
    Queue::fake();

    $resultPayload = new P3CountingJsonSerializable(['result_value' => 'frozen']);
    $metaPayload = new P3CountingArrayable(['meta_value' => new P3CountingJsonSerializable('also-frozen')]);
    $incoming = p3IncomingMessage('freeze-callback-original');

    $summary = app(TalktoResultCallbackSender::class)->sendResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(
            ['data' => $resultPayload],
            ['meta' => $metaPayload]
        )
    );

    $callback = TalktoMessage::query()
        ->where('parent_message_id', 'freeze-callback-original')
        ->where('command', 'talkto.result')
        ->sole();

    p3AssertPrimitiveTree($callback->payload);

    expect($summary['queued'])->toBeTrue()
        ->and($resultPayload->calls)->toBe(1)
        ->and($metaPayload->calls)->toBe(1)
        ->and($metaPayload->payload['meta_value']->calls)->toBe(1)
        ->and($callback->payload['result']['data'])->toBe(['result_value' => 'frozen'])
        ->and($callback->payload['meta']['meta']['meta_value'])->toBe('also-frozen')
        ->and($callback->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($callback->payload));

    Queue::assertPushed(SendTalktoMessage::class, 1);

    $capturedEnvelope = null;

    Http::fake(function (Request $request) use (&$capturedEnvelope) {
        $capturedEnvelope = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

        return Http::response(['accepted' => true, 'status' => 'succeeded'], 200);
    });

    (new SendTalktoMessage($callback->fresh()->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect($resultPayload->calls)->toBe(1)
        ->and($metaPayload->calls)->toBe(1)
        ->and($metaPayload->payload['meta_value']->calls)->toBe(1)
        ->and($capturedEnvelope['payload'])->toBe($callback->fresh()->payload)
        ->and($capturedEnvelope['payload_hash'])->toBe($callback->fresh()->payload_hash);
});

test('result callback data envelopes freeze payloads before calculating callback hashes', function (): void {
    $resultPayload = new P3CountingJsonSerializable(['direct' => 'envelope']);
    $incoming = p3IncomingMessage('freeze-callback-data-original');

    $callback = TalktoResultCallbackData::fromIncomingMessageResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(['data' => $resultPayload])
    );

    $envelope = $callback->toEnvelope();

    p3AssertPrimitiveTree($envelope['payload']);

    expect($resultPayload->calls)->toBe(1)
        ->and($envelope['payload']['result']['data'])->toBe(['direct' => 'envelope'])
        ->and($envelope['payload_hash'])->toBe(app(TalktoPayloadHasher::class)->hash($envelope['payload']));
});

test('result callback data reuses one immutable frozen payload snapshot across methods', function (): void {
    $shared = new P3CountingJsonSerializable(fn (int $calls): array => ['call' => $calls]);
    $incoming = p3IncomingMessage('freeze-callback-repeatable-original');

    $callback = TalktoResultCallbackData::fromIncomingMessageResult(
        $incoming,
        TalktoIncomingCommandResult::succeeded(
            ['data' => $shared],
            ['same' => $shared],
        )
    );

    $firstPayload = $callback->toPayload();
    $secondPayload = $callback->toPayload();
    $firstEnvelope = $callback->toEnvelope();
    $secondEnvelope = $callback->toEnvelope();

    expect($shared->calls)->toBe(1)
        ->and($firstPayload)->toBe($secondPayload)
        ->and($firstEnvelope)->toBe($secondEnvelope)
        ->and($firstEnvelope['payload'])->toBe($firstPayload)
        ->and($firstEnvelope['payload_hash'])->toBe($secondEnvelope['payload_hash'])
        ->and($firstEnvelope['payload_hash'])->toBe(app(TalktoPayloadHasher::class)->hash($firstPayload))
        ->and($firstPayload['result']['data'])->toBe(['call' => 1])
        ->and($firstPayload['meta']['same'])->toBe(['call' => 1]);
});

test('direct callback data construction remains compatible while freezing once', function (): void {
    $value = new P3CountingJsonSerializable(fn (int $calls): array => ['call' => $calls]);

    $callback = new TalktoResultCallbackData(
        'cb-direct-freeze',
        'direct-original',
        'catalog.sync',
        'direct-correlation',
        'direct-original',
        'inventory',
        'website',
        'talkto.result',
        null,
        null,
        'succeeded',
        new TalktoIncomingCommandResultData(
            succeeded: true,
            retryable: false,
            skipped: false,
            errorClass: null,
            errorMessage: null,
            result: ['data' => $value],
            meta: ['again' => $value],
        ),
    );

    $payload = $callback->toPayload();
    $envelope = $callback->toEnvelope();

    expect($value->calls)->toBe(1)
        ->and($payload['result']['data'])->toBe(['call' => 1])
        ->and($payload['meta']['again'])->toBe(['call' => 1])
        ->and($envelope['payload'])->toBe($payload)
        ->and($callback->toEnvelope())->toBe($envelope)
        ->and($value->calls)->toBe(1);
});

test('explicit callback snapshot arrays are validated frozen and memoized once', function (): void {
    $shared = new P3CountingJsonSerializable(fn (int $calls): array => ['call' => $calls]);

    $callback = p3CallbackDataWithSnapshot([
        'result' => [
            'first' => $shared,
            'second' => $shared,
        ],
    ]);

    $payload1 = $callback->toPayload();
    $payload2 = $callback->toPayload();
    $envelope1 = $callback->toEnvelope();
    $envelope2 = $callback->toEnvelope();

    p3AssertPrimitiveTree($payload1);

    expect($shared->calls)->toBe(1)
        ->and($payload1['result']['first'])->toBe(['call' => 1])
        ->and($payload1['result']['second'])->toBe($payload1['result']['first'])
        ->and($payload2)->toBe($payload1)
        ->and($envelope2)->toBe($envelope1)
        ->and($envelope1['payload'])->toBe($payload1)
        ->and($envelope1['payload_hash'])->toBe(app(TalktoPayloadHasher::class)->hash($payload1))
        ->and($shared->calls)->toBe(1);
});

test('explicit callback snapshot arrayable instances are validated frozen and memoized once', function (): void {
    $shared = new P3CountingArrayable(['arrayable' => true]);

    $callback = p3CallbackDataWithSnapshot([
        'meta' => [
            'first' => $shared,
            'second' => $shared,
        ],
    ]);

    $payload = $callback->toPayload();
    $envelope = $callback->toEnvelope();

    p3AssertPrimitiveTree($payload);

    expect($shared->calls)->toBe(1)
        ->and($payload['meta']['first'])->toBe(['arrayable' => true])
        ->and($payload['meta']['second'])->toBe($payload['meta']['first'])
        ->and($envelope['payload'])->toBe($payload)
        ->and($callback->toPayload())->toBe($payload)
        ->and($callback->toEnvelope())->toBe($envelope)
        ->and($shared->calls)->toBe(1);
});

test('explicit callback snapshot unsupported values fail immediately without persistence or dispatch', function (): void {
    Queue::fake();

    $cases = [
        'resource' => [
            'payload' => fn (): mixed => fopen('php://temp', 'r'),
            'error' => 'payload_resource',
        ],
        'closure' => [
            'payload' => fn (): mixed => fn (): string => 'nope',
            'error' => 'payload_closure',
        ],
        'generator' => [
            'payload' => fn (): mixed => p3GeneratorPayload(),
            'error' => 'payload_generator',
        ],
        'array-object' => [
            'payload' => fn (): mixed => new ArrayObject(['important' => 'value']),
            'error' => 'payload_traversable_object',
        ],
        'native-date' => [
            'payload' => fn (): mixed => new DateTimeImmutable('2026-01-02 03:04:05.987654', new DateTimeZone('UTC')),
            'error' => 'payload_datetime_unsupported',
        ],
    ];

    foreach ($cases as $name => $case) {
        $value = $case['payload']();

        try {
            p3CallbackDataWithSnapshot([
                'result' => [
                    'bad' => $value,
                ],
            ]);

            $this->fail('Expected explicit callback snapshot rejection for '.$name.'.');
        } catch (TalktoUnsupportedPayloadValueException $exception) {
            expect($exception)->toBeInstanceOf(InvalidArgumentException::class)
                ->and($exception->payloadErrorCode)->toBe($case['error']);
        } finally {
            if (is_resource($value)) {
                fclose($value);
            }
        }

        expect(TalktoMessage::query()->count())->toBe(0)
            ->and(TalktoEvent::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    }
});

test('explicit callback snapshot circular references are rejected safely', function (): void {
    expect(fn () => p3CallbackDataWithSnapshot([
        'result' => [
            'node' => p3CircularObject(),
        ],
    ]))->toThrow(TalktoUnsupportedPayloadValueException::class);

    expect(TalktoMessage::query()->count())->toBe(0)
        ->and(TalktoEvent::query()->count())->toBe(0);
});

test('callback data from envelope preserves primitive values and stable repeated output', function (): void {
    $payload = [
        'original_message_id' => 'original-from-envelope',
        'original_command' => 'catalog.sync',
        'status' => 'succeeded',
        'succeeded' => true,
        'retryable' => false,
        'skipped' => false,
        'error_class' => null,
        'error_message' => '',
        'result' => [
            'integer' => 7,
            'float' => 7.25,
            'numeric_string' => '007',
            'trailing_space' => 'keep ',
            'empty_string' => '',
        ],
        'meta' => [
            'zero' => 0,
            'zero_string' => '0',
            'space' => ' ',
        ],
    ];
    $envelope = [
        'message_id' => 'cb-from-envelope',
        'correlation_id' => 'corr-from-envelope',
        'parent_message_id' => 'original-from-envelope',
        'source' => 'inventory',
        'target' => 'website',
        'command' => 'talkto.result',
        'business_key' => 'biz-from-envelope',
        'idempotency_key' => 'idem-from-envelope',
        'created_at' => '2026-01-02T03:04:05+00:00',
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'payload' => $payload,
    ];

    $callback = TalktoResultCallbackData::fromEnvelope($envelope);
    $payload1 = $callback->toPayload();
    $payload2 = $callback->toPayload();
    $envelope1 = $callback->toEnvelope();
    $envelope2 = $callback->toEnvelope();

    p3AssertPrimitiveTree($payload1);

    expect($payload1)->toBe($payload)
        ->and($payload2)->toBe($payload1)
        ->and($envelope2)->toBe($envelope1)
        ->and($envelope1['payload'])->toBe($payload)
        ->and($envelope1['created_at'])->toBe('2026-01-02T03:04:05+00:00')
        ->and($envelope1['payload_hash'])->toBe(app(TalktoPayloadHasher::class)->hash($payload))
        ->and($payload1['result']['numeric_string'])->toBe('007')
        ->and($payload1['result']['integer'])->toBe(7)
        ->and($payload1['result']['float'])->toBe(7.25)
        ->and($payload1['result']['trailing_space'])->toBe('keep ')
        ->and($payload1['result']['empty_string'])->toBe('');
});

test('explicit primitive callback snapshot keeps hash and json compatibility', function (): void {
    $payload = [
        'original_message_id' => 'primitive-callback-original',
        'original_command' => 'catalog.sync',
        'status' => 'succeeded',
        'succeeded' => true,
        'retryable' => false,
        'skipped' => false,
        'error_class' => null,
        'error_message' => '',
        'result' => [
            'sku' => 'PRIM-CB-1',
            'quantity' => 2,
            'price' => 79.95,
            'numeric_string' => '0002',
        ],
        'meta' => [
            'note' => 'ready ',
            'empty' => '',
        ],
    ];
    $expectedHash = app(TalktoPayloadHasher::class)->hash($payload);
    $expectedJson = app(TalktoJsonEncoder::class)->encode($payload);

    $callback = p3CallbackDataWithSnapshot($payload);

    expect($callback->toPayload())->toBe($payload)
        ->and(app(TalktoPayloadHasher::class)->hash($callback->toPayload()))->toBe($expectedHash)
        ->and(app(TalktoJsonEncoder::class)->encode($callback->toPayload()))->toBe($expectedJson)
        ->and($callback->toEnvelope()['payload_hash'])->toBe($expectedHash);
});

test('retry dead letter and repair paths reuse the persisted frozen payload', function (): void {
    $payload = new P3CountingJsonSerializable(['retry' => 'payload']);
    $message = app(TalktoOutgoingMessageFactory::class)->create('website', 'catalog.sync', $payload, [
        'message_id' => 'freeze-retry-dlq',
        'max_attempts' => 1,
    ]);
    $frozen = $message->fresh()->payload;

    Http::fake(fn () => Http::response(['received' => false], 500));

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();
    $deadLetter = TalktoDeadLetter::query()->where('message_id', 'freeze-retry-dlq')->sole();

    expect($payload->calls)->toBe(1)
        ->and($message->payload)->toBe($frozen)
        ->and($deadLetter->payload)->toBe($frozen)
        ->and($message->overall_status)->toBe('failed_final');

    $message->forceFill([
        'payload_hash' => str_repeat('0', 64),
        'last_response' => 'payload_hash_mismatch',
    ])->save();

    expect(Artisan::call('talkto:repair-payload-hash', [
        'message_id' => 'freeze-retry-dlq',
        '--confirm' => true,
        '--reason' => 'phase 3 frozen payload repair',
    ]))->toBe(0);

    expect($payload->calls)->toBe(1)
        ->and($message->fresh()->payload)->toBe($frozen)
        ->and($message->fresh()->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($frozen));

    Queue::fake();
    expect(Artisan::call('talkto:dlq-reprocess', ['--message-id' => 'freeze-retry-dlq', '--force' => true]))->toBe(0);
    Queue::assertPushed(SendTalktoMessage::class, 1);
});

test('freezing works with configured custom message models and a separate talkto connection', function (): void {
    p3ConfigureAndMigrateConnection('phase3_talkto');

    config([
        'talkto.database.connection' => 'phase3_talkto',
        'talkto.models.message' => P3CustomTalktoMessage::class,
    ]);

    $payload = new P3CountingJsonSerializable(['connection' => 'phase3']);

    $message = app(TalktoOutgoingMessageFactory::class)->create('website', 'catalog.sync', $payload, [
        'message_id' => 'freeze-separate-connection',
    ]);

    expect($message)->toBeInstanceOf(P3CustomTalktoMessage::class)
        ->and($message->getConnection()->getName())->toBe('phase3_talkto')
        ->and($payload->calls)->toBe(1)
        ->and(DB::connection('phase3_talkto')->table('talkto_messages')->where('message_id', 'freeze-separate-connection')->count())->toBe(1)
        ->and(DB::connection('sqlite')->table('talkto_messages')->where('message_id', 'freeze-separate-connection')->count())->toBe(0)
        ->and($message->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($message->payload));
});

function p3CallbackDataWithSnapshot(array $payload): TalktoResultCallbackData
{
    return new TalktoResultCallbackData(
        'cb-explicit-snapshot',
        'explicit-snapshot-original',
        'catalog.sync',
        'explicit-snapshot-correlation',
        'explicit-snapshot-original',
        'inventory',
        'website',
        'talkto.result',
        null,
        null,
        'succeeded',
        new TalktoIncomingCommandResultData(
            succeeded: true,
            retryable: false,
            skipped: false,
            errorClass: null,
            errorMessage: null,
            result: [],
            meta: [],
        ),
        $payload,
        '2026-01-02T03:04:05+00:00',
    );
}

function p3IncomingMessage(string $messageId): TalktoMessage
{
    $payload = ['original' => $messageId];

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

function p3AssertPrimitiveTree(mixed $value): void
{
    if (is_array($value)) {
        foreach ($value as $item) {
            p3AssertPrimitiveTree($item);
        }

        return;
    }

    expect($value === null || is_bool($value) || is_int($value) || is_string($value) || is_float($value))->toBeTrue();

    if (is_float($value)) {
        expect(is_finite($value))->toBeTrue();
    }
}

function p3GeneratorPayload(): Generator
{
    yield 'first';
}

function p3CircularObject(): stdClass
{
    $node = new stdClass;
    $node->self = $node;

    return $node;
}

function p3CircularArray(): array
{
    $node = [];
    $node['self'] = &$node;

    return $node;
}

function p3ExcessivelyNestedPayload(): array
{
    $payload = ['leaf' => true];

    for ($i = 0; $i < 520; $i++) {
        $payload = ['child' => $payload];
    }

    return $payload;
}

function p3ConfigureAndMigrateConnection(string $connection): void
{
    config([
        "database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($connection);

    $previousConnection = config('talkto.database.connection');

    try {
        config(['talkto.database.connection' => $connection]);

        foreach (p3MigrationInstances() as $migration) {
            $migration->up();
        }
    } finally {
        config(['talkto.database.connection' => $previousConnection]);
    }
}

function p3MigrationInstances(): array
{
    return [
        include __DIR__.'/../../database/migrations/2026_06_13_000001_create_talkto_messages_table.php',
        include __DIR__.'/../../database/migrations/2026_06_13_000002_create_talkto_attempts_table.php',
        include __DIR__.'/../../database/migrations/2026_06_13_000003_create_talkto_events_table.php',
        include __DIR__.'/../../database/migrations/2026_06_19_000002_create_talkto_dead_letters_table.php',
        include __DIR__.'/../../database/migrations/2026_06_20_000001_create_talkto_nonces_table.php',
    ];
}

class P3CountingJsonSerializable implements JsonSerializable
{
    public int $calls = 0;

    public function __construct(public mixed $payload) {}

    public function jsonSerialize(): mixed
    {
        $this->calls++;

        return $this->payload instanceof Closure
            ? ($this->payload)($this->calls)
            : $this->payload;
    }
}

class P3CountingArrayable implements Arrayable
{
    public int $calls = 0;

    public function __construct(public array $payload) {}

    public function toArray(): array
    {
        $this->calls++;

        return $this->payload;
    }
}

class P3PublicPayloadObject
{
    public function __construct(
        public string $name,
        public P3CountingJsonSerializable $child,
    ) {}
}

class P3PrivateOnlyPayloadObject
{
    public function __construct(private readonly string $secret) {}
}

class P3InvokablePayload
{
    public function __construct(public array $payload) {}

    public function __invoke(): array
    {
        return $this->payload;
    }
}

class P3InvokableJsonSerializable implements JsonSerializable
{
    public int $calls = 0;

    public function __construct(public mixed $payload) {}

    public function __invoke(): mixed
    {
        return $this->payload;
    }

    public function jsonSerialize(): mixed
    {
        $this->calls++;

        return $this->payload;
    }
}

class P3IteratorAggregatePayload implements IteratorAggregate
{
    public function __construct(private readonly array $payload) {}

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->payload);
    }
}

class P3CountingEloquentPayload extends Model
{
    public static int $relationCalls = 0;

    public int $toArrayCalls = 0;

    protected $guarded = [];

    protected $casts = [
        'stock' => 'integer',
        'active' => 'boolean',
    ];

    protected $hidden = [
        'secret',
    ];

    protected $visible = [
        'sku',
        'stock',
        'active',
        'label',
    ];

    protected $appends = [
        'label',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct();

        $this->setRawAttributes($attributes, true);
        $this->exists = true;
    }

    public function toArray(): array
    {
        $this->toArrayCalls++;

        return parent::toArray();
    }

    public function getLabelAttribute(): string
    {
        return ($this->attributes['sku'] ?? 'unknown').'-label';
    }

    public function lazyRelation()
    {
        self::$relationCalls++;

        return $this->hasMany(self::class, 'parent_id');
    }
}

class P3StringableSecret implements Stringable
{
    public function __construct(private readonly string $secret) {}

    public function __toString(): string
    {
        return $this->secret;
    }
}

class P3CustomTalktoMessage extends TalktoMessage {}

class P3FailingTalktoEvent extends TalktoEvent
{
    public static bool $failOnCreate = false;

    protected static function booted(): void
    {
        static::creating(function (): void {
            if (self::$failOnCreate) {
                throw new RuntimeException('Phase 3 event create failure.');
            }
        });
    }
}

enum P3BackedFreezingStatus: string
{
    case Ready = 'ready';
}

enum P3PureFreezingStatus
{
    case Ready;
}
