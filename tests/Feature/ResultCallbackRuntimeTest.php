<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoResultCallbackController;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoNonce;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackReceiver;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackSender;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.security.require_signature' => true,
        'talkto.security.signature_version' => 'v1',
        'talkto.security.accept_versions' => ['v1'],
        'talkto.service' => 'target-service',
        'talkto.callbacks.enabled' => true,
        'talkto.outgoing.source-service' => [
            'url' => 'https://source.test',
            'secret' => 'shared-callback-secret',
            'callback_endpoint' => '/callbacks/talkto',
        ],
    ]);
});

test('callback sender builds signed envelope and posts to configured callback endpoint', function (): void {
    Http::fake(['https://source.test/callbacks/talkto' => Http::response(['accepted' => true], 200)]);

    $message = p04IncomingMessage('p04-send-ok');
    $result = TalktoIncomingCommandResult::succeeded(['processed' => true]);

    $summary = app(ResultCallbackSenderContract::class)->sendResult($message, $result);

    expect($summary['sent'])->toBeTrue()
        ->and($summary['status'])->toBe('sent')
        ->and($summary['original_message_id'])->toBe('p04-send-ok');

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->url() === 'https://source.test/callbacks/talkto'
            && $request->hasHeader('X-Talkto-Signature')
            && ($payload['command'] ?? null) === 'talkto.result'
            && ($payload['payload']['original_message_id'] ?? null) === 'p04-send-ok'
            && ($payload['payload']['status'] ?? null) === 'succeeded';
    });

    expect(TalktoEvent::query()->where('message_id', 'p04-send-ok')->where('event_type', 'result_callback_sending_started')->exists())->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', 'p04-send-ok')->where('event_type', 'result_callback_sent')->exists())->toBeTrue();
});

test('callback message factory creates outgoing durable callback message from incoming result', function (): void {
    config(['talkto.service' => 'unexpected-config-service']);

    $incoming = p04IncomingMessage('p04-durable-create');
    $result = TalktoIncomingCommandResult::succeeded(['processed' => true], ['attempt' => 1]);

    $callback = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult($incoming, $result);

    expect($callback->direction)->toBe('outgoing')
        ->and($callback->command)->toBe('talkto.result')
        ->and($callback->source_service)->toBe('target-service')
        ->and($callback->target_service)->toBe('source-service')
        ->and($callback->parent_message_id)->toBe('p04-durable-create')
        ->and($callback->correlation_id)->toBe('correlation-p04-durable-create')
        ->and($callback->business_key)->toBe('business-key-123')
        ->and($callback->idempotency_key)->toBe('talkto:callback:p04-durable-create:succeeded')
        ->and($callback->source_action_status)->toBe('succeeded_assumed')
        ->and($callback->transport_status)->toBe('pending')
        ->and($callback->overall_status)->toBe('waiting_to_send')
        ->and((int) $callback->attempts)->toBe(0)
        ->and($callback->message_id)->toBe('cb-'.sha1('p04-durable-create|succeeded'))
        ->and($callback->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($callback->payload))
        ->and($callback->payload)->toMatchArray([
            'original_message_id' => 'p04-durable-create',
            'original_command' => 'domain.command',
            'status' => 'succeeded',
            'succeeded' => true,
            'retryable' => false,
            'skipped' => false,
            'result' => ['processed' => true],
            'meta' => ['attempt' => 1],
        ])
        ->and(TalktoEvent::query()->where('message_id', $callback->message_id)->where('event_type', 'message_created')->exists())->toBeTrue();
});

test('callback message factory reuses duplicate durable callback for same original and status', function (): void {
    $incoming = p04IncomingMessage('p04-durable-idempotent');
    $factory = app(TalktoResultCallbackMessageFactory::class);

    $first = $factory->createForIncomingResult($incoming, TalktoIncomingCommandResult::succeeded(['processed' => true]));
    $second = $factory->createForIncomingResult($incoming, TalktoIncomingCommandResult::succeeded(['processed' => false]));

    expect($second->id)->toBe($first->id)
        ->and(TalktoMessage::query()
            ->where('idempotency_key', 'talkto:callback:p04-durable-idempotent:succeeded')
            ->count())->toBe(1)
        ->and(TalktoMessage::query()
            ->where('parent_message_id', 'p04-durable-idempotent')
            ->where('command', 'talkto.result')
            ->count())->toBe(1);
});

test('callback message factory rejects non incoming messages clearly', function (): void {
    $outgoing = p04OutgoingMessage('p04-durable-reject');

    expect(fn () => app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
        $outgoing,
        TalktoIncomingCommandResult::succeeded()
    ))->toThrow(InvalidArgumentException::class, 'incoming messages');
});

test('callback message factory does not send http or dispatch send job', function (): void {
    Bus::fake();
    Http::fake();

    $incoming = p04IncomingMessage('p04-durable-no-side-effects');

    app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult(
        $incoming,
        TalktoIncomingCommandResult::failedRetryable('Temporary failure.')
    );

    Http::assertNothingSent();
    Bus::assertNotDispatched(SendTalktoMessage::class);
});

test('default v2 callback sender builds nonce and payload hash headers', function (): void {
    p04UseDefaultV2Security();
    Http::fake(['https://source.test/callbacks/talkto' => Http::response(['accepted' => true], 200)]);

    $message = p04IncomingMessage('p04-send-v2-default');

    $summary = app(ResultCallbackSenderContract::class)->sendResult(
        $message,
        TalktoIncomingCommandResult::succeeded(['processed' => true])
    );

    expect($summary['sent'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('X-Talkto-Signature-Version', 'v2')
            && $request->hasHeader('X-Talkto-Nonce')
            && $request->hasHeader('X-Talkto-Payload-Hash');
    });
});

test('callback sender skips without sending when callbacks are disabled', function (): void {
    config(['talkto.callbacks.enabled' => false]);
    Http::fake();

    $message = p04IncomingMessage('p04-send-disabled');
    $summary = app(ResultCallbackSenderContract::class)->sendResult(
        $message,
        TalktoIncomingCommandResult::succeeded(['processed' => true])
    );

    expect($summary)->toMatchArray([
        'sent' => false,
        'status' => 'skipped',
        'original_message_id' => 'p04-send-disabled',
        'error' => 'callbacks_disabled',
    ])->and(TalktoEvent::query()->where('message_id', 'p04-send-disabled')->where('event_type', 'result_callback_skipped')->exists())->toBeTrue();

    Http::assertNothingSent();
});

test('callback sender records failed events without exposing secrets', function (): void {
    Http::fake(['https://source.test/callbacks/talkto' => Http::response('temporary unavailable shared-callback-secret', 503)]);

    $message = p04IncomingMessage('p04-send-failed');
    $summary = app(ResultCallbackSenderContract::class)->sendResult(
        $message,
        TalktoIncomingCommandResult::failedFinal('Final failure.')
    );

    $events = TalktoEvent::query()->where('message_id', 'p04-send-failed')->get();
    $combinedMeta = $events->map(fn (TalktoEvent $event): string => json_encode($event->meta))->implode("\n");

    expect($summary['sent'])->toBeFalse()
        ->and(TalktoEvent::query()->where('message_id', 'p04-send-failed')->where('event_type', 'result_callback_failed')->exists())->toBeTrue()
        ->and($combinedMeta)->not->toContain('shared-callback-secret');
});

test('callback receiver verifies signed callback and updates original outgoing message to completed', function (): void {
    $message = p04OutgoingMessage('p04-receive-success');
    [$envelope, $headers] = p04SignedCallback($message, TalktoIncomingCommandResult::succeeded(['processed' => true]));

    $result = app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers);

    expect($result)->toMatchArray([
        'accepted' => true,
        'status' => 'applied',
        'original_message_id' => 'p04-receive-success',
        'duplicate' => false,
    ])->and($message->fresh()->destination_action_status)->toBe('succeeded')
        ->and($message->fresh()->overall_status)->toBe('completed')
        ->and(TalktoEvent::query()->where('message_id', 'p04-receive-success')->where('event_type', 'result_callback_received')->exists())->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', 'p04-receive-success')->where('event_type', 'result_callback_applied')->exists())->toBeTrue();
});

test('default v2 callback receiver accepts nonce and creates one nonce ledger row without exposing raw nonce', function (): void {
    $message = p04OutgoingMessage('p04-receive-v2-success');
    $rawNonce = 'callback-raw-nonce-never-exposed';
    [$envelope, $headers] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::succeeded(['processed' => true]),
        ['nonce' => $rawNonce]
    );

    $result = app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers);

    $storedNonce = TalktoNonce::query()->sole();
    $encodedResponse = json_encode($result);
    $encodedEvents = json_encode(TalktoEvent::query()->get()->toArray());

    expect($result)->toMatchArray([
        'accepted' => true,
        'status' => 'applied',
        'original_message_id' => 'p04-receive-v2-success',
        'duplicate' => false,
    ])->and(TalktoNonce::query()->count())->toBe(1)
        ->and($storedNonce->message_id)->toBe($envelope['message_id'])
        ->and($storedNonce->nonce_hash)->not->toBe($rawNonce)
        ->and(json_encode($storedNonce->toArray()))->not->toContain($rawNonce)
        ->and($encodedResponse)->not->toContain($rawNonce)
        ->and($encodedEvents)->not->toContain($rawNonce);
});

test('default v2 callback receiver rejects reused nonce with different callback message id', function (): void {
    $message = p04OutgoingMessage('p04-receive-v2-replay');
    $rawNonce = 'callback-reused-nonce-value';
    [$firstEnvelope, $firstHeaders] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::failedRetryable('Temporary failure.'),
        ['callback_message_id' => 'callback-v2-replay-first', 'nonce' => $rawNonce]
    );
    [$secondEnvelope, $secondHeaders] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::failedFinal('Final failure.'),
        ['callback_message_id' => 'callback-v2-replay-second', 'nonce' => $rawNonce]
    );

    $first = app(ResultCallbackReceiverContract::class)->receiveResult($firstEnvelope, $firstHeaders);
    $second = app(ResultCallbackReceiverContract::class)->receiveResult($secondEnvelope, $secondHeaders);

    expect($first)->toMatchArray([
        'accepted' => true,
        'status' => 'applied',
        'duplicate' => false,
    ])->and($second)->toMatchArray([
        'accepted' => false,
        'status' => 'rejected',
        'duplicate' => false,
        'error' => 'replay_nonce_reused',
    ])->and(TalktoNonce::query()->count())->toBe(1)
        ->and($message->fresh()->destination_action_status)->toBe('failed_retryable')
        ->and($message->fresh()->overall_status)->toBe('failed_retryable');
});

test('default v2 callback replay cannot regress status after later successful callback', function (): void {
    $message = p04OutgoingMessage('p04-receive-v2-no-regress');
    [$oldEnvelope, $oldHeaders] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::failedRetryable('Temporary failure.'),
        ['callback_message_id' => 'callback-v2-old', 'nonce' => 'callback-old-nonce']
    );
    [$newEnvelope, $newHeaders] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::succeeded(['processed' => true]),
        ['callback_message_id' => 'callback-v2-new', 'nonce' => 'callback-new-nonce']
    );

    $old = app(ResultCallbackReceiverContract::class)->receiveResult($oldEnvelope, $oldHeaders);
    $new = app(ResultCallbackReceiverContract::class)->receiveResult($newEnvelope, $newHeaders);
    $replay = app(ResultCallbackReceiverContract::class)->receiveResult($oldEnvelope, $oldHeaders);

    expect($old['accepted'])->toBeTrue()
        ->and($new['accepted'])->toBeTrue()
        ->and($replay)->toMatchArray([
            'accepted' => false,
            'status' => 'rejected',
            'duplicate' => false,
            'error' => 'replay_nonce_reused',
        ])->and($message->fresh()->destination_action_status)->toBe('succeeded')
        ->and($message->fresh()->overall_status)->toBe('completed')
        ->and(TalktoNonce::query()->count())->toBe(2);
});

test('default v2 callback receiver ignores stale retryable failure after success with fresh nonce', function (): void {
    $message = p04OutgoingMessage('p04-receive-v2-stale-retryable');
    [$successEnvelope, $successHeaders] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::succeeded(['processed' => true]),
        ['callback_message_id' => 'callback-v2-stale-success', 'nonce' => 'callback-v2-stale-success-nonce']
    );
    [$staleEnvelope, $staleHeaders] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::failedRetryable('Temporary failure.', RuntimeException::class),
        ['callback_message_id' => 'callback-v2-stale-retryable', 'nonce' => 'callback-v2-stale-retryable-nonce']
    );

    $success = app(ResultCallbackReceiverContract::class)->receiveResult($successEnvelope, $successHeaders);
    $stale = app(ResultCallbackReceiverContract::class)->receiveResult($staleEnvelope, $staleHeaders);
    $message->refresh();

    $encodedResponse = (string) json_encode($stale);
    $encodedEvents = (string) json_encode(TalktoEvent::query()->where('message_id', $message->message_id)->get()->toArray());

    expect($success)->toMatchArray([
        'accepted' => true,
        'status' => 'applied',
        'duplicate' => false,
    ])->and($stale)->toMatchArray([
        'accepted' => true,
        'status' => 'stale_ignored',
        'duplicate' => false,
    ])->and($message->destination_action_status)->toBe('succeeded')
        ->and($message->overall_status)->toBe('completed')
        ->and($message->completed_at)->not->toBeNull()
        ->and($message->failed_at)->toBeNull()
        ->and($message->last_error)->toBeNull()
        ->and(TalktoNonce::query()->count())->toBe(2)
        ->and($encodedResponse)->not->toContain('callback-v2-stale-retryable-nonce')
        ->and($encodedEvents)->not->toContain('callback-v2-stale-retryable-nonce')
        ->and(TalktoEvent::query()->where('message_id', $message->message_id)->where('event_type', 'result_callback_stale_ignored')->where('meta->ignored_destination_action_status', 'failed_retryable')->exists())->toBeTrue();
});

test('default v2 callback receiver ignores stale skipped result after success with fresh nonce', function (): void {
    $message = p04OutgoingMessage('p04-receive-v2-stale-skipped');
    [$successEnvelope, $successHeaders] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::succeeded(['processed' => true]),
        ['callback_message_id' => 'callback-v2-skipped-success', 'nonce' => 'callback-v2-skipped-success-nonce']
    );
    [$skippedEnvelope, $skippedHeaders] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::skipped('Not needed.'),
        ['callback_message_id' => 'callback-v2-stale-skipped', 'nonce' => 'callback-v2-stale-skipped-nonce']
    );

    app(ResultCallbackReceiverContract::class)->receiveResult($successEnvelope, $successHeaders);
    $skipped = app(ResultCallbackReceiverContract::class)->receiveResult($skippedEnvelope, $skippedHeaders);
    $message->refresh();

    expect($skipped)->toMatchArray([
        'accepted' => true,
        'status' => 'stale_ignored',
        'duplicate' => false,
    ])->and($message->destination_action_status)->toBe('succeeded')
        ->and($message->overall_status)->toBe('completed')
        ->and(TalktoNonce::query()->count())->toBe(2);
});

test('default v2 callback receiver ignores stale retryable failure after final failure with fresh nonce', function (): void {
    $message = p04OutgoingMessage('p04-receive-v2-final-no-downgrade');
    [$finalEnvelope, $finalHeaders] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::failedFinal('Final failure.', LogicException::class),
        ['callback_message_id' => 'callback-v2-final-first', 'nonce' => 'callback-v2-final-first-nonce']
    );
    [$retryableEnvelope, $retryableHeaders] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::failedRetryable('Temporary failure.', RuntimeException::class),
        ['callback_message_id' => 'callback-v2-final-stale-retryable', 'nonce' => 'callback-v2-final-stale-retryable-nonce']
    );

    $final = app(ResultCallbackReceiverContract::class)->receiveResult($finalEnvelope, $finalHeaders);
    $retryable = app(ResultCallbackReceiverContract::class)->receiveResult($retryableEnvelope, $retryableHeaders);
    $message->refresh();

    expect($final)->toMatchArray([
        'accepted' => true,
        'status' => 'applied',
    ])->and($retryable)->toMatchArray([
        'accepted' => true,
        'status' => 'stale_ignored',
        'duplicate' => false,
    ])->and($message->destination_action_status)->toBe('failed_final')
        ->and($message->overall_status)->toBe('failed_final')
        ->and($message->last_error)->toBe('Final failure.')
        ->and(TalktoNonce::query()->count())->toBe(2);
});

test('default v2 callback receiver can recover retryable failure with later success', function (): void {
    $message = p04OutgoingMessage('p04-receive-v2-retryable-recovers');
    [$retryableEnvelope, $retryableHeaders] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::failedRetryable('Temporary failure.', RuntimeException::class),
        ['callback_message_id' => 'callback-v2-recover-retryable', 'nonce' => 'callback-v2-recover-retryable-nonce']
    );
    [$successEnvelope, $successHeaders] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::succeeded(['processed' => true]),
        ['callback_message_id' => 'callback-v2-recover-success', 'nonce' => 'callback-v2-recover-success-nonce']
    );

    $retryable = app(ResultCallbackReceiverContract::class)->receiveResult($retryableEnvelope, $retryableHeaders);
    $success = app(ResultCallbackReceiverContract::class)->receiveResult($successEnvelope, $successHeaders);
    $message->refresh();

    expect($retryable)->toMatchArray([
        'accepted' => true,
        'status' => 'applied',
    ])->and($success)->toMatchArray([
        'accepted' => true,
        'status' => 'applied',
        'duplicate' => false,
    ])->and($message->destination_action_status)->toBe('succeeded')
        ->and($message->overall_status)->toBe('completed')
        ->and($message->completed_at)->not->toBeNull()
        ->and($message->failed_at)->toBeNull()
        ->and($message->last_error)->toBeNull()
        ->and(TalktoNonce::query()->count())->toBe(2);
});

test('callback route maps reused v2 nonce to conflict', function (): void {
    $message = p04OutgoingMessage('p04-receive-v2-route-conflict');
    [$envelope, $headers] = p04SignedV2Callback(
        $message,
        TalktoIncomingCommandResult::succeeded(),
        ['nonce' => 'callback-route-reused-nonce']
    );

    app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers);

    $request = Request::create('/api/talkto/callback', 'POST', $envelope);

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    $response = app(TalktoResultCallbackController::class)->__invoke($request, app(ResultCallbackReceiverContract::class));

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['error'])->toBe('replay_nonce_reused');
});

test('callback receiver success clears stale failure fields', function (): void {
    $message = p04OutgoingMessage('p04-receive-success-clears-failure', [
        'destination_action_status' => 'failed_retryable',
        'overall_status' => 'failed_retryable',
        'failed_at' => now()->subMinute(),
        'last_error' => 'stale failure',
    ]);
    [$envelope, $headers] = p04SignedCallback($message, TalktoIncomingCommandResult::succeeded(['processed' => true]));

    $result = app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers);
    $message = $message->fresh();

    expect($result)->toMatchArray([
        'accepted' => true,
        'status' => 'applied',
        'duplicate' => false,
    ])->and($message->destination_action_status)->toBe('succeeded')
        ->and($message->overall_status)->toBe('completed')
        ->and($message->completed_at)->not->toBeNull()
        ->and($message->failed_at)->toBeNull()
        ->and($message->last_error)->toBeNull();
});

test('callback receiver rejects when callbacks are disabled', function (): void {
    $message = p04OutgoingMessage('p04-receive-disabled');
    [$envelope, $headers] = p04SignedCallback($message, TalktoIncomingCommandResult::succeeded());

    config(['talkto.callbacks.enabled' => false]);

    expect(app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers))->toMatchArray([
        'accepted' => false,
        'status' => 'rejected',
        'duplicate' => false,
        'error' => 'callbacks_disabled',
    ])->and(TalktoEvent::query()->where('message_id', 'p04-receive-disabled')->exists())->toBeFalse();
});

test('callback receiver handles skipped retryable and final results', function (): void {
    $cases = [
        'skipped' => [TalktoIncomingCommandResult::skipped('not needed'), 'skipped', 'completed'],
        'retryable' => [TalktoIncomingCommandResult::failedRetryable('Temporary failure.', RuntimeException::class), 'failed_retryable', 'failed_retryable'],
        'final' => [TalktoIncomingCommandResult::failedFinal('Final failure.', LogicException::class), 'failed_final', 'failed_final'],
    ];

    foreach ($cases as $name => [$callbackResult, $destinationStatus, $overallStatus]) {
        $message = p04OutgoingMessage("p04-receive-{$name}");
        [$envelope, $headers] = p04SignedCallback($message, $callbackResult);

        $result = app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers);

        expect($result['accepted'])->toBeTrue()
            ->and($message->fresh()->destination_action_status)->toBe($destinationStatus)
            ->and($message->fresh()->overall_status)->toBe($overallStatus);
    }
});

test('callback receiver ignores stale failure for completed messages', function (): void {
    $message = p04OutgoingMessage('p04-receive-failure-after-completed', [
        'destination_action_status' => 'succeeded',
        'overall_status' => 'completed',
        'completed_at' => now()->subMinute(),
    ]);
    [$envelope, $headers] = p04SignedCallback($message, TalktoIncomingCommandResult::failedRetryable('Temporary failure.', RuntimeException::class));

    $result = app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers);
    $message = $message->fresh();

    expect($result)->toMatchArray([
        'accepted' => true,
        'status' => 'stale_ignored',
        'duplicate' => false,
    ])->and($message->destination_action_status)->toBe('succeeded')
        ->and($message->overall_status)->toBe('completed')
        ->and($message->completed_at)->not->toBeNull()
        ->and($message->failed_at)->toBeNull()
        ->and($message->last_error)->toBeNull()
        ->and(TalktoEvent::query()->where('message_id', $message->message_id)->where('event_type', 'result_callback_stale_ignored')->exists())->toBeTrue();
});

test('callback receiver rejects invalid callback status and linked mismatches', function (): void {
    $invalidStatus = p04OutgoingMessage('p04-invalid-status');
    [$invalidStatusEnvelope, $invalidStatusHeaders] = p04SignedCallback(
        $invalidStatus,
        TalktoIncomingCommandResult::succeeded(),
        ['status' => 'unknown_status']
    );

    expect(app(ResultCallbackReceiverContract::class)->receiveResult($invalidStatusEnvelope, $invalidStatusHeaders))->toMatchArray([
        'accepted' => false,
        'error' => 'invalid_callback_status',
    ])->and(TalktoEvent::query()->where('message_id', 'p04-invalid-status')->where('event_type', 'result_callback_rejected')->where('meta->error', 'invalid_callback_status')->exists())->toBeTrue();

    $commandMismatch = p04OutgoingMessage('p04-command-mismatch');
    [$commandEnvelope, $commandHeaders] = p04SignedCallback(
        $commandMismatch,
        TalktoIncomingCommandResult::succeeded(),
        ['original_command' => 'other.command']
    );

    expect(app(ResultCallbackReceiverContract::class)->receiveResult($commandEnvelope, $commandHeaders))->toMatchArray([
        'accepted' => false,
        'error' => 'callback_original_command_mismatch',
    ])->and(TalktoEvent::query()->where('message_id', 'p04-command-mismatch')->where('event_type', 'result_callback_rejected')->where('meta->error', 'callback_original_command_mismatch')->exists())->toBeTrue();

    $parentMismatch = p04OutgoingMessage('p04-parent-mismatch');
    [$parentEnvelope, $parentHeaders] = p04SignedCallback(
        $parentMismatch,
        TalktoIncomingCommandResult::succeeded(),
        ['parent_message_id' => 'different-message-id']
    );

    expect(app(ResultCallbackReceiverContract::class)->receiveResult($parentEnvelope, $parentHeaders))->toMatchArray([
        'accepted' => false,
        'error' => 'callback_parent_message_mismatch',
    ])->and(TalktoEvent::query()->where('message_id', 'p04-parent-mismatch')->where('event_type', 'result_callback_rejected')->where('meta->error', 'callback_parent_message_mismatch')->exists())->toBeTrue();
});

test('callback receiver rejects invalid signature unknown originals and wrong relationships', function (): void {
    $message = p04OutgoingMessage('p04-invalid-signature');
    [$envelope, $headers] = p04SignedCallback($message, TalktoIncomingCommandResult::succeeded());
    $headers['X-Talkto-Signature'] = 'invalid';

    expect(app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers))->toMatchArray([
        'accepted' => false,
        'error' => 'invalid_signature',
    ]);

    [$missingEnvelope, $missingHeaders] = p04SignedCallback(
        p04OutgoingMessage('p04-missing-original-build'),
        TalktoIncomingCommandResult::succeeded(),
        ['original_message_id' => 'p04-missing-original']
    );

    expect(app(ResultCallbackReceiverContract::class)->receiveResult($missingEnvelope, $missingHeaders))->toMatchArray([
        'accepted' => false,
        'error' => 'original_message_not_found',
        'original_message_id' => 'p04-missing-original',
    ]);

    $wrong = p04OutgoingMessage('p04-wrong-relationship', ['target_service' => 'other-target']);
    [$wrongEnvelope, $wrongHeaders] = p04SignedCallback($wrong, TalktoIncomingCommandResult::succeeded());

    expect(app(ResultCallbackReceiverContract::class)->receiveResult($wrongEnvelope, $wrongHeaders))->toMatchArray([
        'accepted' => false,
        'error' => 'callback_relationship_mismatch',
    ]);
});

test('duplicate callback is accepted without duplicate applied side effects', function (): void {
    $message = p04OutgoingMessage('p04-duplicate');
    [$envelope, $headers] = p04SignedCallback($message, TalktoIncomingCommandResult::succeeded());

    $first = app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers);
    $second = app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers);

    expect($first['duplicate'])->toBeFalse()
        ->and($second['duplicate'])->toBeTrue()
        ->and(TalktoEvent::query()->where('message_id', 'p04-duplicate')->where('event_type', 'result_callback_applied')->count())->toBe(1)
        ->and(TalktoEvent::query()->where('message_id', 'p04-duplicate')->where('event_type', 'result_callback_duplicate')->count())->toBe(1);
});

test('callback route exists when routes are enabled and uses configured uri and name', function (): void {
    config([
        'talkto.routes.prefix' => 'api',
        'talkto.routes.callback_uri' => 'custom/talkto/callback',
        'talkto.routes.callback_name' => 'custom.talkto.callback',
    ]);

    require __DIR__.'/../../routes/api.php';
    Route::getRoutes()->refreshNameLookups();

    $route = Route::getRoutes()->getByName('custom.talkto.callback');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('api/custom/talkto/callback');
});

test('callback route is not registered when callbacks are disabled but receive route still is', function (): void {
    config([
        'talkto.routes.prefix' => 'api',
        'talkto.routes.receive_uri' => 'receive-only/talkto',
        'talkto.routes.receive_name' => 'receive-only.talkto',
        'talkto.routes.callback_uri' => 'disabled/talkto/callback',
        'talkto.routes.callback_name' => 'disabled.talkto.callback',
        'talkto.callbacks.enabled' => false,
    ]);

    require __DIR__.'/../../routes/api.php';
    Route::getRoutes()->refreshNameLookups();

    expect(Route::getRoutes()->getByName('disabled.talkto.callback'))->toBeNull()
        ->and(Route::getRoutes()->getByName('receive-only.talkto'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('receive-only.talkto')->uri())->toBe('api/receive-only/talkto');
});

test('callback contracts resolve to defaults and can be overridden by hosts', function (): void {
    expect(app(ResultCallbackSenderContract::class))->toBeInstanceOf(TalktoResultCallbackSender::class)
        ->and(app(ResultCallbackReceiverContract::class))->toBeInstanceOf(TalktoResultCallbackReceiver::class);

    app()->bind(ResultCallbackSenderContract::class, P04CustomCallbackSender::class);
    app()->bind(ResultCallbackReceiverContract::class, P04CustomCallbackReceiver::class);

    expect(app(ResultCallbackSenderContract::class))->toBeInstanceOf(P04CustomCallbackSender::class)
        ->and(app(ResultCallbackReceiverContract::class))->toBeInstanceOf(P04CustomCallbackReceiver::class);
});

test('callback runtime docs describe current statuses without old status list', function (): void {
    $docs = file_get_contents(__DIR__.'/../../docs/callback-contract-template.md');
    preg_match('/## Valid Callback Statuses(?P<section>.*?)## Signature And Hash Verification/s', $docs, $matches);
    $statusSection = $matches['section'] ?? '';

    expect($docs)->toContain('talkto.result')
        ->and($docs)->toContain('original_message_id')
        ->and($docs)->toContain('original_command')
        ->and($docs)->toContain('retryable')
        ->and($statusSection)->toContain('- `succeeded`')
        ->and($statusSection)->toContain('- `skipped`')
        ->and($statusSection)->toContain('- `failed_retryable`')
        ->and($statusSection)->toContain('- `failed_final`')
        ->and($statusSection)->not->toContain('- `failed`')
        ->and($statusSection)->not->toContain('- `rejected`')
        ->and($statusSection)->not->toContain('- `retryable`');
});

function p04IncomingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    $payload = ['resource_id' => $messageId];

    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'correlation-'.$messageId,
        'direction' => 'incoming',
        'source_service' => 'source-service',
        'target_service' => 'target-service',
        'command' => 'domain.command',
        'business_key' => 'business-key-123',
        'idempotency_key' => 'idempotency-key-'.$messageId,
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
    ], $attributes));
}

function p04OutgoingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    $payload = ['resource_id' => $messageId];

    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'correlation-'.$messageId,
        'direction' => 'outgoing',
        'source_service' => 'source-service',
        'target_service' => 'target-service',
        'command' => 'domain.command',
        'business_key' => 'business-key-123',
        'idempotency_key' => 'idempotency-key-'.$messageId,
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1,
        'source_action_status' => 'succeeded',
        'transport_status' => 'sent',
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'destination_received',
        'attempts' => 1,
        'retry_count' => 0,
        'max_attempts' => 5,
        'sent_at' => now(),
    ], $attributes));
}

function p04SignedCallback(TalktoMessage $original, TalktoIncomingCommandResult $result, array $overrides = []): array
{
    config([
        'talkto.service' => 'target-service',
        'talkto.outgoing.source-service' => [
            'url' => 'https://source.test',
            'secret' => 'shared-callback-secret',
            'callback_endpoint' => '/api/talkto/callback',
        ],
    ]);

    $incoming = new TalktoMessage;
    $incoming->forceFill([
        'message_id' => $original->message_id,
        'source_service' => $original->source_service,
        'target_service' => $original->target_service,
        'command' => $original->command,
        'correlation_id' => $original->correlation_id,
        'business_key' => $original->business_key,
        'idempotency_key' => $original->idempotency_key,
    ]);

    $envelope = TalktoResultCallbackData::fromIncomingMessageResult($incoming, $result, [
        'callback_message_id' => $overrides['callback_message_id'] ?? 'callback-'.$original->message_id,
    ])->toEnvelope();

    if (isset($overrides['original_message_id'])) {
        $envelope['payload']['original_message_id'] = $overrides['original_message_id'];
    }

    if (isset($overrides['original_command'])) {
        $envelope['payload']['original_command'] = $overrides['original_command'];
    }

    if (isset($overrides['status'])) {
        $envelope['payload']['status'] = $overrides['status'];
    }

    if (array_key_exists('parent_message_id', $overrides)) {
        $envelope['parent_message_id'] = $overrides['parent_message_id'];
    }

    $envelope['payload_hash'] = app(TalktoPayloadHasher::class)->hash($envelope['payload']);

    $headers = app(TalktoResultCallbackEnvelopeBuilder::class)->buildHeaders($envelope);

    config([
        'talkto.service' => 'source-service',
        'talkto.security.accept_versions' => ['v1'],
        'talkto.incoming.target-service' => [
            'secret' => 'shared-callback-secret',
            'allowed_commands' => [
                'talkto.result' => [
                    'driver' => 'none',
                ],
            ],
        ],
    ]);

    return [$envelope, $headers];
}

function p04SignedV2Callback(TalktoMessage $original, TalktoIncomingCommandResult $result, array $overrides = []): array
{
    p04UseDefaultV2Security();

    $incoming = new TalktoMessage;
    $incoming->forceFill([
        'message_id' => $original->message_id,
        'source_service' => $original->source_service,
        'target_service' => $original->target_service,
        'command' => $original->command,
        'correlation_id' => $original->correlation_id,
        'business_key' => $original->business_key,
        'idempotency_key' => $original->idempotency_key,
    ]);

    $envelope = TalktoResultCallbackData::fromIncomingMessageResult($incoming, $result, [
        'callback_message_id' => $overrides['callback_message_id'] ?? 'callback-'.$original->message_id,
    ])->toEnvelope();

    if (isset($overrides['status'])) {
        $envelope['payload']['status'] = $overrides['status'];
    }

    $envelope['payload_hash'] = app(TalktoPayloadHasher::class)->hash($envelope['payload']);

    $timestamp = $overrides['timestamp'] ?? now()->toIso8601String();
    $nonce = $overrides['nonce'] ?? 'callback-nonce-'.$envelope['message_id'];
    $secret = 'shared-callback-secret';
    $signature = app(TalktoSigner::class)->signV2(
        (string) $timestamp,
        (string) $nonce,
        (string) $envelope['message_id'],
        (string) $envelope['source'],
        (string) $envelope['target'],
        (string) $envelope['command'],
        (string) $envelope['payload_hash'],
        $secret
    );

    config([
        'talkto.service' => 'source-service',
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
        'talkto.incoming.target-service' => [
            'secret' => $secret,
            'allowed_commands' => [
                'talkto.result' => [
                    'driver' => 'none',
                ],
            ],
        ],
    ]);

    return [$envelope, [
        'X-Talkto-Signature' => $signature,
        'X-Talkto-Timestamp' => (string) $timestamp,
        'X-Talkto-Message-Id' => (string) $envelope['message_id'],
        'X-Talkto-Protocol-Version' => '2',
        'X-Talkto-Signature-Version' => 'v2',
        'X-Talkto-Payload-Hash' => (string) $envelope['payload_hash'],
        'X-Talkto-Nonce' => (string) $nonce,
    ]];
}

function p04UseDefaultV2Security(): void
{
    config([
        'talkto.service' => 'target-service',
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
        'talkto.outgoing.source-service' => [
            'url' => 'https://source.test',
            'secret' => 'shared-callback-secret',
            'callback_endpoint' => '/callbacks/talkto',
        ],
    ]);
}

class P04CustomCallbackSender implements ResultCallbackSenderContract
{
    public function sendResult(Model $message, IncomingCommandResultContract $result, array $options = []): mixed
    {
        return ['sent' => true];
    }
}

class P04CustomCallbackReceiver implements ResultCallbackReceiverContract
{
    public function receiveResult(array $envelope, array $headers = []): mixed
    {
        return ['accepted' => true];
    }
}
