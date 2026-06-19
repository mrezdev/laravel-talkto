<?php

use Mrezdev\LaravelTalkto\Contracts\CommandHandlerContract;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Contracts\SourceActionContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoSignatureException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoCommandNotAllowedException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoIdempotencyException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoPayloadHashMismatchException;
use Mrezdev\LaravelTalkto\Handlers\NoopIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoFlowBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoFlowFactory;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResolver;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

test('package config is merged', function (): void {
    expect(config('talkto'))->toBeArray()
        ->and(config('talkto.service'))->toBe('testing')
        ->and(config('talkto.security.algorithm'))->toBe('sha256')
        ->and(config('talkto.routes.enabled'))->toBeFalse()
        ->and(config('talkto.migrations.enabled'))->toBeFalse()
        ->and(config('talkto.routes.receive_uri'))->toBe('talkto/receive');
});

test('package receive route is disabled by default', function (): void {
    $routeName = config('talkto.routes.receive_name', 'talkto.receive');
    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->toBeNull();
});

test('package service classes resolve from the container', function (): void {
    expect(app(TalktoPayloadHasher::class))->toBeInstanceOf(TalktoPayloadHasher::class)
        ->and(app(TalktoSigner::class))->toBeInstanceOf(TalktoSigner::class)
        ->and(app(TalktoSignatureVerifier::class))->toBeInstanceOf(TalktoSignatureVerifier::class)
        ->and(app(TalktoOutgoingMessageFactory::class))->toBeInstanceOf(TalktoOutgoingMessageFactory::class)
        ->and(app(TalktoOutgoingEnvelopeBuilder::class))->toBeInstanceOf(TalktoOutgoingEnvelopeBuilder::class)
        ->and(app(TalktoFlowFactory::class))->toBeInstanceOf(TalktoFlowFactory::class)
        ->and(app(TalktoIncomingCommandResolver::class))->toBeInstanceOf(TalktoIncomingCommandResolver::class)
        ->and(app(NoopIncomingCommandHandler::class))->toBeInstanceOf(NoopIncomingCommandHandler::class)
        ->and(app(NoopIncomingCommandHandler::class))->toBeInstanceOf(TalktoIncomingCommandHandler::class)
        ->and(app(NoopIncomingCommandHandler::class))->toBeInstanceOf(CommandHandlerContract::class)
        ->and(app(TalktoFlowFactory::class)->flow('smoke'))->toBeInstanceOf(TalktoFlowBuilder::class);
});

test('package job classes are autoloadable', function (): void {
    expect(class_exists(SendTalktoMessage::class))->toBeTrue()
        ->and(class_exists(ProcessIncomingTalktoMessage::class))->toBeTrue();
});

test('public contracts and exceptions are autoloadable', function (): void {
    expect(interface_exists(CommandHandlerContract::class))->toBeTrue()
        ->and(interface_exists(IncomingCommandResultContract::class))->toBeTrue()
        ->and(interface_exists(SourceActionContract::class))->toBeTrue()
        ->and(interface_exists(ResultCallbackSenderContract::class))->toBeTrue()
        ->and(interface_exists(ResultCallbackReceiverContract::class))->toBeTrue()
        ->and(is_subclass_of(InvalidTalktoSignatureException::class, TalktoException::class))->toBeTrue()
        ->and(is_subclass_of(TalktoCommandNotAllowedException::class, TalktoException::class))->toBeTrue()
        ->and(is_subclass_of(TalktoPayloadHashMismatchException::class, TalktoException::class))->toBeTrue()
        ->and(is_subclass_of(TalktoIdempotencyException::class, TalktoException::class))->toBeTrue();
});

test('package model classes have expected table names', function (): void {
    expect((new TalktoMessage)->getTable())->toBe('talkto_messages')
        ->and((new TalktoAttempt)->getTable())->toBe('talkto_attempts')
        ->and((new TalktoEvent)->getTable())->toBe('talkto_events');
});

test('outgoing factory honors configured model classes', function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.models.message' => PackageSmokeHostTalktoMessage::class,
        'talkto.models.attempt' => PackageSmokeHostTalktoAttempt::class,
        'talkto.models.event' => PackageSmokeHostTalktoEvent::class,
        'talkto.outgoing.peer' => [
            'url' => 'https://peer.test',
            'secret' => 'secret',
            'endpoint' => '/api/talkto/receive',
            'mode' => 'reliable',
        ],
    ]);

    $message = app(TalktoOutgoingMessageFactory::class)->create(
        target: 'peer',
        command: 'domain.command',
        payload: ['item' => 'item-1'],
    );

    $event = PackageSmokeHostTalktoEvent::query()
        ->where('message_id', $message->message_id)
        ->first();

    expect($message)->toBeInstanceOf(PackageSmokeHostTalktoMessage::class)
        ->and($event)->toBeInstanceOf(PackageSmokeHostTalktoEvent::class)
        ->and($message->events()->first())->toBeInstanceOf(PackageSmokeHostTalktoEvent::class)
        ->and($event->message()->first())->toBeInstanceOf(PackageSmokeHostTalktoMessage::class);
});

test('package migrations are loadable and create Talkto tables', function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0)
        ->and(Schema::hasTable('talkto_messages'))->toBeTrue()
        ->and(Schema::hasTable('talkto_attempts'))->toBeTrue()
        ->and(Schema::hasTable('talkto_events'))->toBeTrue();
});

test('basic payload hashing is deterministic for object key order', function (): void {
    $hasher = app(TalktoPayloadHasher::class);

    expect($hasher->hash(['b' => 2, 'a' => 1]))
        ->toBe($hasher->hash(['a' => 1, 'b' => 2]));
});

test('basic signing verifies canonical messages', function (): void {
    $signer = app(TalktoSigner::class);
    $payloadHash = app(TalktoPayloadHasher::class)->hash(['status' => 'ok']);
    $arguments = [
        'message-1',
        '2026-01-01T00:00:00+00:00',
        'source',
        'target',
        'smoke.command',
        $payloadHash,
        'secret',
    ];

    $signature = $signer->sign(...$arguments);

    expect($signer->verify($signature, ...$arguments))->toBeTrue()
        ->and($signer->verify('invalid-signature', ...$arguments))->toBeFalse();
});

test('outgoing envelope builder accepts compatible eloquent models', function (): void {
    config([
        'talkto.outgoing.peer.secret' => 'secret',
        'talkto.outgoing.peer.url' => 'https://peer.test',
        'talkto.outgoing.peer.endpoint' => '/api/talkto/receive',
    ]);

    $message = new class extends Model
    {
        public $timestamps = false;

        protected $guarded = [];
    };

    $message->forceFill([
        'message_id' => 'message-1',
        'correlation_id' => 'correlation-1',
        'parent_message_id' => null,
        'source_service' => 'source-service',
        'target_service' => 'peer',
        'command' => 'domain.command',
        'business_key' => 'order-1',
        'idempotency_key' => 'idempotency-1',
        'schema_version' => 1,
        'created_at' => now(),
        'payload_hash' => app(TalktoPayloadHasher::class)->hash(['item' => 'item-1']),
        'payload' => ['item' => 'item-1'],
    ]);

    $builder = app(TalktoOutgoingEnvelopeBuilder::class);
    $envelope = $builder->buildEnvelope($message);
    $headers = $builder->buildHeaders($message, '2026-01-01T00:00:00+00:00');
    $built = $builder->build($message);

    expect($envelope['message_id'])->toBe('message-1')
        ->and($headers)->toHaveKey('X-Talkto-Signature')
        ->and($built['envelope']['target'])->toBe('peer')
        ->and($builder->endpointFor($message))->toBe('https://peer.test/api/talkto/receive');
});

test('incoming command result constructors set outcome flags', function (): void {
    $succeeded = TalktoIncomingCommandResult::succeeded(['ok' => true]);
    $retryable = TalktoIncomingCommandResult::failedRetryable('Temporary failure', RuntimeException::class);
    $final = TalktoIncomingCommandResult::failedFinal('Final failure', LogicException::class);

    expect($succeeded)->toBeInstanceOf(IncomingCommandResultContract::class)
        ->and($succeeded->succeeded)->toBeTrue()
        ->and($succeeded->retryable)->toBeFalse()
        ->and($retryable->succeeded)->toBeFalse()
        ->and($retryable->retryable)->toBeTrue()
        ->and($final->succeeded)->toBeFalse()
        ->and($final->retryable)->toBeFalse();
});

class PackageSmokeHostTalktoMessage extends TalktoMessage
{
}

class PackageSmokeHostTalktoAttempt extends TalktoAttempt
{
}

class PackageSmokeHostTalktoEvent extends TalktoEvent
{
}
