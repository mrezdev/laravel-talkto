<?php

use Mrezdev\LaravelTalkto\Exceptions\TalktoJsonEncodingException;
use Mrezdev\LaravelTalkto\Services\TalktoJsonEncoder;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;

test('encoder produces the same json under supported serialize precision values', function (): void {
    $encoder = new TalktoJsonEncoder;
    $outputs = [];
    $originalPrecision = ini_get('serialize_precision');

    try {
        foreach (['-1', '14', '17', '53'] as $precision) {
            ini_set('serialize_precision', $precision);
            $outputs[$precision] = $encoder->encode(encoderRepresentativePayload());
        }
    } finally {
        ini_set('serialize_precision', (string) $originalPrecision);
    }

    expect(array_unique($outputs))->toHaveCount(1)
        ->and($outputs['-1'])->toContain('"stock":79.95')
        ->and($outputs['-1'])->toContain('"available":77.95')
        ->and($outputs['-1'])->toContain('"city":"تهران"')
        ->and($outputs['-1'])->toContain('"url":"https://example.test/a/b"');

    $decoded = json_decode($outputs['-1'], true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['items'][0]['stock'])->toBeFloat()
        ->and($decoded['items'][0]['available'])->toBeFloat()
        ->and($decoded['active'])->toBeTrue()
        ->and($decoded['count'])->toBe(31)
        ->and($decoded['nothing'])->toBeNull();
});

test('canonical encoding sorts associative keys recursively but preserves list order', function (): void {
    $json = (new TalktoJsonEncoder)->encodeCanonical([
        'b' => 1,
        'list' => [
            ['second' => 2, 'first' => 1],
            ['third' => 3],
        ],
        'a' => [
            'z' => 2,
            'y' => 1,
        ],
    ]);

    expect($json)->toBe('{"a":{"y":1,"z":2},"b":1,"list":[{"first":1,"second":2},{"third":3}]}');
});

test('encoder supports json serializable values and ordinary objects', function (): void {
    $jsonSerializable = new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return ['b' => 2, 'a' => 1];
        }
    };

    $ordinary = new class
    {
        public string $name = 'object';

        public float $stock = 79.95;
    };

    $encoded = (new TalktoJsonEncoder)->encodeCanonical([
        'json' => $jsonSerializable,
        'object' => $ordinary,
    ]);

    expect($encoded)->toBe('{"json":{"a":1,"b":2},"object":{"name":"object","stock":79.95}}');
});

test('encoder rejects non finite floats', function (float $value): void {
    expect(fn () => (new TalktoJsonEncoder)->encode(['value' => $value]))
        ->toThrow(TalktoJsonEncodingException::class, 'non-finite');
})->with([
    'nan' => [NAN],
    'inf' => [INF],
    '-inf' => [-INF],
]);

test('encoder restores serialize precision after success and exception', function (): void {
    $encoder = new TalktoJsonEncoder;
    $originalPrecision = ini_get('serialize_precision');
    $resource = fopen('php://memory', 'r');

    try {
        ini_set('serialize_precision', '17');
        $encoder->encode(['stock' => 79.95]);
        expect(ini_get('serialize_precision'))->toBe('17');

        ini_set('serialize_precision', '14');

        try {
            $encoder->encode(['resource' => $resource]);
            expect()->fail('Expected JSON encoding exception.');
        } catch (TalktoJsonEncodingException) {
            expect(ini_get('serialize_precision'))->toBe('14');
        }
    } finally {
        if (is_resource($resource)) {
            fclose($resource);
        }

        ini_set('serialize_precision', (string) $originalPrecision);
    }
});

test('payload hasher is precision stable and keeps known float fixture hash', function (): void {
    $hasher = new TalktoPayloadHasher;
    $hashes = [];
    $originalPrecision = ini_get('serialize_precision');

    try {
        foreach (['-1', '14', '17', '53'] as $precision) {
            ini_set('serialize_precision', $precision);
            $hashes[$precision] = $hasher->hash(encoderRepresentativePayload());
        }
    } finally {
        ini_set('serialize_precision', (string) $originalPrecision);
    }

    expect(array_unique($hashes))->toHaveCount(1)
        ->and($hashes['-1'])->toBe('7488376ddd87433e87c90b2b605a0fd27834ba6a6d76953bb0ae38475b5cb444')
        ->and($hasher->hash(['status' => 'ready', 'amount' => 10]))
        ->toBe(hash('sha256', '{"amount":10,"status":"ready"}'));
});

test('payload hasher changes when numeric or nested values change', function (): void {
    $hasher = new TalktoPayloadHasher;
    $payload = encoderRepresentativePayload();

    $changedNumeric = $payload;
    $changedNumeric['items'][0]['stock'] = 79.96;

    $changedNested = $payload;
    $changedNested['items'][0]['entity_type'] = 'material';

    expect($hasher->hash($payload))->not->toBe($hasher->hash($changedNumeric))
        ->and($hasher->hash($payload))->not->toBe($hasher->hash($changedNested));
});

function encoderRepresentativePayload(): array
{
    return [
        'schema_version' => 1,
        'source_service' => 'inventory',
        'target_service' => 'website',
        'command' => 'webhook:update-stock',
        'unicode' => [
            'city' => 'تهران',
            'url' => 'https://example.test/a/b',
        ],
        'active' => true,
        'count' => 31,
        'nothing' => null,
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
