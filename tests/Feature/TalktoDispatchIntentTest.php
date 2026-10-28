<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\Connectors\SyncConnector;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDispatchIntent;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoDispatchIntentService;
use Mrezdev\LaravelTalkto\Services\TalktoFlowBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackReceiver;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;

beforeEach(function (): void {
    DurableIntentCountingHandler::$calls = 0;
    config([
        'talkto.service' => 'testing',
        'talkto.dispatch_intents.enabled' => false,
        'talkto.dispatch_intents.recovery_enabled' => false,
        'talkto.dispatch_intents.publish_after_commit' => true,
        'talkto.dispatch_intents.lease_seconds' => 60,
        'talkto.outgoing.peer' => ['url' => 'https://durable-intent.invalid', 'secret' => 'synthetic-durable-intent-secret'],
        'talkto.incoming.peer' => ['secret' => 'synthetic-durable-intent-secret', 'allowed_commands' => ['domain.command' => ['driver' => 'none'], 'talkto.result' => ['driver' => 'none']]],
        'talkto.callbacks.auto_dispatch' => false,
        'queue.default' => 'durable_database',
        'queue.connections.durable_database' => [
            'driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs',
            'queue' => 'durable-probe', 'retry_after' => 90, 'after_commit' => false,
        ],
    ]);
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->loadMigrationsFrom(__DIR__.'/../../database/dispatch-intents');
    expect($this->artisan('migrate')->run())->toBe(0);
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

afterEach(function (): void {
    TalktoDispatchTestHooks::reset();
    DB::purge('durable_other');
    DB::purge('durable_broker');
});

test('disabled durable mode preserves legacy outgoing publication without requiring its table', function (): void {
    Schema::dropIfExists('talkto_dispatch_intents');
    $message = durableIntentFlow()->option('message_id', 'disabled-send')->send();

    expect($message->message_id)->toBe('disabled-send')
        ->and($message->overall_status)->toBe('waiting_to_send')
        ->and(DB::table('jobs')->count())->toBe(1);
    $queued = Queue::connection('durable_database')->pop('durable-probe');
    expect($queued)->not->toBeNull()->and($queued->resolveName())->toBe(SendTalktoMessage::class);
    $queued->delete();
});

test('disabled factory only persistence remains intentionally undispatched', function (): void {
    Schema::dropIfExists('talkto_dispatch_intents');
    $message = app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', ['synthetic' => true]);

    expect($message->exists)->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('talkto_events')->where('event_type', 'message_created')->count())->toBe(1);
});

test('disabled idempotent outgoing flow retains the original dispatch contract', function (): void {
    Schema::dropIfExists('talkto_dispatch_intents');
    $first = durableIntentFlow()->idempotencyKey('disabled-idempotency')->send();
    $second = durableIntentFlow()->idempotencyKey('disabled-idempotency')->send();

    expect($second->getKey())->toBe($first->getKey())
        ->and(TalktoMessage::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(2);
});

test('disabled outgoing run preserves source action and after commit behavior', function (): void {
    Schema::dropIfExists('talkto_dispatch_intents');
    $connection = DB::connection();
    $connection->beginTransaction();
    try {
        $message = durableIntentFlow()->run(fn (): array => ['payload' => ['synthetic' => true], 'result' => ['ok' => true]]);
        expect($message->source_action_status)->toBe('succeeded')
            ->and(DB::table('jobs')->count())->toBe(0);
        $connection->commit();
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    expect(DB::table('jobs')->count())->toBe(1);
});

test('disabled rolled back outgoing flow creates no executable job', function (): void {
    Schema::dropIfExists('talkto_dispatch_intents');
    $connection = DB::connection();
    $connection->beginTransaction();
    try {
        durableIntentFlow()->send();
        expect(DB::table('jobs')->count())->toBe(0);
    } finally {
        $connection->rollBack();
    }
    expect(TalktoMessage::query()->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
});

test('disabled incoming valid and duplicate requests keep the existing response and publication contract', function (): void {
    Schema::dropIfExists('talkto_dispatch_intents');
    $receiver = app(ReceiveIncomingTalktoMessagePipeline::class);
    $first = $receiver->receive(durableIntentRequest('disabled-incoming'), app(TalktoSignatureVerifier::class));
    $duplicate = $receiver->receive(durableIntentRequest('disabled-incoming'), app(TalktoSignatureVerifier::class));

    expect($first->getStatusCode())->toBe(202)
        ->and($first->getData(true))->toMatchArray(['received' => true, 'status' => 'queued', 'message_id' => 'disabled-incoming'])
        ->and($duplicate->getStatusCode())->toBe(200)
        ->and($duplicate->getData(true))->toMatchArray(['received' => true, 'duplicate' => true, 'status' => 'already_received'])
        ->and(TalktoMessage::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('disabled incoming idempotency reuse does not enqueue another processing job', function (): void {
    Schema::dropIfExists('talkto_dispatch_intents');
    $receiver = app(ReceiveIncomingTalktoMessagePipeline::class);
    $first = $receiver->receive(durableIntentRequest('disabled-key-first', ['idempotency_key' => 'disabled-key']), app(TalktoSignatureVerifier::class));
    $duplicate = $receiver->receive(durableIntentRequest('disabled-key-second', ['idempotency_key' => 'disabled-key']), app(TalktoSignatureVerifier::class));

    expect($first->getStatusCode())->toBe(202)
        ->and($duplicate->getStatusCode())->toBe(200)
        ->and($duplicate->getData(true))->toMatchArray(['duplicate' => true, 'status' => 'already_accepted'])
        ->and(TalktoMessage::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('disabled incoming security rejection never creates a message or executable queue job', function (array $overrides, bool $badSignature, int $status): void {
    Schema::dropIfExists('talkto_dispatch_intents');
    $response = app(ReceiveIncomingTalktoMessagePipeline::class)->receive(
        durableIntentRequest('disabled-rejected', $overrides, badSignature: $badSignature),
        app(TalktoSignatureVerifier::class)
    );

    expect($response->getStatusCode())->toBe($status)
        ->and(TalktoMessage::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'invalid HMAC' => [[], true, 401],
    'wrong target' => [['target' => 'other-service'], false, 403],
    'unauthorized command' => [['command' => 'forbidden.command'], false, 403],
]);

test('disabled mode preserves custom outgoing and incoming job queue selection', function (): void {
    Schema::dropIfExists('talkto_dispatch_intents');
    config(['talkto.jobs.send_message' => DurableIntentCustomSendJob::class,
        'talkto.jobs.process_incoming' => DurableIntentCustomIncomingJob::class]);
    durableIntentFlow()->send();
    $response = app(ReceiveIncomingTalktoMessagePipeline::class)->receive(durableIntentRequest('disabled-custom-incoming'), app(TalktoSignatureVerifier::class));

    expect($response->getStatusCode())->toBe(202)
        ->and(DB::table('jobs')->where('queue', 'custom-send')->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'custom-incoming')->count())->toBe(1);
    $outgoing = Queue::connection()->pop('custom-send');
    $incoming = Queue::connection()->pop('custom-incoming');
    expect($outgoing->resolveName())->toBe(DurableIntentCustomSendJob::class)
        ->and($incoming->resolveName())->toBe(DurableIntentCustomIncomingJob::class);
    $outgoing->delete();
    $incoming->delete();
});

test('disabled sync queue still executes only after the existing outer commit', function (): void {
    Schema::dropIfExists('talkto_dispatch_intents');
    config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync', 'after_commit' => false]]);
    $client = new DurableIntentHttpClient;
    app()->instance(TalktoHttpClient::class, $client);
    $connection = DB::connection();
    $connection->beginTransaction();
    try {
        $message = durableIntentFlow()->send();
        expect($client->calls)->toBe(0)->and($message->overall_status)->toBe('waiting_to_send');
        $connection->commit();
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    expect($client->calls)->toBe(1)
        ->and($message->fresh()->overall_status)->toBe('destination_received')
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('disabled failed source action remains a persistence only failure audit', function (): void {
    Schema::dropIfExists('talkto_dispatch_intents');
    expect(fn () => durableIntentFlow()->run(fn () => throw new RuntimeException('Synthetic source failure.')))
        ->toThrow(RuntimeException::class, 'Synthetic source failure.');

    $message = TalktoMessage::query()->sole();
    expect($message->source_action_status)->toBe('failed')
        ->and($message->overall_status)->toBe('failed')
        ->and($message->transport_status)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('enabled outgoing flow commits one new message intent and publication', function (): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    $message = durableIntentFlow()->option('message_id', 'enabled-new')->send();

    expect($message->message_id)->toBe('enabled-new')
        ->and($message->overall_status)->toBe('waiting_to_send')
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('enabled message and intent share the outer transaction and publish only after commit', function (): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    $connection = DB::connection();
    $connection->beginTransaction();
    try {
        durableIntentFlow()->option('message_id', 'enabled-outer')->send();
        expect(TalktoMessage::query()->count())->toBe(1)
            ->and(DB::table('talkto_dispatch_intents')->count())->toBe(1)
            ->and(DB::table('jobs')->count())->toBe(0);
        $connection->commit();
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    expect(DB::table('jobs')->count())->toBe(1);
});

test('enabled outer rollback removes both message and intent and leaves no executable job', function (): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    $connection = DB::connection();
    $connection->beginTransaction();
    try {
        durableIntentFlow()->send();
        expect(TalktoMessage::query()->count())->toBe(1)
            ->and(DB::table('talkto_dispatch_intents')->count())->toBe(1);
    } finally {
        $connection->rollBack();
    }
    expect(TalktoMessage::query()->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('enabled mode rolls back an interrupted message and intent transaction atomically', function (string $table): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    $failed = false;
    DB::listen(function (QueryExecuted $query) use ($table, &$failed): void {
        if (! $failed && preg_match('/^insert into ["`]?'.preg_quote($table, '/').'["`]?\b/i', $query->sql)) {
            $failed = true;
            throw new RuntimeException('Synthetic atomic insert interruption.');
        }
    });

    expect(fn () => durableIntentFlow()->send())->toThrow(RuntimeException::class, 'Synthetic atomic insert interruption.');
    expect($failed)->toBeTrue()
        ->and(TalktoMessage::query()->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('talkto_events')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['after message insert' => ['talkto_messages'], 'after intent insert' => ['talkto_dispatch_intents']]);

test('enabled factory only API still creates no dispatch intent or queue job', function (): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', ['synthetic' => true]);

    expect(TalktoMessage::query()->count())->toBe(1)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('enabled flow does not create an intent for a factory created idempotent existing message', function (): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    $existing = app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', [], ['idempotency_key' => 'existing-factory']);
    $returned = durableIntentFlow()->idempotencyKey('existing-factory')->send();

    expect($returned->getKey())->toBe($existing->getKey())
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('enabled repeated idempotent flow does not create another intent or publication', function (): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    $first = durableIntentFlow()->idempotencyKey('existing-intent')->send();
    $second = durableIntentFlow()->idempotencyKey('existing-intent')->send();

    expect($second->getKey())->toBe($first->getKey())
        ->and(TalktoMessage::query()->count())->toBe(1)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('enabled incoming creation atomically adds one processing intent while duplicates do not', function (): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    $receiver = app(ReceiveIncomingTalktoMessagePipeline::class);
    $first = $receiver->receive(durableIntentRequest('enabled-incoming', ['idempotency_key' => 'enabled-incoming-key']), app(TalktoSignatureVerifier::class));
    $duplicate = $receiver->receive(durableIntentRequest('enabled-incoming'), app(TalktoSignatureVerifier::class));
    $idempotent = $receiver->receive(durableIntentRequest('enabled-incoming-other', ['idempotency_key' => 'enabled-incoming-key']), app(TalktoSignatureVerifier::class));

    expect($first->getStatusCode())->toBe(202)
        ->and($duplicate->getStatusCode())->toBe(200)
        ->and($idempotent->getStatusCode())->toBe(200)
        ->and(TalktoMessage::query()->count())->toBe(1)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('enabled incoming authorization failures cannot create executable intents', function (array $overrides, bool $badSignature, int $status): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    $response = app(ReceiveIncomingTalktoMessagePipeline::class)->receive(
        durableIntentRequest('enabled-security', $overrides, badSignature: $badSignature), app(TalktoSignatureVerifier::class)
    );

    expect($response->getStatusCode())->toBe($status)
        ->and(TalktoMessage::query()->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['invalid HMAC' => [[], true, 401], 'wrong target' => [['target' => 'other'], false, 403], 'forbidden command' => [['command' => 'forbidden'], false, 403]]);

test('enabled dispatch cannot silently fall back when the required intent table is missing', function (): void {
    Schema::dropIfExists('talkto_dispatch_intents');
    config(['talkto.dispatch_intents.enabled' => true]);
    $failure = null;
    try {
        durableIntentFlow()->send();
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }
    expect($failure)->not->toBeNull()
        ->and(TalktoMessage::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(Schema::hasTable('talkto_dispatch_intents'))->toBeFalse();
});

test('durable sync mode is rejected before any message or publication', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'queue.default' => 'sync',
        'queue.connections.sync' => ['driver' => 'sync', 'after_commit' => false]]);
    $failure = null;
    try {
        durableIntentFlow()->send();
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }
    expect($failure)->not->toBeNull()
        ->and(TalktoMessage::query()->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('the existing undispatched command remains read only even when durable recovery is enabled', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', ['synthetic' => true]);
    $before = DB::table('talkto_messages')->get()->map(fn ($row) => (array) $row)->all();

    expect(Artisan::call('talkto:recover-undispatched', ['--min-age' => 0, '--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['mode' => 'diagnostic_only', 'recovery_enabled' => false])
        ->and(Artisan::call('talkto:recover-undispatched', ['--execute' => true]))->toBe(1)
        ->and(DB::table('talkto_messages')->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a committed never attempted intent is actually recovered and repeated recovery does not republish', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false]);
    $message = durableIntentFlow()->option('message_id', 'recover-ready')->send();
    expect(DB::table('talkto_dispatch_intents')->count())->toBe(1)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('ready')
        ->and(DB::table('jobs')->count())->toBe(0);

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--message-id' => $message->message_id, '--min-age' => 0, '--json' => true]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('published');

    $client = new DurableIntentHttpClient;
    app()->instance(TalktoHttpClient::class, $client);
    $job = Queue::connection()->pop('durable-probe');
    expect($job)->not->toBeNull()->and($job->resolveName())->toBe(SendTalktoMessage::class);
    $job->fire();
    $job->delete();
    expect($client->calls)->toBe(1)
        ->and($message->fresh()->overall_status)->toBe('destination_received');

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--message-id' => $message->message_id, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(1)
        ->and($client->calls)->toBe(1);
});

test('the new intent command defaults to sanitized read only observations', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false]);
    $message = durableIntentFlow()->payload(['password' => 'synthetic-private-body'])->option('message_id', 'read-only-intent')->send();
    $before = DB::table('talkto_dispatch_intents')->get()->map(fn ($row) => (array) $row)->all();

    expect(Artisan::call('talkto:dispatch-intents', ['--message-id' => $message->message_id, '--min-age' => 0, '--json' => true]))->toBe(0);
    $output = Artisan::output();
    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBeArray()
        ->and($output)->toContain('read-only-intent')->not->toContain('synthetic-private-body', 'synthetic-durable-intent-secret')
        ->and(DB::table('talkto_dispatch_intents')->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('manual intent execution requires both independent opt in flags', function (bool $enabled, bool $recovery): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false]);
    durableIntentFlow()->send();
    config(['talkto.dispatch_intents.enabled' => $enabled, 'talkto.dispatch_intents.recovery_enabled' => $recovery]);
    $before = DB::table('talkto_dispatch_intents')->get()->map(fn ($row) => (array) $row)->all();

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
})->with(['both disabled' => [false, false], 'intent disabled' => [false, true], 'recovery disabled' => [true, false]]);

test('historical and factory only rows are never backfilled or published by intent recovery', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    $factory = app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', [], ['message_id' => 'factory-no-intent']);
    $before = $factory->fresh()->getRawOriginal();

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($factory->fresh()->getRawOriginal())->toBe($before);
});

test('terminal messages never receive a job from a previously ready intent', function (string $status): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false]);
    $message = durableIntentFlow()->send();
    $message->forceFill(['overall_status' => $status, 'completed_at' => now(), 'destination_action_status' => 'succeeded'])->save();
    $before = $message->fresh()->getRawOriginal();

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($message->fresh()->getRawOriginal())->toBe($before);
})->with(['completed', 'succeeded', 'failed_final', 'cancelled', 'skipped', 'sent', 'destination_received', 'processing', 'sending']);

test('a changed service owner cannot receive a publication from an older ready intent', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false]);
    $message = durableIntentFlow()->send();
    $message->forceFill(['source_service' => 'different-owner'])->save();
    $before = $message->fresh()->getRawOriginal();

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($message->fresh()->getRawOriginal())->toBe($before);
});

test('incoming recovery publishes the existing job and a real queue worker invokes the handler once', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false,
        'talkto.incoming.peer.allowed_commands' => ['domain.command' => ['handler' => DurableIntentCountingHandler::class]]]);
    $response = app(ReceiveIncomingTalktoMessagePipeline::class)->receive(durableIntentRequest('recover-incoming'), app(TalktoSignatureVerifier::class));
    expect($response->getStatusCode())->toBe(202)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('ready')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DurableIntentCountingHandler::$calls)->toBe(0);

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--direction' => 'incoming', '--message-id' => 'recover-incoming', '--min-age' => 0]))->toBe(0);
    $job = Queue::connection()->pop('durable-probe');
    expect($job)->not->toBeNull()->and($job->resolveName())->toBe(ProcessIncomingTalktoMessage::class);
    $job->fire();
    $job->delete();
    expect(DurableIntentCountingHandler::$calls)->toBe(1)
        ->and(TalktoMessage::query()->sole()->overall_status)->toBe('succeeded')
        ->and(DB::table('talkto_attempts')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DurableIntentCountingHandler::$calls)->toBe(1);
});

test('enabled outgoing run commits its source work message and intent together', function (): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    Schema::create('durable_source_probe', function (Blueprint $table): void {
        $table->id();
        $table->string('value');
    });
    $message = durableIntentFlow()->run(function (): array {
        DB::table('durable_source_probe')->insert(['value' => 'committed-source']);

        return ['payload' => ['synthetic' => true], 'result' => ['committed' => true]];
    });
    expect($message->source_action_status)->toBe('succeeded')
        ->and(DB::table('durable_source_probe')->count())->toBe(1)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('durable flow rejects an unrelated open transaction before creating dispatch state', function (): void {
    config(['talkto.dispatch_intents.enabled' => true,
        'database.connections.durable_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $other = DB::connection('durable_other');
    $other->beginTransaction();
    $failure = null;
    try {
        durableIntentFlow()->send();
    } catch (Throwable $throwable) {
        $failure = $throwable;
    } finally {
        $other->rollBack();
    }
    expect($failure)->not->toBeNull()
        ->and(TalktoMessage::query()->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('durable source connection mismatch is rejected before invoking the source action', function (): void {
    config(['talkto.dispatch_intents.enabled' => true,
        'database.connections.durable_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'talkto.database.connection' => 'durable_other']);
    $called = false;
    $failure = null;
    try {
        durableIntentFlow()->run(function () use (&$called): array {
            $called = true;

            return ['synthetic' => true];
        });
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }
    config(['talkto.database.connection' => null]);
    expect($failure)->not->toBeNull()->and($called)->toBeFalse()
        ->and(TalktoMessage::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('same connection publication rolls back the queue insert when its acknowledgement fence changes', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false]);
    durableIntentFlow()->send();
    $id = (int) DB::table('talkto_dispatch_intents')->value('id');
    TalktoDispatchTestHooks::set('dispatch_intent.before_ack', function (array $context): void {
        DB::table('talkto_dispatch_intents')->where('id', $context['intent_id'])->update(['claim_token' => '00000000-0000-4000-8000-000000000001']);
    });

    $result = app(TalktoDispatchIntentService::class)->publish($id);

    expect($result['published'])->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('claimed')
        ->and(DB::table('talkto_dispatch_intents')->value('last_error_code'))->toBe('atomic_enqueue_rolled_back');
});

test('same connection enqueue failure rolls back publication and becomes safely retryable after the claim lease', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false]);
    $message = durableIntentFlow()->send();
    $before = $message->fresh()->getRawOriginal();
    $id = (int) DB::table('talkto_dispatch_intents')->value('id');
    TalktoDispatchTestHooks::set('dispatch_intent.after_queue', fn () => throw new RuntimeException('Synthetic post-insert interruption.'));

    $failed = app(TalktoDispatchIntentService::class)->publish($id);
    expect($failed['published'])->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('claimed')
        ->and($message->fresh()->getRawOriginal())->toBe($before);

    TalktoDispatchTestHooks::reset();
    $this->travel(61)->seconds();
    try {
        expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
            ->and(DB::table('jobs')->count())->toBe(1)
            ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('published')
            ->and(DB::table('talkto_dispatch_intents')->value('publication_attempts'))->toBe(1);
    } finally {
        $this->travelBack();
    }
});

test('a separate database acceptance followed by lost acknowledgement remains unknown and is never republished', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false,
        'database.connections.durable_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'queue.connections.durable_database.connection' => 'durable_other']);
    durableIntentCreateJobs('durable_other');
    $message = durableIntentFlow()->send();
    $before = $message->fresh()->getRawOriginal();
    $id = (int) DB::table('talkto_dispatch_intents')->value('id');
    TalktoDispatchTestHooks::set('dispatch_intent.after_queue', fn () => throw new RuntimeException('Synthetic acknowledgement lost after actual enqueue.'));

    $result = app(TalktoDispatchIntentService::class)->publish($id);
    expect($result['published'])->toBeFalse()
        ->and(DB::connection('durable_other')->table('jobs')->count())->toBe(1)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('unknown')
        ->and($message->fresh()->getRawOriginal())->toBe($before);
    TalktoDispatchTestHooks::reset();
    $this->travel(3600)->seconds();
    try {
        expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
            ->and(DB::connection('durable_other')->table('jobs')->count())->toBe(1)
            ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('unknown')
            ->and(DB::table('talkto_dispatch_intents')->value('publication_attempts'))->toBe(1);
    } finally {
        $this->travelBack();
    }
});

test('an abandoned external publishing state is not recycled on lease expiry', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false,
        'database.connections.durable_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'queue.connections.durable_database.connection' => 'durable_other']);
    durableIntentCreateJobs('durable_other');
    durableIntentFlow()->send();
    DB::table('talkto_dispatch_intents')->update(['status' => 'publishing', 'claim_token' => '00000000-0000-4000-8000-000000000002',
        'claimed_at' => now()->subHours(2), 'claim_expires_at' => now()->subHour(), 'publication_started_at' => now()->subHours(2)]);

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::connection('durable_other')->table('jobs')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('publishing');
});

test('configured custom message event and intent models work on their named connection and tables', function (): void {
    config(['talkto.dispatch_intents.enabled' => true,
        'database.connections.durable_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'talkto.database.connection' => 'durable_other',
        'talkto.database.tables.messages' => 'durable_custom_messages',
        'talkto.database.tables.events' => 'durable_custom_events',
        'talkto.database.tables.dispatch_intents' => 'durable_custom_intents',
        'talkto.models.message' => DurableIntentCustomMessage::class,
        'talkto.models.event' => DurableIntentCustomEvent::class,
        'talkto.models.dispatch_intent' => DurableIntentCustomIntent::class,
        'queue.connections.durable_database.connection' => 'durable_other']);
    durableIntentMigrateConfiguredConnection();
    durableIntentCreateJobs('durable_other');
    $message = durableIntentFlow()->send();

    expect($message)->toBeInstanceOf(DurableIntentCustomMessage::class)
        ->and($message->getConnection()->getName())->toBe('durable_other')
        ->and(DB::connection('durable_other')->table('durable_custom_messages')->count())->toBe(1)
        ->and(DB::connection('durable_other')->table('durable_custom_events')->where('event_type', 'message_created')->count())->toBe(1)
        ->and(DB::connection('durable_other')->table('durable_custom_intents')->value('status'))->toBe('published')
        ->and(DB::connection('durable_other')->table('jobs')->count())->toBe(1)
        ->and(DB::connection('sqlite')->table('talkto_messages')->count())->toBe(0)
        ->and(DB::connection('sqlite')->table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::connection('sqlite')->table('jobs')->count())->toBe(0);
});

test('a message hidden by its global scope before publication is never dispatched', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false, 'talkto.models.message' => DurableIntentScopedMessage::class]);
    $message = durableIntentFlow()->businessKey('durable-visible')->send();
    DB::table('talkto_messages')->where('id', $message->id)->update(['business_key' => 'durable-hidden']);

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('cancelled')
        ->and(DB::table('talkto_messages')->where('id', $message->id)->value('overall_status'))->toBe('waiting_to_send');
});

test('custom queue names and delayed existing jobs retain their snapshotted publication routing', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.jobs.send_message' => DurableIntentDelayedSendJob::class]);
    $before = now()->getTimestamp();
    durableIntentFlow()->send();
    $intent = DB::table('talkto_dispatch_intents')->sole();
    $job = DB::table('jobs')->sole();

    expect($intent->job_class)->toBe(DurableIntentDelayedSendJob::class)
        ->and($intent->queue_connection)->toBe('durable_database')
        ->and($intent->queue_name)->toBe('custom-delayed')
        ->and($intent->status)->toBe('published')
        ->and($job->queue)->toBe('custom-delayed')
        ->and($job->available_at)->toBeGreaterThanOrEqual($before + 120)
        ->and(Queue::connection()->pop('custom-delayed'))->toBeNull();
});

test('post commit queue errors do not relabel a successful source action as failed', function (): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    TalktoDispatchTestHooks::set('dispatch_intent.before_claim', fn () => throw new RuntimeException('Synthetic process boundary before publication.'));
    $message = durableIntentFlow()->run(fn (): array => ['payload' => ['synthetic' => true], 'result' => ['source' => 'succeeded']]);

    expect($message->source_action_status)->toBe('succeeded')
        ->and($message->overall_status)->toBe('waiting_to_send')
        ->and(TalktoMessage::query()->count())->toBe(1)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('ready')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('talkto_messages')->where('source_action_status', 'failed')->count())->toBe(0);
});

test('a live claimed publisher excludes a competing publication before queue IO', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false]);
    durableIntentFlow()->send();
    $id = (int) DB::table('talkto_dispatch_intents')->value('id');
    $other = null;
    TalktoDispatchTestHooks::set('dispatch_intent.claimed', function () use ($id, &$other): void {
        $other = app(TalktoDispatchIntentService::class)->publish($id);
    });

    $first = app(TalktoDispatchIntentService::class)->publish($id);
    expect($first['published'])->toBeTrue()->and($other['published'])->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('talkto_dispatch_intents')->value('publication_attempts'))->toBe(1);
});

test('an old claimed publisher is fenced after an expired never attempted claim is replaced', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false]);
    durableIntentFlow()->send();
    $id = (int) DB::table('talkto_dispatch_intents')->value('id');
    $claims = 0;
    $newer = null;
    $tokens = [];
    TalktoDispatchTestHooks::set('dispatch_intent.claimed', function (array $context) use ($id, &$claims, &$newer, &$tokens): void {
        $tokens[] = $context['claim_token'];
        if (++$claims === 1) {
            DB::table('talkto_dispatch_intents')->where('id', $id)->update(['claim_expires_at' => now()->subSecond()]);
            $newer = app(TalktoDispatchIntentService::class)->publish($id);
        }
    });

    $old = app(TalktoDispatchIntentService::class)->publish($id);
    expect($old['published'])->toBeFalse()->and($newer['published'])->toBeTrue()
        ->and($tokens)->toHaveCount(2)->and($tokens[0])->not->toBe($tokens[1])
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('published')
        ->and(DB::table('talkto_dispatch_intents')->value('publication_attempts'))->toBe(1);
});

test('a verified successful callback after claim prevents publication and preserves terminal message fields', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false]);
    $message = durableIntentFlow()->send();
    $id = (int) DB::table('talkto_dispatch_intents')->value('id');
    $terminal = null;
    TalktoDispatchTestHooks::set('dispatch_intent.claimed', function () use ($message, &$terminal): void {
        $accepted = durableIntentSuccessCallback($message);
        expect($accepted)->toMatchArray(['accepted' => true, 'status' => 'applied']);
        $terminal = $message->fresh()->getRawOriginal();
    });

    $result = app(TalktoDispatchIntentService::class)->publish($id);
    expect($result['published'])->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($message->fresh()->getRawOriginal())->toBe($terminal)
        ->and($message->fresh()->overall_status)->toBe('completed')
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('cancelled');
});

test('a separate queue connection is called only with all database transactions closed', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false,
        'database.connections.durable_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'queue.connections.durable_database.connection' => 'durable_other']);
    durableIntentCreateJobs('durable_other');
    durableIntentFlow()->send();
    $id = (int) DB::table('talkto_dispatch_intents')->value('id');
    $observed = false;
    TalktoDispatchTestHooks::set('dispatch_intent.publishing', function () use (&$observed): void {
        $observed = true;
        expect(DB::connection()->transactionLevel())->toBe(0)
            ->and(DB::connection('durable_other')->transactionLevel())->toBe(0)
            ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('publishing');
    });

    $result = app(TalktoDispatchIntentService::class)->publish($id);
    expect($observed)->toBeTrue()->and($result['published'])->toBeTrue()
        ->and(DB::connection('durable_other')->table('jobs')->count())->toBe(1)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('published');
});

test('a delayed external acknowledgement cannot overwrite a newer fenced outcome', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false,
        'database.connections.durable_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'queue.connections.durable_database.connection' => 'durable_other']);
    durableIntentCreateJobs('durable_other');
    durableIntentFlow()->send();
    $id = (int) DB::table('talkto_dispatch_intents')->value('id');
    TalktoDispatchTestHooks::set('dispatch_intent.before_ack', function (array $context): void {
        DB::table('talkto_dispatch_intents')->where('id', $context['intent_id'])->update([
            'status' => 'unknown', 'claim_token' => '00000000-0000-4000-8000-000000000003', 'last_error_code' => 'newer-operator-outcome',
        ]);
    });

    $result = app(TalktoDispatchIntentService::class)->publish($id);
    expect($result['published'])->toBeFalse()->and($result['reason'])->toBe('fenced_acknowledgement')
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('unknown')
        ->and(DB::table('talkto_dispatch_intents')->value('last_error_code'))->toBe('newer-operator-outcome')
        ->and(DB::connection('durable_other')->table('jobs')->count())->toBe(1);
    TalktoDispatchTestHooks::reset();
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::connection('durable_other')->table('jobs')->count())->toBe(1);
});

test('a custom key differing from the legacy pipeline id fails closed before committing publication state', function (): void {
    Schema::table('talkto_messages', function (Blueprint $table): void {
        $table->unsignedInteger('legacy_key')->nullable()->unique();
    });
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.models.message' => DurableIntentNumericKeyMessage::class]);

    expect(fn () => durableIntentFlow()->send())->toThrow(RuntimeException::class)
        ->and(DB::table('talkto_messages')->count())->toBe(0)
        ->and(DB::table('talkto_events')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a canonical integer custom message key matching its legacy id supports the existing job contract', function (): void {
    Schema::table('talkto_messages', function (Blueprint $table): void {
        $table->unsignedInteger('legacy_key')->nullable()->unique();
    });
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.models.message' => DurableIntentMatchingNumericKeyMessage::class]);
    $message = durableIntentFlow()->send();
    $intent = DB::table('talkto_dispatch_intents')->sole();
    expect($intent->message_key_name)->toBe('legacy_key')->and($intent->message_key)->toBe('73')
        ->and($intent->status)->toBe('published');
    $client = new DurableIntentHttpClient;
    app()->instance(TalktoHttpClient::class, $client);
    $job = Queue::connection()->pop('durable-probe');
    $job->fire();
    $job->delete();
    expect($client->calls)->toBe(1)
        ->and(DurableIntentMatchingNumericKeyMessage::query()->whereKey(73)->sole()->overall_status)->toBe('destination_received')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($message->getKey())->toBe(73);
});

test('a nonintegral message key fails closed rather than silently casting its existing job argument', function (): void {
    Schema::table('talkto_messages', function (Blueprint $table): void {
        $table->string('legacy_key')->nullable()->unique();
    });
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.models.message' => DurableIntentStringKeyMessage::class]);

    expect(fn () => durableIntentFlow()->send())->toThrow(RuntimeException::class, 'integer primary key')
        ->and(DB::table('talkto_messages')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a changed configured job class does not silently reroute a ready intent', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false]);
    durableIntentFlow()->send();
    config(['talkto.jobs.send_message' => DurableIntentCustomSendJob::class]);

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('ready');
});

test('a fault at either intent persistence boundary rolls back an accepted incoming or outgoing message', function (string $direction, string $boundary): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    TalktoDispatchTestHooks::set($boundary, function (): void {
        throw new RuntimeException('Synthetic intent persistence interruption.');
    });
    $action = $direction === 'outgoing'
        ? fn () => durableIntentFlow()->send()
        : fn () => app(ReceiveIncomingTalktoMessagePipeline::class)->receive(durableIntentRequest('interrupted-incoming'), app(TalktoSignatureVerifier::class));

    expect($action)->toThrow(RuntimeException::class, 'Synthetic intent persistence interruption.')
        ->and(DB::table('talkto_messages')->count())->toBe(0)
        ->and(DB::table('talkto_events')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'outgoing before intent insert' => ['outgoing', 'dispatch_intent.before_record'],
    'outgoing after intent insert' => ['outgoing', 'dispatch_intent.recorded'],
    'incoming before intent insert' => ['incoming', 'dispatch_intent.before_record'],
    'incoming after intent insert' => ['incoming', 'dispatch_intent.recorded'],
]);

test('enabled failed source work rolls back and its failure audit never acquires an initial intent', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    Schema::create('durable_source_probe', fn (Blueprint $table) => $table->string('value'));
    expect(fn () => durableIntentFlow()->run(function (): never {
        DB::table('durable_source_probe')->insert(['value' => 'rolled-back']);
        throw new RuntimeException('Synthetic durable source failure.');
    }))->toThrow(RuntimeException::class, 'Synthetic durable source failure.');

    expect(DB::table('durable_source_probe')->count())->toBe(0)
        ->and(TalktoMessage::query()->sole()->source_action_status)->toBe('failed')
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('manual recovery rejects malformed or unbounded filters without mutating intents', function (array $filters): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false]);
    durableIntentFlow()->send();
    $before = (array) DB::table('talkto_dispatch_intents')->sole();

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true] + $filters))->toBe(1)
        ->and((array) DB::table('talkto_dispatch_intents')->sole())->toBe($before)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'invalid direction' => [['--direction' => 'sideways']],
    'noninteger age' => [['--min-age' => '1.5']],
    'negative age' => [['--min-age' => -1]],
    'zero limit' => [['--limit' => 0]],
    'unbounded limit' => [['--limit' => 1001]],
]);

test('manual recovery obeys direction message identity age and limit filters', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false]);
    $first = durableIntentFlow()->option('message_id', 'filtered-outgoing')->send();
    durableIntentFlow()->option('message_id', 'other-outgoing')->send();
    app(ReceiveIncomingTalktoMessagePipeline::class)->receive(durableIntentRequest('filtered-incoming'), app(TalktoSignatureVerifier::class));
    $service = app(TalktoDispatchIntentService::class);

    expect($service->inspect(minimumAgeMinutes: 30)['count'])->toBe(0)
        ->and($service->inspect(direction: 'outgoing', minimumAgeMinutes: 0, limit: 1)['count'])->toBe(1)
        ->and($service->inspect(messageId: 'filtered-incoming', minimumAgeMinutes: 0)['candidates'][0]['direction'])->toBe('incoming');
    $report = $service->execute(direction: 'outgoing', messageId: $first->message_id, minimumAgeMinutes: 0, limit: 1);
    expect($report['dispatched'])->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('talkto_dispatch_intents')->where('status', 'ready')->count())->toBe(2);
});

test('enabled incoming routing retains its configured existing job subclass and queue', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.jobs.process_incoming' => DurableIntentCustomIncomingJob::class]);
    $response = app(ReceiveIncomingTalktoMessagePipeline::class)->receive(durableIntentRequest('enabled-custom-incoming'), app(TalktoSignatureVerifier::class));
    $intent = DB::table('talkto_dispatch_intents')->sole();
    $job = Queue::connection()->pop('custom-incoming');

    expect($response->getStatusCode())->toBe(202)
        ->and($intent->job_class)->toBe(DurableIntentCustomIncomingJob::class)
        ->and($intent->queue_name)->toBe('custom-incoming')
        ->and($intent->status)->toBe('published')
        ->and($job->resolveName())->toBe(DurableIntentCustomIncomingJob::class);
    $job->delete();
});

test('a model failure before the message insert creates no intent or executable publication', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.models.message' => DurableIntentFailingMessage::class]);

    expect(fn () => durableIntentFlow()->send())->toThrow(RuntimeException::class, 'Synthetic pre-message interruption.')
        ->and(DB::table('talkto_messages')->count())->toBe(0)
        ->and(DB::table('talkto_events')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('an external queue acknowledgement after a verified completion cannot downgrade the message or repeat its HTTP side effect', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false,
        'database.connections.durable_other' => config('database.connections.sqlite'),
        'queue.connections.durable_external' => ['driver' => 'database', 'connection' => 'durable_other', 'table' => 'jobs', 'queue' => 'durable-probe', 'retry_after' => 90, 'after_commit' => false],
        'queue.default' => 'durable_external']);
    durableIntentCreateJobs('durable_other');
    $message = durableIntentFlow()->send();
    $terminalSnapshot = null;
    TalktoDispatchTestHooks::set('dispatch_intent.before_ack', function () use ($message, &$terminalSnapshot): void {
        expect(durableIntentSuccessCallback($message))->toMatchArray(['accepted' => true, 'status' => 'applied']);
        $terminalSnapshot = $message->fresh()->getRawOriginal();
    });

    $result = app(TalktoDispatchIntentService::class)->publish((int) DB::table('talkto_dispatch_intents')->value('id'));
    $client = new DurableIntentHttpClient;
    app()->instance(TalktoHttpClient::class, $client);
    $job = Queue::connection('durable_external')->pop('durable-probe');
    $job->fire();
    $job->delete();

    expect($result['published'])->toBeTrue()
        ->and($message->fresh()->overall_status)->toBe('completed')
        ->and($message->fresh()->getRawOriginal())->toBe($terminalSnapshot)
        ->and($client->calls)->toBe(0)
        ->and(DB::connection('durable_other')->table('jobs')->count())->toBe(0);
});

test('published history cannot starve a safely recoverable intent when the execution limit is one', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    durableIntentFlow()->option('message_id', 'older-published-history')->send();
    DB::table('talkto_dispatch_intents')->update(['created_at' => now()->subHour()]);
    config(['talkto.dispatch_intents.publish_after_commit' => false]);
    $message = durableIntentFlow()->option('message_id', 'newer-ready-intent')->send();

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0, '--limit' => 1, '--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['dispatched'])->toBe(1)
        ->and($report['candidates'][0]['message_id'])->toBe($message->message_id)
        ->and(DB::table('talkto_dispatch_intents')->where('status', 'published')->count())->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(2);
});

test('a freshly locked intent cannot publish stale ownership routing or message binding after claim', function (string $column, string $value): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false]);
    durableIntentFlow()->send();
    $id = (int) DB::table('talkto_dispatch_intents')->value('id');
    $changed = null;
    TalktoDispatchTestHooks::set('dispatch_intent.claimed', function () use ($id, $column, $value, &$changed): void {
        DB::table('talkto_dispatch_intents')->where('id', $id)->update([$column => $value]);
        $changed = (array) DB::table('talkto_dispatch_intents')->where('id', $id)->sole();
    });

    $result = app(TalktoDispatchIntentService::class)->publish($id);
    expect($result['published'])->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and((array) DB::table('talkto_dispatch_intents')->where('id', $id)->sole())->toBe($changed);
})->with([
    'intent service owner changed' => ['owner', 'other-service'],
    'intent queue changed' => ['queue_name', 'other-queue'],
    'intent public message binding changed' => ['message_id', 'other-message-id'],
]);

test('unsupported scoped or differently keyed intent models fail before message persistence', function (string $model): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.models.dispatch_intent' => $model]);

    expect(fn () => durableIntentFlow()->send())->toThrow(RuntimeException::class)
        ->and(DB::table('talkto_messages')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'state filtering global scope' => [DurableIntentScopedIntent::class],
    'different intent primary key' => [DurableIntentDifferentKeyIntent::class],
]);

test('an advanced transport field makes a ready initial intent ineligible without changing the message', function (array $attributes): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false]);
    $message = durableIntentFlow()->send();
    $message->forceFill($attributes)->save();
    $before = $message->fresh()->getRawOriginal();

    $result = app(TalktoDispatchIntentService::class)->publish((int) DB::table('talkto_dispatch_intents')->value('id'));
    expect($result['published'])->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($message->fresh()->getRawOriginal())->toBe($before);
})->with([
    'previous transport attempt' => [['attempts' => 1]],
    'previous attempted timestamp' => [fn () => ['last_attempted_at' => now()->subMinute()]],
    'previous acknowledged transport' => [fn () => ['sent_at' => now()->subMinute()]],
]);

test('inherited or trait queue attributes require an explicit durable adapter before message persistence', function (string $jobClass): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.jobs.send_message' => $jobClass]);
    expect((new ReflectionClass($jobClass))->getAttributes())->toHaveCount(0);

    expect(fn () => durableIntentFlow()->send())->toThrow(RuntimeException::class, 'Queue routing attributes')
        ->and(DB::table('talkto_messages')->count())->toBe(0)
        ->and(DB::table('talkto_events')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'attribute declared on an intermediate parent' => [DurableIntentInheritedAttributeJob::class],
    'attribute declared on a recursively used trait' => [DurableIntentTraitAttributeJob::class],
]);

test('native queue route mappings require an explicit durable adapter when the framework provides them', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.jobs.send_message' => DurableIntentCustomSendJob::class]);
    $manager = Queue::getFacadeRoot();
    if (! method_exists($manager, 'route')) {
        expect(app()->bound('queue.routes'))->toBeFalse();
        durableIntentFlow()->send();
        expect(DB::table('talkto_dispatch_intents')->value('status'))->toBe('published')
            ->and(DB::table('jobs')->where('queue', 'custom-send')->count())->toBe(1);

        return;
    }
    $manager->route(SendTalktoMessage::class, 'mapped-parent-queue', 'durable_database');
    expect(app('queue.routes')->getRoute(new DurableIntentCustomSendJob(1)))->not->toBeNull();

    expect(fn () => durableIntentFlow()->send())->toThrow(RuntimeException::class, 'Queue route mappings')
        ->and(DB::table('talkto_messages')->count())->toBe(0)
        ->and(DB::table('talkto_events')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a database connector returning an actual sync queue cannot execute a durable job inline', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    Queue::extend('database', fn () => new SyncConnector);
    expect(Queue::connection('durable_database'))->toBeInstanceOf(SyncQueue::class);
    $client = new DurableIntentHttpClient;
    app()->instance(TalktoHttpClient::class, $client);

    $message = durableIntentFlow()->send();
    $before = $message->fresh()->getRawOriginal();
    expect($message->overall_status)->toBe('waiting_to_send')
        ->and($client->calls)->toBe(0)
        ->and(DurableIntentCountingHandler::$calls)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('ready')
        ->and(DB::table('talkto_dispatch_intents')->value('last_error_code'))->toBe('queue_preflight_failed')
        ->and(DB::table('talkto_dispatch_intents')->value('publication_attempts'))->toBe(0)
        ->and(DB::table('talkto_dispatch_intents')->value('claim_token'))->toBeNull()
        ->and(DB::table('talkto_dispatch_intents')->value('publication_started_at'))->toBeNull();

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0, '--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['dispatched'])->toBe(0)
        ->and($report['candidates'][0]['result']['reason'])->toBe('queue_preflight_failed')
        ->and($client->calls)->toBe(0)
        ->and(DurableIntentCountingHandler::$calls)->toBe(0)
        ->and($message->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('talkto_dispatch_intents')->value('status'))->toBe('ready')
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('switching the configured database during intent persistence rolls back the captured transaction', function (string $boundary): void {
    config(['talkto.dispatch_intents.enabled' => true]);
    durableIntentPrepareOtherDatabase();
    $otherWrites = [];
    DB::listen(function (QueryExecuted $query) use (&$otherWrites): void {
        if ($query->connectionName === 'durable_other' && preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
            $otherWrites[] = $query->sql;
        }
    });
    TalktoDispatchTestHooks::set($boundary, fn () => config(['talkto.database.connection' => 'durable_other']));

    expect(fn () => durableIntentFlow()->send())->toThrow(RuntimeException::class)
        ->and(DB::connection('sqlite')->table('talkto_messages')->count())->toBe(0)
        ->and(DB::connection('sqlite')->table('talkto_events')->count())->toBe(0)
        ->and(DB::connection('sqlite')->table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::connection('sqlite')->table('jobs')->count())->toBe(0)
        ->and(DB::connection('durable_other')->table('talkto_messages')->count())->toBe(0)
        ->and(DB::connection('durable_other')->table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::connection('durable_other')->table('jobs')->count())->toBe(0)
        ->and($otherWrites)->toBe([]);
})->with(['before intent insert' => ['dispatch_intent.before_record'], 'after intent insert' => ['dispatch_intent.recorded']]);

test('switching the configured database after claim cannot push or update another database', function (string $boundary): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false]);
    durableIntentPrepareOtherDatabase();
    durableIntentFlow()->send();
    $id = (int) DB::connection('sqlite')->table('talkto_dispatch_intents')->value('id');
    $otherWrites = [];
    DB::listen(function (QueryExecuted $query) use (&$otherWrites): void {
        if ($query->connectionName === 'durable_other' && preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
            $otherWrites[] = $query->sql;
        }
    });
    TalktoDispatchTestHooks::set($boundary, fn () => config(['talkto.database.connection' => 'durable_other']));

    expect(fn () => app(TalktoDispatchIntentService::class)->publish($id))->toThrow(RuntimeException::class)
        ->and(DB::connection('sqlite')->table('talkto_dispatch_intents')->value('status'))->toBe('claimed')
        ->and(DB::connection('sqlite')->table('talkto_dispatch_intents')->value('publication_attempts'))->toBe(0)
        ->and(DB::connection('sqlite')->table('jobs')->count())->toBe(0)
        ->and(DB::connection('durable_other')->table('talkto_messages')->count())->toBe(0)
        ->and(DB::connection('durable_other')->table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::connection('durable_other')->table('jobs')->count())->toBe(0)
        ->and($otherWrites)->toBe([]);
})->with(['after claim' => ['dispatch_intent.claimed'], 'before publication' => ['dispatch_intent.before_publish']]);

test('switching the configured database before an atomic acknowledgement rolls back its original queue insertion', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false]);
    durableIntentPrepareOtherDatabase();
    durableIntentFlow()->send();
    $id = (int) DB::connection('sqlite')->table('talkto_dispatch_intents')->value('id');
    $otherWrites = [];
    DB::listen(function (QueryExecuted $query) use (&$otherWrites): void {
        if ($query->connectionName === 'durable_other' && preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
            $otherWrites[] = $query->sql;
        }
    });
    TalktoDispatchTestHooks::set('dispatch_intent.before_ack', fn () => config(['talkto.database.connection' => 'durable_other']));

    $result = app(TalktoDispatchIntentService::class)->publish($id);
    expect($result['published'])->toBeFalse()
        ->and($result['reason'])->toBe('atomic_enqueue_rolled_back')
        ->and(DB::connection('sqlite')->table('talkto_dispatch_intents')->value('status'))->toBe('claimed')
        ->and(DB::connection('sqlite')->table('talkto_dispatch_intents')->value('publication_attempts'))->toBe(0)
        ->and(DB::connection('sqlite')->table('jobs')->count())->toBe(0)
        ->and(DB::connection('durable_other')->table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::connection('durable_other')->table('jobs')->count())->toBe(0)
        ->and($otherWrites)->toBe([]);
});

test('switching the configured database after external acceptance preserves publishing evidence without a wrong database acknowledgement', function (): void {
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false]);
    durableIntentPrepareOtherDatabase();
    config(['database.connections.durable_broker' => config('database.connections.sqlite'),
        'queue.connections.durable_external' => ['driver' => 'database', 'connection' => 'durable_broker', 'table' => 'jobs', 'queue' => 'durable-probe', 'retry_after' => 90, 'after_commit' => false],
        'queue.default' => 'durable_external']);
    durableIntentCreateJobs('durable_broker');
    durableIntentFlow()->send();
    $id = (int) DB::connection('sqlite')->table('talkto_dispatch_intents')->value('id');
    $otherWrites = [];
    DB::listen(function (QueryExecuted $query) use (&$otherWrites): void {
        if ($query->connectionName === 'durable_other' && preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
            $otherWrites[] = $query->sql;
        }
    });
    TalktoDispatchTestHooks::set('dispatch_intent.before_ack', fn () => config(['talkto.database.connection' => 'durable_other']));

    $result = app(TalktoDispatchIntentService::class)->publish($id);
    expect($result['published'])->toBeFalse()
        ->and($result['reason'])->toBe('publication_outcome_unknown')
        ->and(DB::connection('sqlite')->table('talkto_dispatch_intents')->value('status'))->toBe('publishing')
        ->and(DB::connection('sqlite')->table('talkto_dispatch_intents')->value('publication_attempts'))->toBe(1)
        ->and(DB::connection('sqlite')->table('jobs')->count())->toBe(0)
        ->and(DB::connection('durable_broker')->table('jobs')->count())->toBe(1)
        ->and(DB::connection('durable_other')->table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::connection('durable_other')->table('jobs')->count())->toBe(0)
        ->and($otherWrites)->toBe([]);

    TalktoDispatchTestHooks::reset();
    config(['talkto.database.connection' => null]);
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::connection('durable_broker')->table('jobs')->count())->toBe(1)
        ->and(DB::connection('sqlite')->table('talkto_dispatch_intents')->value('status'))->toBe('publishing');
});

test('purging the configured database after a committed claim cannot publish through a replaced connection object', function (): void {
    $databasePath = tempnam(sys_get_temp_dir(), 'talkto-durable-purge-');
    $originalTalktoConnection = config('talkto.database.connection');
    $originalQueue = config('queue.default');
    config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false,
        'database.connections.durable_purge' => ['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '', 'foreign_key_constraints' => true],
        'talkto.database.connection' => 'durable_purge',
        'queue.connections.durable_purge_queue' => ['driver' => 'database', 'connection' => 'durable_purge', 'table' => 'jobs', 'queue' => 'durable-probe', 'retry_after' => 90, 'after_commit' => false],
        'queue.default' => 'durable_purge_queue']);
    try {
        durableIntentMigrateConfiguredConnection();
        durableIntentCreateJobs('durable_purge');
        durableIntentFlow()->send();
        $captured = DB::connection('durable_purge');
        $id = (int) $captured->table('talkto_dispatch_intents')->value('id');
        TalktoDispatchTestHooks::set('dispatch_intent.claimed', fn () => DB::purge('durable_purge'));

        expect(fn () => app(TalktoDispatchIntentService::class)->publish($id))->toThrow(RuntimeException::class)
            ->and(DB::connection('durable_purge'))->not->toBe($captured)
            ->and(DB::connection('durable_purge')->table('talkto_messages')->count())->toBe(1)
            ->and(DB::connection('durable_purge')->table('talkto_dispatch_intents')->value('status'))->toBe('claimed')
            ->and(DB::connection('durable_purge')->table('talkto_dispatch_intents')->value('publication_attempts'))->toBe(0)
            ->and(DB::connection('durable_purge')->table('jobs')->count())->toBe(0);
    } finally {
        TalktoDispatchTestHooks::reset();
        DB::purge('durable_purge');
        config(['talkto.database.connection' => $originalTalktoConnection, 'queue.default' => $originalQueue]);
        unlink($databasePath);
    }
});

function durableIntentFlow(): TalktoFlowBuilder
{
    return (new TalktoFlowBuilder('durable-probe', app(TalktoOutgoingMessageFactory::class)))
        ->to('peer')->command('domain.command')->payload(['synthetic' => true]);
}

function durableIntentCreateJobs(string $connection): void
{
    Schema::connection($connection)->create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

function durableIntentMigrateConfiguredConnection(): void
{
    $migrations = glob(__DIR__.'/../../database/migrations/*.php');
    sort($migrations);
    foreach (array_merge($migrations, glob(__DIR__.'/../../database/dispatch-intents/*.php')) as $migration) {
        (require $migration)->up();
    }
}

function durableIntentPrepareOtherDatabase(): void
{
    $originalConnection = config('talkto.database.connection');
    config(['database.connections.durable_other' => config('database.connections.sqlite'), 'talkto.database.connection' => 'durable_other']);
    try {
        durableIntentMigrateConfiguredConnection();
        durableIntentCreateJobs('durable_other');
    } finally {
        config(['talkto.database.connection' => $originalConnection]);
    }
}

function durableIntentRequest(string $messageId, array $overrides = [], ?string $nonce = null, bool $badSignature = false): Request
{
    $envelope = array_replace([
        'message_id' => $messageId, 'source' => 'peer', 'target' => 'testing', 'command' => 'domain.command',
        'payload' => ['synthetic' => true],
    ], $overrides);
    $envelope['payload_hash'] = app(TalktoPayloadHasher::class)->hash($envelope['payload']);
    $timestamp = gmdate(DATE_ATOM);
    $nonce ??= 'durable-nonce-'.bin2hex(random_bytes(10));
    $signature = app(TalktoSigner::class)->signV2($timestamp, $nonce, $envelope['message_id'], $envelope['source'], $envelope['target'], $envelope['command'], $envelope['payload_hash'], 'synthetic-durable-intent-secret');
    $request = Request::create('/api/talkto/receive', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($envelope, JSON_THROW_ON_ERROR));
    $request->headers->add([
        'X-Talkto-Signature-Version' => 'v2', 'X-Talkto-Signature' => $badSignature ? 'invalid-signature' : $signature,
        'X-Talkto-Timestamp' => $timestamp, 'X-Talkto-Nonce' => $nonce,
        'X-Talkto-Message-Id' => $envelope['message_id'], 'X-Talkto-Payload-Hash' => $envelope['payload_hash'],
    ]);

    return $request;
}

function durableIntentSuccessCallback(TalktoMessage $message): array
{
    $payload = ['original_message_id' => $message->message_id, 'original_command' => $message->command,
        'status' => 'succeeded', 'succeeded' => true, 'retryable' => false, 'skipped' => false,
        'result' => ['synthetic' => true], 'meta' => ['fixture' => 'durable-intent']];
    $envelope = ['message_id' => 'durable-callback-'.bin2hex(random_bytes(8)), 'parent_message_id' => $message->message_id,
        'source' => 'peer', 'target' => 'testing', 'command' => 'talkto.result', 'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload)];
    $timestamp = gmdate(DATE_ATOM);
    $nonce = 'durable-callback-nonce-'.bin2hex(random_bytes(8));
    $signature = app(TalktoSigner::class)->signV2($timestamp, $nonce, $envelope['message_id'], 'peer', 'testing', 'talkto.result', $envelope['payload_hash'], 'synthetic-durable-intent-secret');

    return app(TalktoResultCallbackReceiver::class)->receiveResult($envelope, [
        'X-Talkto-Signature-Version' => 'v2', 'X-Talkto-Signature' => $signature,
        'X-Talkto-Timestamp' => $timestamp, 'X-Talkto-Nonce' => $nonce,
        'X-Talkto-Message-Id' => $envelope['message_id'], 'X-Talkto-Payload-Hash' => $envelope['payload_hash'],
    ]);
}

class DurableIntentCustomSendJob extends SendTalktoMessage
{
    public function __construct(int $messageId)
    {
        parent::__construct($messageId);
        $this->onQueue('custom-send');
    }
}

#[Illuminate\Queue\Attributes\Queue('unsupported-parent-queue')]
class DurableIntentAttributedParentJob extends SendTalktoMessage {}

class DurableIntentInheritedAttributeJob extends DurableIntentAttributedParentJob {}

#[Illuminate\Queue\Attributes\Queue('unsupported-trait-queue')]
trait DurableIntentAttributedQueueTrait {}

trait DurableIntentNestedAttributedQueueTrait
{
    use DurableIntentAttributedQueueTrait;
}

class DurableIntentTraitAttributeJob extends SendTalktoMessage
{
    use DurableIntentNestedAttributedQueueTrait;
}

class DurableIntentCustomIncomingJob extends ProcessIncomingTalktoMessage
{
    public function __construct(int $messageId)
    {
        parent::__construct($messageId);
        $this->onQueue('custom-incoming');
    }
}

class DurableIntentHttpClient implements TalktoHttpClient
{
    public int $calls = 0;

    public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
    {
        $this->calls++;

        return new TalktoHttpResponse(200, '{"received":true,"status":"queued"}');
    }
}

class DurableIntentCountingHandler implements TalktoIncomingCommandHandler
{
    public static int $calls = 0;

    public function handle(TalktoMessage $message): IncomingCommandResultContract
    {
        self::$calls++;

        return TalktoIncomingCommandResult::succeeded(['synthetic' => true]);
    }
}

class DurableIntentCustomMessage extends TalktoMessage {}

class DurableIntentCustomEvent extends TalktoEvent {}

class DurableIntentCustomIntent extends TalktoDispatchIntent {}

class DurableIntentScopedIntent extends TalktoDispatchIntent
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('only-ready', fn (Builder $query) => $query->where('status', 'ready'));
    }
}

class DurableIntentDifferentKeyIntent extends TalktoDispatchIntent
{
    protected $primaryKey = 'ledger_key';
}

class DurableIntentFailingMessage extends TalktoMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (): never {
            throw new RuntimeException('Synthetic pre-message interruption.');
        });
    }
}

class DurableIntentScopedMessage extends TalktoMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('durable-visible', fn (Builder $query) => $query->where('business_key', 'durable-visible'));
    }
}

class DurableIntentDelayedSendJob extends SendTalktoMessage
{
    public function __construct(int $messageId)
    {
        parent::__construct($messageId);
        $this->onQueue('custom-delayed')->delay(120)->afterCommit();
    }
}

class DurableIntentNumericKeyMessage extends TalktoMessage
{
    protected $primaryKey = 'legacy_key';

    public $incrementing = false;

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $message): void {
            $message->setAttribute('legacy_key', 73);
        });
    }
}

class DurableIntentMatchingNumericKeyMessage extends DurableIntentNumericKeyMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $message): void {
            $message->setAttribute('id', 73);
        });
    }
}

class DurableIntentStringKeyMessage extends TalktoMessage
{
    protected $primaryKey = 'legacy_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $message): void {
            $message->setAttribute('legacy_key', 'nonintegral-synthetic-key');
        });
    }
}
