<?php

test('routes and migrations are disabled by default', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';

    expect($defaults['routes']['enabled'])->toBeFalse()
        ->and($defaults['migrations']['enabled'])->toBeFalse()
        ->and(config('talkto.routes.enabled'))->toBeFalse()
        ->and(config('talkto.migrations.enabled'))->toBeFalse();
});

test('default config has no production urls or shared secrets', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';
    $values = new RecursiveIteratorIterator(new RecursiveArrayIterator($defaults));
    $matches = [];

    foreach ($values as $value) {
        if (! is_string($value)) {
            continue;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $matches[] = $value;
        }

        if (str_contains(strtolower($value), 'secret')) {
            $matches[] = $value;
        }
    }

    expect($defaults['incoming']['handlers'])->toBe([])
        ->and($defaults['incoming']['unknown_command_strategy'])->toBe('fail')
        ->and($defaults['outgoing'])->toBe([])
        ->and($matches)->toBe([]);
});

test('default config uses generic package classes only', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';

    expect($defaults['models']['message'])->toStartWith('Ibake\\TalktoReliable\\')
        ->and($defaults['models']['attempt'])->toStartWith('Ibake\\TalktoReliable\\')
        ->and($defaults['models']['event'])->toStartWith('Ibake\\TalktoReliable\\')
        ->and($defaults['jobs']['send_message'])->toStartWith('Ibake\\TalktoReliable\\')
        ->and($defaults['jobs']['process_incoming'])->toStartWith('Ibake\\TalktoReliable\\');
});
