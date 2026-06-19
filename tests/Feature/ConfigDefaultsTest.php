<?php

test('routes and migrations are disabled by default', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';

    expect($defaults['routes']['enabled'])->toBeFalse()
        ->and($defaults['migrations']['enabled'])->toBeFalse()
        ->and(config('talkto.routes.enabled'))->toBeFalse()
        ->and(config('talkto.migrations.enabled'))->toBeFalse();
});

test('dead letter table config has a canonical database table path', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';

    expect($defaults['database']['tables']['dead_letters'])->toBe('talkto_dead_letters')
        ->and($defaults['dead_letter'])->not->toHaveKey('table');
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

    expect($defaults['models']['message'])->toStartWith('Mrezdev\\LaravelTalkto\\')
        ->and($defaults['models']['attempt'])->toStartWith('Mrezdev\\LaravelTalkto\\')
        ->and($defaults['models']['event'])->toStartWith('Mrezdev\\LaravelTalkto\\')
        ->and($defaults['jobs']['send_message'])->toStartWith('Mrezdev\\LaravelTalkto\\')
        ->and($defaults['jobs']['process_incoming'])->toStartWith('Mrezdev\\LaravelTalkto\\');
});
