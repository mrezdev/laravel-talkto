<?php

use Illuminate\Http\Request;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoRequestJsonException;
use Mrezdev\LaravelTalkto\Services\TalktoJsonEncoder;
use Mrezdev\LaravelTalkto\Services\TalktoSignedRequestDecoder;

test('decoder reads raw JSON objects and preserves JSON values exactly', function (): void {
    $raw = app(TalktoJsonEncoder::class)->encode([
        'message_id' => 'decoder-preserves-values',
        'payload' => [
            'leading_and_trailing' => ' value ',
            'empty_string' => '',
            'unicode' => 'سلام / hello ',
            'integer' => 42,
            'float' => 79.95,
            'boolean' => true,
            'null' => null,
            'list' => [' a ', '', null],
            'object' => [
                'nested_empty' => '',
            ],
        ],
    ]);

    $decoded = app(TalktoSignedRequestDecoder::class)->decode(rawDecoderRequest($raw));

    expect($decoded['payload']['leading_and_trailing'])->toBe(' value ')
        ->and($decoded['payload']['empty_string'])->toBe('')
        ->and($decoded['payload']['unicode'])->toBe('سلام / hello ')
        ->and($decoded['payload']['integer'])->toBe(42)
        ->and($decoded['payload']['float'])->toBe(79.95)
        ->and($decoded['payload']['boolean'])->toBeTrue()
        ->and($decoded['payload']['null'])->toBeNull()
        ->and($decoded['payload']['list'])->toBe([' a ', '', null])
        ->and($decoded['payload']['object']['nested_empty'])->toBe('');
});

test('decoder recognizes JSON content type variants', function (string $contentType): void {
    $decoded = app(TalktoSignedRequestDecoder::class)->decode(rawDecoderRequest('{"message_id":"json-type"}', $contentType));

    expect($decoded)->toBe(['message_id' => 'json-type']);
})->with([
    'application/json',
    'application/json; charset=UTF-8',
    'application/vnd.talkto+json',
]);

test('decoder rejects invalid JSON bodies without falling back to parsed input', function (string $raw): void {
    $request = rawDecoderRequest($raw);
    $request->request->replace(['message_id' => 'fallback-should-not-win']);

    expect(fn () => app(TalktoSignedRequestDecoder::class)->decode($request))
        ->toThrow(InvalidTalktoRequestJsonException::class, 'invalid_json');
})->with([
    'malformed' => ['{"message_id":'],
    'empty' => [''],
    'whitespace' => [" \n\t "],
    'invalid utf8' => ["{\"message_id\":\"\xB1\"}"],
    'too deeply nested' => [str_repeat('{"a":', 513).'null'.str_repeat('}', 513)],
]);

test('decoder rejects non object JSON roots', function (string $raw): void {
    expect(fn () => app(TalktoSignedRequestDecoder::class)->decode(rawDecoderRequest($raw)))
        ->toThrow(InvalidTalktoRequestJsonException::class, 'invalid_json');
})->with([
    'list' => ['[]'],
    'string' => ['"message"'],
    'number' => ['123'],
    'boolean' => ['true'],
    'null' => ['null'],
]);

test('decoder keeps parsed input compatibility for clearly non JSON requests', function (): void {
    $request = Request::create('/api/talkto/receive', 'POST', [
        'message_id' => 'legacy-form',
        'payload' => [
            'empty' => '',
        ],
    ]);

    $decoded = app(TalktoSignedRequestDecoder::class)->decode($request);

    expect($decoded)->toBe([
        'message_id' => 'legacy-form',
        'payload' => [
            'empty' => '',
        ],
    ]);
});

function rawDecoderRequest(string $raw, string $contentType = 'application/json'): Request
{
    return Request::create(
        '/api/talkto/receive',
        'POST',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => $contentType,
            'HTTP_ACCEPT' => 'application/json',
        ],
        $raw
    );
}
