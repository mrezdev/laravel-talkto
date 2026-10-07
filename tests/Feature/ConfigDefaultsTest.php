<?php

test('routes and migrations are disabled by default', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';

    expect($defaults['routes']['enabled'])->toBeFalse()
        ->and($defaults['migrations']['enabled'])->toBeFalse()
        ->and($defaults['panel']['enabled'])->toBeFalse()
        ->and(config('talkto.routes.enabled'))->toBeFalse()
        ->and(config('talkto.migrations.enabled'))->toBeFalse()
        ->and(config('talkto.panel.enabled'))->toBeFalse();
});

test('dead letter table config has a canonical database table path', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';

    expect($defaults['database']['tables']['dead_letters'])->toBe('talkto_dead_letters')
        ->and($defaults['database']['tables']['nonces'])->toBe('talkto_nonces')
        ->and($defaults['database']['tables'])->toMatchArray([
            'messages' => 'talkto_messages',
            'attempts' => 'talkto_attempts',
            'events' => 'talkto_events',
        ])
        ->and($defaults['database']['connection'])->toBeNull()
        ->and($defaults['dead_letter'])->not->toHaveKey('table');
});

test('default security config uses v2 only with required nonces', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';

    expect($defaults['security']['signature_version'])->toBe('v2')
        ->and($defaults['security']['accept_versions'])->toBe(['v2'])
        ->and($defaults['security']['require_signature'])->toBeTrue()
        ->and($defaults['security']['require_timestamp'])->toBeTrue()
        ->and($defaults['security']['timestamp_tolerance_seconds'])->toBe(300)
        ->and($defaults['security']['algorithm'])->toBe('sha256')
        ->and($defaults['security']['nonce_header'])->toBe('X-Talkto-Nonce')
        ->and($defaults['security']['signature_version_header'])->toBe('X-Talkto-Signature-Version')
        ->and($defaults['security']['replay_protection']['enabled'])->toBeTrue()
        ->and($defaults['security']['replay_protection']['use_message_id'])->toBeTrue()
        ->and($defaults['security']['replay_protection']['require_nonce_for_v2'])->toBeTrue()
        ->and($defaults['retention']['nonces_days'])->toBe(7);
});

test('retry and dead letter defaults preserve the production scheduling policy', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';

    expect($defaults['retry'])->toMatchArray([
        'enabled' => true,
        'outgoing_enabled' => true,
        'incoming_enabled' => false,
        'max_attempts' => 5,
        'backoff_seconds' => [10, 30, 60, 120, 300],
        'retryable_statuses' => ['failed_retryable'],
        'final_failure_status' => 'failed_final',
        'retryable_http_statuses' => [408, 425, 429],
        'retry_server_errors' => true,
        'jitter_seconds' => 0,
    ])->and($defaults['dead_letter'])->toMatchArray([
        'enabled' => true,
        'auto_store_on_final_failure' => true,
        'allow_reprocess' => true,
        'max_reprocess_attempts' => 3,
    ]);
});

test('transport callback route and panel defaults remain compatible', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';

    expect($defaults['http'])->toMatchArray(['timeout_seconds' => 20, 'verify_ssl' => true, 'ca_bundle' => null])
        ->and($defaults['callbacks'])->toMatchArray([
            'enabled' => true,
            'auto_dispatch' => true,
            'command' => 'talkto.result',
            'endpoint' => '/api/talkto/callback',
            'timeout_seconds' => 20,
        ])->and($defaults['routes'])->toMatchArray([
            'prefix' => 'api',
            'middleware' => ['api', 'throttle:talkto'],
            'receive_uri' => 'talkto/receive',
            'receive_name' => 'talkto.receive',
            'callback_uri' => 'talkto/callback',
            'callback_name' => 'talkto.callback',
        ])->and($defaults['panel']['route'])->toMatchArray([
            'prefix' => 'talkto',
            'domain' => null,
            'middleware' => ['web', 'auth'],
            'name' => 'talkto.panel.',
        ])->and($defaults['panel']['authorization'])->toMatchArray(['enabled' => true, 'gate' => 'viewTalktoPanel']);
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
        ->and($defaults['models']['nonce'])->toStartWith('Mrezdev\\LaravelTalkto\\')
        ->and($defaults['jobs']['send_message'])->toStartWith('Mrezdev\\LaravelTalkto\\')
        ->and($defaults['jobs']['process_incoming'])->toStartWith('Mrezdev\\LaravelTalkto\\');
});
