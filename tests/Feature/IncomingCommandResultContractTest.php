<?php

use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;

test('incoming command result implements the public result contract', function (): void {
    $result = TalktoIncomingCommandResult::succeeded(['processed' => true], ['attempt' => 1]);

    expect($result)->toBeInstanceOf(IncomingCommandResultContract::class);
});

test('incoming command result accessors mirror value properties', function (): void {
    $result = TalktoIncomingCommandResult::failedRetryable(
        'Temporary failure.',
        RuntimeException::class,
        ['code' => 'temporary_failure'],
    );

    expect($result->isSucceeded())->toBe($result->succeeded)
        ->and($result->isRetryable())->toBe($result->retryable)
        ->and($result->isSkipped())->toBe($result->skipped)
        ->and($result->errorClass())->toBe($result->errorClass)
        ->and($result->errorMessage())->toBe($result->errorMessage)
        ->and($result->result())->toBe($result->result)
        ->and($result->meta())->toBe($result->meta);
});

test('incoming command result convenience helpers expose stable values', function (): void {
    $result = TalktoIncomingCommandResult::succeeded(['processed' => true], ['attempt' => 1]);

    expect($result->ok())->toBeTrue()
        ->and($result->toArray())->toBe([
            'succeeded' => true,
            'retryable' => false,
            'error_class' => null,
            'error_message' => null,
            'result' => ['processed' => true],
            'meta' => ['attempt' => 1],
            'skipped' => false,
        ]);
});

test('skipped result is successful and marked skipped', function (): void {
    $result = TalktoIncomingCommandResult::skipped('already processed');

    expect($result->isSucceeded())->toBeTrue()
        ->and($result->isRetryable())->toBeFalse()
        ->and($result->isSkipped())->toBeTrue()
        ->and($result->meta())->toBe(['reason' => 'already processed']);
});
