<?php

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData;
use Mrezdev\LaravelTalkto\Exceptions\TalktoReliableCallbackPersistenceException;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDispatchIntent;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Pipelines\ProcessIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoDispatchClaimingService;
use Mrezdev\LaravelTalkto\Services\TalktoFlowBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResolver;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackSender;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;

beforeEach(function (): void {
    ReliableCallbackFeatureProbe::$handlerCalls = 0;
    ReliableCallbackFeatureProbe::$senderCalls = 0;
    ReliableCallbackFeatureProbe::$resultCalls = [];
    ReliableCallbackFeatureProbe::$constructorCalls = 0;
    ReliableCallbackFeatureProbe::$factoryResolverCalls = 0;
    ReliableCallbackFeatureProbe::$cancelSaveWhen = null;
    config([
        'talkto.service' => 'target-service',
        'talkto.callbacks.enabled' => true,
        'talkto.callbacks.auto_dispatch' => true,
        'talkto.dispatch_intents.enabled' => false,
        'talkto.outgoing.source-service' => ['url' => 'https://reliable-callback.invalid', 'secret' => 'synthetic-callback-fixture-secret'],
        'talkto.retry.incoming_enabled' => false,
        'queue.default' => 'callback_database',
        'queue.connections.callback_database' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'callback-probe', 'retry_after' => 90, 'after_commit' => false],
    ]);
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
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
    DB::purge('reliable_callback_other');
    DB::purge('reliable_callback_source');
    DB::purge('reliable_callback_broker');
});

test('legacy callback gap leaves a committed success when callback creation is interrupted', function (): void {
    app()->instance(ResultCallbackSenderContract::class, new class implements ResultCallbackSenderContract
    {
        public function sendResult(Model $message, IncomingCommandResultContract $result, array $options = []): mixed
        {
            ReliableCallbackFeatureProbe::$senderCalls++;
            throw new RuntimeException('synthetic post-result callback interruption');
        }
    });
    $incoming = reliableCallbackIncoming('legacy-gap');
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process(
        $incoming->id,
        reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['saved' => true], ['nested' => ['value' => 'persisted']]))
    ))->toThrow(RuntimeException::class, 'synthetic post-result callback interruption');

    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and(ReliableCallbackFeatureProbe::$senderCalls)->toBe(1)
        ->and($incoming->fresh()->overall_status)->toBe('succeeded')
        ->and($incoming->fresh()->completed_at)->not->toBeNull()
        ->and(TalktoAttempt::query()->sole()->status)->toBe('succeeded')
        ->and(TalktoAttempt::query()->sole()->meta)->toMatchArray(['result' => ['saved' => true]])
        ->and(TalktoEvent::query()->where('event_type', 'destination_processing_succeeded')->count())->toBe(1)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(Schema::hasTable('talkto_dispatch_intents'))->toBeFalse();
});

test('reliable callback flags are disabled by default without optional ledger storage', function (): void {
    expect(config('talkto.callbacks.reliable.enabled'))->toBeFalse()
        ->and(config('talkto.callbacks.reliable.recovery_enabled'))->toBeFalse()
        ->and(config('talkto.dispatch_intents.enabled'))->toBeFalse()
        ->and(Schema::hasTable('talkto_dispatch_intents'))->toBeFalse();
});

test('reliable result and callback preserve each reported outcome without reconstructing attempt metadata', function (string $kind, string $messageStatus, string $callbackStatus, bool $retryable): void {
    reliableCallbackEnable();
    config(['talkto.retry.incoming_enabled' => true]);
    $incoming = reliableCallbackIncoming('result-'.$kind, $kind === 'exhausted' ? ['retry_count' => 4] : []);
    $result = match ($kind) {
        'succeeded' => TalktoIncomingCommandResult::succeeded(['processed' => true, 'unicode' => 'سلام 世界 🌍', 'empty' => []], ['nested' => ['empty' => [], 'value' => null]]),
        'skipped' => TalktoIncomingCommandResult::skipped('already applied', ['unicode' => 'سلام']),
        'retryable', 'exhausted' => new TalktoIncomingCommandResult(false, true, 'temporary_failure', 'synthetic retryable failure', ['partial' => true], ['phase_value' => 'retry']),
        'failed' => new TalktoIncomingCommandResult(false, false, null, null, [], []),
    };
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver($result));
    $callback = reliableCallbackMessage($incoming);
    $intent = TalktoDispatchIntent::query()->sole();

    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and($incoming->fresh()->overall_status)->toBe($messageStatus)
        ->and($callback->payload)->toBe(TalktoResultCallbackData::fromIncomingMessageResult($incoming->fresh(), $result)->toPayload())
        ->and($callback->payload['status'])->toBe($callbackStatus)
        ->and($callback->payload['retryable'])->toBe($retryable)
        ->and($callback->message_id)->toBe('cb-'.sha1($incoming->message_id.'|'.$callbackStatus))
        ->and($callback->idempotency_key)->toBe('talkto:callback:'.$incoming->message_id.':'.$callbackStatus)
        ->and($callback->correlation_id)->toBe($incoming->correlation_id)
        ->and($callback->parent_message_id)->toBe($incoming->message_id)
        ->and($callback->business_key)->toBe($incoming->business_key)
        ->and($callback->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($callback->payload))
        ->and($intent->message_id)->toBe($callback->message_id)
        ->and($intent->status)->toBe('ready')
        ->and($intent->job_class)->toBe(SendTalktoMessage::class)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'success' => ['succeeded', 'succeeded', 'succeeded', false],
    'skipped' => ['skipped', 'skipped', 'skipped', false],
    'retryable failure' => ['retryable', 'failed_retryable', 'failed_retryable', true],
    'final failure' => ['failed', 'failed_final', 'failed_final', false],
    'retry exhausted preserves reported retryability' => ['exhausted', 'failed_final', 'failed_retryable', true],
]);

test('unexpected handler exceptions preserve existing sanitized callback retry policy', function (bool $exhausted): void {
    reliableCallbackEnable();
    config(['talkto.retry.incoming_enabled' => true]);
    $incoming = reliableCallbackIncoming('unexpected-'.(int) $exhausted, ['retry_count' => $exhausted ? 4 : 0]);
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(new RuntimeException('synthetic handler exception')));
    $payload = reliableCallbackMessage($incoming)->payload;

    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and($incoming->fresh()->overall_status)->toBe($exhausted ? 'failed_final' : 'failed_retryable')
        ->and($payload['status'])->toBe($exhausted ? 'failed_final' : 'failed_retryable')
        ->and($payload['retryable'])->toBe(! $exhausted)
        ->and($payload['error_class'])->toBe(RuntimeException::class)
        ->and($payload['error_message'])->toBe('synthetic handler exception')
        ->and($payload['meta'])->toMatchArray(['unexpected_exception' => true, 'source' => 'handler_exception'])
        ->and(TalktoDispatchIntent::query()->count())->toBe(1);
})->with([false, true]);

test('custom result accessors are frozen once for result state attempt and callback', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('custom-stateful-result');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(new ReliableCallbackCountingResult));
    $callback = reliableCallbackMessage($incoming);
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and($incoming->fresh()->overall_status)->toBe('succeeded')
        ->and($callback->payload['result'])->toBe(['snapshot' => 1])
        ->and($callback->payload['meta'])->toBe(['snapshot' => 1])
        ->and(TalktoAttempt::query()->where('stage', 'destination_processor')->sole()->meta)->toMatchArray(['result' => ['snapshot' => 1], 'result_meta' => ['snapshot' => 1]])
        ->and(ReliableCallbackFeatureProbe::$resultCalls)->toBe(['isSucceeded' => 1, 'isRetryable' => 1, 'isSkipped' => 1, 'errorClass' => 1, 'errorMessage' => 1, 'result' => 1, 'meta' => 1]);
});

test('missing reliable prerequisites reject before resolver handler or processing persistence', function (string $missing): void {
    reliableCallbackEnable();
    if ($missing === 'dispatch flag') {
        config(['talkto.dispatch_intents.enabled' => false]);
    } else {
        Schema::drop('talkto_dispatch_intents');
    }
    $incoming = reliableCallbackIncoming('preflight-'.$missing);
    $before = $incoming->fresh()->getRawOriginal();
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded())))->toThrow(Exception::class);
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0)
        ->and($incoming->fresh()->getRawOriginal())->toBe($before)
        ->and(TalktoAttempt::query()->count())->toBe(0)
        ->and(TalktoEvent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['dispatch flag', 'dispatch table']);

test('custom callback sender is rejected without invoking it in reliable mode', function (): void {
    reliableCallbackEnable();
    app()->instance(ResultCallbackSenderContract::class, new ReliableCallbackCustomSender);
    $incoming = reliableCallbackIncoming('custom-sender');
    $before = $incoming->fresh()->getRawOriginal();
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded())))->toThrow(Exception::class);
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$senderCalls)->toBe(0)
        ->and($incoming->fresh()->getRawOriginal())->toBe($before)
        ->and(TalktoAttempt::query()->count())->toBe(0)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('disabled reliable mode continues invoking an existing custom callback sender without intent storage', function (): void {
    app()->instance(ResultCallbackSenderContract::class, new ReliableCallbackCustomSender);
    $incoming = reliableCallbackIncoming('legacy-custom-sender');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and(ReliableCallbackFeatureProbe::$senderCalls)->toBe(1)
        ->and($incoming->fresh()->overall_status)->toBe('succeeded')
        ->and(Schema::hasTable('talkto_dispatch_intents'))->toBeFalse();
});

test('reliable callback persistence rolls back the complete outcome on injected transaction failure', function (string $hook): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('rollback-'.str_replace('.', '-', $hook));
    TalktoDispatchTestHooks::set($hook, fn () => throw new RuntimeException('synthetic reliable transaction interruption'));
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['committed' => true]))))->toThrow(Exception::class);
    $incoming = $incoming->fresh();
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and($incoming->overall_status)->toBe('processing')
        ->and($incoming->destination_action_status)->toBe('processing')
        ->and($incoming->completed_at)->toBeNull()
        ->and($incoming->locked_by)->toStartWith('reliable-callback:')
        ->and(TalktoAttempt::query()->sole()->status)->toBe('processing')
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(TalktoEvent::query()->where('event_type', 'destination_processing_succeeded')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['reliable_callback.before_result_persistence', 'reliable_callback.result_persisted', 'reliable_callback.callback_persisted', 'reliable_callback.before_commit']);

test('protected unresolved processing cannot be reexecuted by a duplicate job or stale recovery even after disablement', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('protected-persistence-failure');
    TalktoDispatchTestHooks::set('reliable_callback.after_handler', fn () => throw new RuntimeException('synthetic crash after handler'));
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded())))->toThrow(Exception::class);
    TalktoDispatchTestHooks::reset();
    $incoming->fresh()->forceFill(['locked_at' => now()->subDay()])->save();
    config(['talkto.callbacks.reliable.enabled' => false]);
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
    $claim = app(TalktoDispatchClaimingService::class)->claimStaleRecovery($incoming->fresh(), 0, 'callback-feature-probe');
    expect($claim->claimed)->toBeFalse()
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and($incoming->fresh()->overall_status)->toBe('processing')
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('postcommit interruption retains an executable frozen callback and recovery never calls the original handler', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('postcommit-recovery');
    TalktoDispatchTestHooks::set('reliable_callback.committed', fn () => throw new RuntimeException('synthetic postcommit interruption'));
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['saved' => true]))))->toThrow(Exception::class);
    TalktoDispatchTestHooks::reset();
    $callback = reliableCallbackMessage($incoming);
    $frozen = $callback->getRawOriginal();
    config(['talkto.callbacks.reliable.recovery_enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--message-id' => $callback->message_id, '--min-age' => 0]))->toBe(0)
        ->and(TalktoDispatchIntent::query()->sole()->status)->toBe('published')
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($incoming->fresh()->overall_status)->toBe('succeeded')
        ->and($callback->fresh()->getRawOriginal())->toBe($frozen)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--message-id' => $callback->message_id, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
});

test('callback publication recovery requires both reliable and durable recovery authorization', function (bool $reliableRecovery, bool $durableRecovery): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('recovery-gates-'.(int) $reliableRecovery.(int) $durableRecovery);
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
    config(['talkto.callbacks.reliable.recovery_enabled' => $reliableRecovery, 'talkto.dispatch_intents.recovery_enabled' => $durableRecovery]);
    Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]);
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->sole()->status)->toBe('ready')
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
})->with([[false, false], [false, true], [true, false]]);

test('an older callback blocked by recovery authorization cannot starve ordinary durable dispatch', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('blocked-old-callback');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
    $callback = reliableCallbackMessage($incoming);
    TalktoDispatchIntent::query()->where('message_id', $callback->message_id)->update(['created_at' => now()->subHours(2)]);
    $callbackBefore = $callback->getRawOriginal();
    $intentBefore = TalktoDispatchIntent::query()->where('message_id', $callback->message_id)->sole()->getRawOriginal();
    $ordinary = (new TalktoFlowBuilder('ordinary-after-callback', app(TalktoOutgoingMessageFactory::class)))->to('source-service')->command('domain.command')->payload(['ordinary' => true])->send();
    config(['talkto.dispatch_intents.recovery_enabled' => true, 'talkto.callbacks.reliable.recovery_enabled' => false]);
    expect(Artisan::call('talkto:dispatch-intents', ['--message-id' => $callback->message_id, '--min-age' => 0, '--limit' => 1]))->toBe(0);
    $inspection = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($inspection['candidates'][0]['recovery_eligible'])->toBeFalse()
        ->and($inspection['candidates'][0]['reason'])->toBe('reliable_callback_recovery_disabled')
        ->and(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0, '--limit' => 1]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(TalktoDispatchIntent::query()->where('message_id', $ordinary->message_id)->value('status'))->toBe('published')
        ->and(TalktoDispatchIntent::query()->where('message_id', $callback->message_id)->sole()->getRawOriginal())->toBe($intentBefore)
        ->and($callback->fresh()->getRawOriginal())->toBe($callbackBefore)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
    $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    $job = unserialize($payload['data']['command'], ['allowed_classes' => [SendTalktoMessage::class]]);
    expect($job)->toBeInstanceOf(SendTalktoMessage::class)->and($job->talktoMessageId)->toBe($ordinary->id);
});

test('manual callback sending inside an active reliable handler cannot publish an outcome before finalization', function (bool $disable): void {
    reliableCallbackEnable();
    config(['talkto.dispatch_intents.publish_after_commit' => true]);
    $incoming = reliableCallbackIncoming('premature-manual-callback');
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process(
        $incoming->id,
        reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['early' => true]), function (TalktoMessage $message) use ($disable): void {
            if ($disable) {
                config(['talkto.callbacks.reliable.enabled' => false]);
            }
            app(ResultCallbackSenderContract::class)->sendResult($message, TalktoIncomingCommandResult::succeeded(['early' => true]));
        })
    ))->toThrow(TalktoReliableCallbackPersistenceException::class);
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and($incoming->fresh()->overall_status)->toBe('processing')
        ->and($incoming->fresh()->locked_by)->toStartWith('reliable-callback:')
        ->and($incoming->fresh()->next_retry_at)->toBeNull()
        ->and(TalktoAttempt::query()->sole()->status)->toBe('processing')
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([false, true]);

test('interruption before a reliable handler starts preserves protected evidence without a result callback', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('before-handler');
    TalktoDispatchTestHooks::set('reliable_callback.before_handler', fn () => throw new RuntimeException('synthetic interruption before handler'));
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded())))->toThrow(TalktoReliableCallbackPersistenceException::class, 'requires investigation');
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0)
        ->and($incoming->fresh()->overall_status)->toBe('processing')
        ->and($incoming->fresh()->locked_by)->toStartWith('reliable-callback:')
        ->and(TalktoAttempt::query()->sole()->status)->toBe('processing')
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a stale incoming instance cannot manually publish from inside reliable processing', function (bool $disable, bool $customModel): void {
    reliableCallbackEnable();
    config(['talkto.dispatch_intents.publish_after_commit' => true]);
    if ($customModel) {
        config(['talkto.models.message' => ReliableCallbackCustomMessage::class]);
    }
    $incoming = reliableCallbackIncoming('stale-manual-'.(int) $disable.(int) $customModel);
    $stale = $incoming->fresh();
    $failure = null;
    try {
        app(ProcessIncomingTalktoMessagePipeline::class)->process(
            $incoming->id,
            reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['actual' => true]), function () use ($stale, $disable): void {
                if ($disable) {
                    config(['talkto.callbacks.reliable.enabled' => false]);
                }
                app(ResultCallbackSenderContract::class)->sendResult($stale, TalktoIncomingCommandResult::succeeded(['actual' => true]));
            })
        );
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and($incoming->fresh()->overall_status)->toBe('processing')
        ->and(TalktoAttempt::query()->sole()->status)->toBe('processing')
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($failure)->toBeInstanceOf(TalktoReliableCallbackPersistenceException::class);
})->with([
    'base model and enabled flag' => [false, false],
    'base model after disabling flag' => [true, false],
    'custom processing model and base stale instance' => [false, true],
    'custom processing model and base stale instance after disablement' => [true, true],
]);

test('incoming identity or claim changes before handler execution cannot run stale business work', function (string $attribute): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('before-handler-identity-'.$attribute);
    $expected = null;
    TalktoDispatchTestHooks::set('reliable_callback.before_handler', function () use ($incoming, $attribute, &$expected): void {
        DB::table('talkto_messages')->where('id', $incoming->id)->update([$attribute => 'changed-'.bin2hex(random_bytes(8))]);
        $expected = (array) DB::table('talkto_messages')->where('id', $incoming->id)->first();
    });
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded())))->toThrow(Exception::class);
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0)
        ->and((array) DB::table('talkto_messages')->where('id', $incoming->id)->first())->toBe($expected)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['target_service', 'message_id', 'locked_by']);

test('configuration changes after the handler returns cannot commit a result through different callback bindings', function (string $change): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('context-drift-'.$change);
    $original = ['talkto.service' => config('talkto.service'), 'talkto.callbacks.command' => config('talkto.callbacks.command'),
        'talkto.jobs.send_message' => config('talkto.jobs.send_message'), 'talkto.database.connection' => config('talkto.database.connection')];
    config(['database.connections.reliable_callback_other' => config('database.connections.sqlite')]);
    TalktoDispatchTestHooks::set('reliable_callback.after_handler', function () use ($change): void {
        match ($change) {
            'service' => config(['talkto.service' => 'other-service']),
            'command' => config(['talkto.callbacks.command' => 'other.result']),
            'job' => config(['talkto.jobs.send_message' => ReliableCallbackCustomSendJob::class]),
            'connection' => config(['talkto.database.connection' => 'reliable_callback_other']),
        };
    });
    try {
        expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded())))->toThrow(Exception::class);
    } finally {
        config($original);
    }
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and($incoming->fresh()->overall_status)->toBe('processing')
        ->and($incoming->fresh()->locked_by)->toStartWith('reliable-callback:')
        ->and(TalktoAttempt::query()->sole()->status)->toBe('processing')
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['service', 'command', 'job', 'connection']);

test('a callback factory instance rebound after handler return is not silently bypassed', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('rebound-callback-factory');
    TalktoDispatchTestHooks::set('reliable_callback.after_handler', function (): void {
        app()->instance(TalktoResultCallbackMessageFactory::class, new ReliableCallbackOverriddenFactory(app(TalktoOutgoingMessageFactory::class)));
    });
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded())))->toThrow(Exception::class);
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and(ReliableCallbackFeatureProbe::$senderCalls)->toBe(0)
        ->and($incoming->fresh()->overall_status)->toBe('processing')
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('actual model persistence failure leaves no committed result or callback and does not retry the handler', function (string $binding): void {
    reliableCallbackEnable();
    config(['talkto.models.'.$binding => match ($binding) {
        'message' => ReliableCallbackFailingMessage::class,
        'attempt' => ReliableCallbackFailingAttempt::class,
        'event' => ReliableCallbackFailingEvent::class,
        'dispatch_intent' => ReliableCallbackFailingIntent::class,
    }]);
    $incoming = reliableCallbackIncoming('write-failure-'.$binding);
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['saved' => true]))))->toThrow(TalktoReliableCallbackPersistenceException::class, 'requires investigation');
    expect($incoming->fresh()->overall_status)->toBe('processing')
        ->and($incoming->fresh()->next_retry_at)->toBeNull()
        ->and(TalktoAttempt::query()->sole()->status)->toBe('processing')
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
    TalktoDispatchTestHooks::reset();
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
})->with(['message', 'attempt', 'event', 'dispatch_intent']);

test('a model silently cancelling required reliable persistence cannot commit partial callback state', function (string $fault): void {
    reliableCallbackEnable();
    $claim = str_starts_with($fault, 'claim ');
    [$binding, $class] = match ($fault) {
        'result message', 'callback message', 'claim message' => ['message', ReliableCallbackCancellingMessage::class],
        'result attempt', 'claim attempt' => ['attempt', ReliableCallbackCancellingAttempt::class],
        'result event', 'coordination event', 'claim event' => ['event', ReliableCallbackCancellingEvent::class],
        'callback intent' => ['dispatch_intent', ReliableCallbackCancellingIntent::class],
    };
    ReliableCallbackFeatureProbe::$cancelSaveWhen = match ($fault) {
        'result message' => fn (Model $model): bool => $model->direction === 'incoming' && $model->overall_status === 'succeeded',
        'callback message' => fn (Model $model): bool => $model->direction === 'outgoing',
        'claim message' => fn (Model $model): bool => $model->direction === 'incoming' && $model->overall_status === 'processing',
        'result attempt' => fn (Model $model): bool => $model->status === 'succeeded',
        'claim attempt' => fn (Model $model): bool => $model->status === 'processing',
        'result event' => fn (Model $model): bool => $model->event_type === 'destination_processing_succeeded',
        'coordination event' => fn (Model $model): bool => $model->event_type === 'result_callback_persisted',
        'claim event' => fn (Model $model): bool => $model->event_type === 'destination_processing_started',
        'callback intent' => fn (Model $model): bool => $model->purpose === 'result_callback',
    };
    config(['talkto.models.'.$binding => $class]);
    $incoming = reliableCallbackIncoming('silent-save-cancelled-'.str_replace(' ', '-', $fault));
    $before = $incoming->fresh()->getRawOriginal();
    $failure = null;
    try {
        app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['actual' => true])));
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }
    expect(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($failure)->toBeInstanceOf(Exception::class);
    if ($claim) {
        expect($incoming->fresh()->getRawOriginal())->toBe($before)
            ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0)
            ->and(TalktoAttempt::query()->count())->toBe(0)
            ->and(TalktoEvent::query()->count())->toBe(0);
    } else {
        expect($incoming->fresh()->overall_status)->toBe('processing')
            ->and($incoming->fresh()->locked_by)->toStartWith('reliable-callback:')
            ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
            ->and(TalktoAttempt::query()->sole()->status)->toBe('processing')
            ->and(TalktoEvent::query()->whereIn('event_type', ['destination_processing_succeeded', 'result_callback_persisted'])->count())->toBe(0)
            ->and($failure)->toBeInstanceOf(TalktoReliableCallbackPersistenceException::class);
        app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
        expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
    }
})->with(['result message', 'result attempt', 'result event', 'callback message', 'callback intent', 'coordination event', 'claim message', 'claim attempt', 'claim event']);

test('an actual callback insert uniqueness violation preserves unresolved processing without exposing the result in its error', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('callback-sql-constraint');
    $callbackId = 'cb-'.sha1($incoming->message_id.'|succeeded');
    $collision = app(TalktoOutgoingMessageFactory::class)->create('source-service', 'domain.other', ['unrelated' => true], ['message_id' => $callbackId, 'idempotency_key' => 'unrelated-collision']);
    $before = $collision->fresh()->getRawOriginal();
    $failure = null;
    try {
        app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['private_fixture' => 'sensitive-result-fixture-never-log'])));
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }
    expect($failure)->toBeInstanceOf(Exception::class)
        ->and($incoming->fresh()->overall_status)->toBe('processing')
        ->and($incoming->fresh()->next_retry_at)->toBeNull()
        ->and(TalktoAttempt::query()->sole()->status)->toBe('processing')
        ->and($collision->fresh()->getRawOriginal())->toBe($before)
        ->and(TalktoMessage::query()->where('parent_message_id', $incoming->message_id)->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and($failure->getMessage())->not->toContain('sensitive-result-fixture-never-log')
        ->and((string) $failure)->not->toContain('sensitive-result-fixture-never-log');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
});

test('the reliable manual sender does not expose SQL-bound result payloads when callback creation fails', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('manual-sql-constraint');
    $incomingBefore = $incoming->fresh()->getRawOriginal();
    app(TalktoOutgoingMessageFactory::class)->create('source-service', 'domain.other', ['unrelated' => true], ['message_id' => 'cb-'.sha1($incoming->message_id.'|succeeded'), 'idempotency_key' => 'manual-unrelated-collision']);
    $failure = null;
    try {
        app(ResultCallbackSenderContract::class)->sendResult($incoming, TalktoIncomingCommandResult::succeeded(['private_fixture' => 'sensitive-manual-result-fixture-never-log']));
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }
    expect($failure)->toBeInstanceOf(Exception::class)
        ->and($incoming->fresh()->getRawOriginal())->toBe($incomingBefore)
        ->and(TalktoAttempt::query()->count())->toBe(0)
        ->and(TalktoMessage::query()->where('parent_message_id', $incoming->message_id)->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0)
        ->and((string) $failure)->not->toContain('sensitive-manual-result-fixture-never-log');
});

test('reliable callback result payload supports representative large nested metadata', function (int $bytes): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('large-result-'.$bytes);
    $body = str_repeat('x', $bytes);
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['body' => $body], ['nested' => ['unicode' => 'نتیجه', 'empty' => []]])));
    $callback = reliableCallbackMessage($incoming);
    expect($callback->payload['result']['body'])->toBe($body)
        ->and($callback->payload['meta'])->toBe(['nested' => ['unicode' => 'نتیجه', 'empty' => []]])
        ->and(TalktoDispatchIntent::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
})->with([1024, 65536, 262144, 1048576, 2516582]);

test('large Unicode result metadata is frozen identically into the attempt and callback payload', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('large-unicode-metadata');
    $meta = ['nested' => ['empty' => [], 'null' => null, 'details' => [['unicode' => str_repeat('م', 131072)]]]];
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded([], $meta)));
    $callback = reliableCallbackMessage($incoming);
    expect($callback->payload['meta'])->toBe($meta)
        ->and($callback->payload['result'])->toBe([])
        ->and(TalktoAttempt::query()->sole()->meta['result_meta'])->toBe($meta)
        ->and($callback->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($callback->payload))
        ->and(TalktoDispatchIntent::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
});

test('callbacks deliberately disabled or automatic callback dispatch disabled need no reliable storage', function (string $disabled): void {
    config(['talkto.callbacks.reliable.enabled' => true, 'talkto.callbacks.'.$disabled => false]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql.' '.json_encode($query->bindings, JSON_THROW_ON_ERROR);
    });
    $incoming = reliableCallbackIncoming('not-required-'.$disabled);
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
    expect($incoming->fresh()->overall_status)->toBe('succeeded')
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(array_filter($queries, fn (string $sql): bool => str_contains(strtolower($sql), 'dispatch_intents')))->toBe([])
        ->and(Schema::hasTable('talkto_dispatch_intents'))->toBeFalse();
})->with(['enabled', 'auto_dispatch']);

test('reliable manual sender keeps public summary keys custom identity and idempotent persistence', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('manual-result');
    $result = TalktoIncomingCommandResult::succeeded(['manual' => true], ['empty' => []]);
    $sender = app(ResultCallbackSenderContract::class);
    $first = $sender->sendResult($incoming, $result, ['callback_message_id' => 'manual-custom-callback']);
    $second = $sender->sendResult($incoming, $result, ['callback_message_id' => 'manual-custom-callback']);
    expect($first)->toHaveKeys(['sent', 'queued', 'status', 'message_id', 'callback_message_id', 'callback_message_db_id', 'original_message_id', 'target', 'command'])
        ->and($first['sent'])->toBeFalse()
        ->and($first['queued'])->toBeFalse()
        ->and($first['callback_message_id'])->toBe('manual-custom-callback')
        ->and($second['callback_message_id'])->toBe($first['callback_message_id'])
        ->and($second['queued'])->toBeFalse()
        ->and($second['duplicate'])->toBeTrue()
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(1)
        ->and(TalktoDispatchIntent::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0);
});

test('a previously reliable callback is reused during finalization without a second intent or publication', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('reused-reliable-callback');
    $result = TalktoIncomingCommandResult::succeeded(['same' => true]);
    app(ResultCallbackSenderContract::class)->sendResult($incoming, $result);
    $callback = reliableCallbackMessage($incoming);
    $before = $callback->getRawOriginal();
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver($result));
    expect($incoming->fresh()->overall_status)->toBe('succeeded')
        ->and(reliableCallbackMessage($incoming)->getRawOriginal())->toBe($before)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(1)
        ->and(TalktoDispatchIntent::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
});

test('conflicting reliable callback payload leaves the actual handler result unresolved without overwriting delivery evidence', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('conflicting-callback');
    app(ResultCallbackSenderContract::class)->sendResult($incoming, TalktoIncomingCommandResult::succeeded(['old' => true]));
    $callback = reliableCallbackMessage($incoming);
    $before = $callback->getRawOriginal();
    $intentBefore = TalktoDispatchIntent::query()->sole()->getRawOriginal();
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['new' => true]))))->toThrow(TalktoReliableCallbackPersistenceException::class, 'requires investigation');
    expect($incoming->fresh()->overall_status)->toBe('processing')
        ->and($incoming->fresh()->next_retry_at)->toBeNull()
        ->and($callback->fresh()->getRawOriginal())->toBe($before)
        ->and(TalktoDispatchIntent::query()->sole()->getRawOriginal())->toBe($intentBefore)
        ->and(TalktoAttempt::query()->sole()->status)->toBe('processing')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
});

test('legacy callbacks are not retrofitted with executable intents even when already completed', function (bool $completed): void {
    $incoming = reliableCallbackIncoming('legacy-unverified-'.(int) $completed);
    $result = TalktoIncomingCommandResult::succeeded(['same' => true]);
    $callback = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult($incoming, $result);
    if ($completed) {
        $callback->forceFill(['overall_status' => 'completed', 'transport_status' => 'sent', 'completed_at' => now(), 'sent_at' => now()])->save();
    }
    $before = $callback->fresh()->getRawOriginal();
    reliableCallbackEnable();
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver($result)))->toThrow(TalktoReliableCallbackPersistenceException::class, 'requires investigation');
    expect($callback->fresh()->getRawOriginal())->toBe($before)
        ->and($incoming->fresh()->overall_status)->toBe('processing')
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
})->with([false, true]);

test('after disabling reliable mode legacy manual sending cannot bypass already persisted reliable callback evidence', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('disabled-existing-evidence');
    $result = TalktoIncomingCommandResult::succeeded(['saved' => true]);
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver($result));
    $callback = reliableCallbackMessage($incoming);
    $before = $callback->getRawOriginal();
    config(['talkto.callbacks.reliable.enabled' => false, 'talkto.dispatch_intents.enabled' => false]);
    Schema::drop('talkto_dispatch_intents');
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql.' '.json_encode($query->bindings, JSON_THROW_ON_ERROR);
    });
    $summary = app(ResultCallbackSenderContract::class)->sendResult($incoming->fresh(), $result);
    expect($summary['queued'])->toBeFalse()
        ->and($callback->fresh()->getRawOriginal())->toBe($before)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(array_filter($queries, fn (string $sql): bool => str_contains(strtolower($sql), 'dispatch_intents')))->toBe([])
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
});

test('custom models tables and a dedicated connection retain atomic callback persistence and real existing job execution', function (): void {
    config(['database.connections.reliable_callback_other' => config('database.connections.sqlite'), 'talkto.database.connection' => 'reliable_callback_other',
        'talkto.database.tables.messages' => 'custom_callback_messages', 'talkto.database.tables.attempts' => 'custom_callback_attempts',
        'talkto.database.tables.events' => 'custom_callback_events', 'talkto.database.tables.dead_letters' => 'custom_callback_dead_letters',
        'talkto.database.tables.nonces' => 'custom_callback_nonces', 'talkto.database.tables.dispatch_intents' => 'custom_callback_intents',
        'talkto.models.message' => ReliableCallbackCustomMessage::class, 'talkto.models.attempt' => ReliableCallbackCustomAttempt::class,
        'talkto.models.event' => ReliableCallbackCustomEvent::class, 'talkto.models.dispatch_intent' => ReliableCallbackCustomIntent::class,
        'queue.connections.callback_custom_storage' => ['driver' => 'database', 'connection' => 'reliable_callback_other', 'table' => 'jobs', 'queue' => 'custom-storage', 'retry_after' => 90, 'after_commit' => false],
        'queue.default' => 'callback_custom_storage']);
    reliableCallbackMigrateBase();
    reliableCallbackEnable();
    reliableCallbackCreateJobs('reliable_callback_other');
    $client = new ReliableCallbackHttpClient;
    app()->instance(TalktoHttpClient::class, $client);
    $incoming = reliableCallbackIncoming('custom-model-storage');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['custom' => true])));
    $callback = ReliableCallbackCustomMessage::query()->where('direction', 'outgoing')->sole();
    expect($callback)->toBeInstanceOf(ReliableCallbackCustomMessage::class)
        ->and(ReliableCallbackCustomIntent::query()->sole()->message_class)->toBe(ReliableCallbackCustomMessage::class)
        ->and(ReliableCallbackCustomIntent::query()->sole()->message_table)->toBe('custom_callback_messages')
        ->and(DB::connection('sqlite')->table('talkto_messages')->count())->toBe(0)
        ->and(ReliableCallbackCustomAttempt::query()->sole()->status)->toBe('succeeded');
    config(['talkto.callbacks.reliable.recovery_enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0);
    $job = Queue::connection()->pop('custom-storage');
    expect($job)->not->toBeNull();
    $job->fire();
    $job->delete();
    expect($callback->fresh()->overall_status)->toBe('completed')
        ->and(count($client->requests))->toBe(1)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and(DB::connection('sqlite')->table('talkto_messages')->count())->toBe(0);
});

test('reliable preflight rejects a factory actually injected into the default sender before handler execution', function (): void {
    reliableCallbackEnable();
    $factory = new ReliableCallbackOverriddenFactory(app(TalktoOutgoingMessageFactory::class));
    app()->instance(ResultCallbackSenderContract::class, new TalktoResultCallbackSender($factory, app(TalktoSecurityRedactor::class), app(TalktoDispatchClaimingService::class)));
    $incoming = reliableCallbackIncoming('contextual-factory');
    $before = $incoming->fresh()->getRawOriginal();
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded())))->toThrow(Exception::class);
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$senderCalls)->toBe(0)
        ->and($incoming->fresh()->getRawOriginal())->toBe($before)
        ->and(TalktoAttempt::query()->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('contextual factory protected model resolvers cannot commit a callback on another database in reliable mode', function (bool $reliable): void {
    config(['database.connections.reliable_callback_other' => config('database.connections.sqlite'), 'talkto.database.connection' => 'reliable_callback_other']);
    reliableCallbackMigrateBase();
    foreach (glob(__DIR__.'/../../database/dispatch-intents/*.php') as $migration) {
        (require $migration)->up();
    }
    reliableCallbackCreateJobs('reliable_callback_other');
    config(['talkto.database.connection' => null]);
    if ($reliable) {
        reliableCallbackEnable();
    }
    $foreignOutgoing = app(ReliableCallbackForeignModelFactory::class);
    $callbackFactory = new TalktoResultCallbackMessageFactory($foreignOutgoing);
    $incoming = reliableCallbackIncoming('protected-model-factory-'.(int) $reliable);
    if ($reliable) {
        app()->instance(ResultCallbackSenderContract::class, new TalktoResultCallbackSender($callbackFactory, app(TalktoSecurityRedactor::class), app(TalktoDispatchClaimingService::class)));
        $before = $incoming->fresh()->getRawOriginal();
        expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded())))->toThrow(RuntimeException::class, 'messageModelClass');
        expect($incoming->fresh()->getRawOriginal())->toBe($before)
            ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0)
            ->and(ReliableCallbackFeatureProbe::$factoryResolverCalls)->toBe(0)
            ->and(DB::table('talkto_attempts')->count())->toBe(0)
            ->and(DB::table('talkto_events')->count())->toBe(0)
            ->and(DB::table('talkto_dispatch_intents')->count())->toBe(0)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::connection('reliable_callback_other')->table('talkto_messages')->count())->toBe(0)
            ->and(DB::connection('reliable_callback_other')->table('talkto_events')->count())->toBe(0)
            ->and(DB::connection('reliable_callback_other')->table('talkto_dispatch_intents')->count())->toBe(0)
            ->and(DB::connection('reliable_callback_other')->table('jobs')->count())->toBe(0);
    } else {
        $callback = $callbackFactory->createForIncomingResult($incoming, TalktoIncomingCommandResult::succeeded(['legacy' => true]));
        expect($callback->getConnection()->getName())->toBe('reliable_callback_other')
            ->and(ReliableCallbackFeatureProbe::$factoryResolverCalls)->toBeGreaterThan(0)
            ->and(DB::table('talkto_messages')->where('direction', 'outgoing')->count())->toBe(0)
            ->and(DB::connection('reliable_callback_other')->table('talkto_messages')->count())->toBe(1)
            ->and(DB::connection('reliable_callback_other')->table('talkto_events')->count())->toBe(1)
            ->and(DB::connection('reliable_callback_other')->table('talkto_dispatch_intents')->count())->toBe(0)
            ->and(DB::table('jobs')->count())->toBe(0);
    }
})->with(['reliable rejection before side effects' => true, 'legacy protected factory extension remains available' => false]);

test('reliable mode rejects unsupported callback queue features before handler execution', function (string $unsupported): void {
    reliableCallbackEnable();
    if ($unsupported === 'sync') {
        config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync']]);
    } elseif ($unsupported === 'unique') {
        config(['talkto.jobs.send_message' => ReliableCallbackUniqueSendJob::class]);
    } elseif ($unsupported === 'constructor') {
        config(['talkto.jobs.send_message' => ReliableCallbackConstructorSendJob::class]);
    } elseif ($unsupported === 'invalid class') {
        config(['talkto.jobs.send_message' => stdClass::class]);
    } else {
        config(['talkto.jobs.send_message' => ReliableCallbackMiddlewareSendJob::class]);
    }
    $incoming = reliableCallbackIncoming('unsupported-'.$unsupported);
    $before = $incoming->fresh()->getRawOriginal();
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded())))->toThrow(Exception::class);
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0)
        ->and($incoming->fresh()->getRawOriginal())->toBe($before)
        ->and(TalktoAttempt::query()->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$constructorCalls)->toBe(0);
})->with(['sync', 'unique', 'middleware', 'constructor', 'invalid class']);

test('legacy callback sender still accepts the configured custom job constructor without optional migration', function (): void {
    config(['talkto.jobs.send_message' => ReliableCallbackConstructorSendJob::class]);
    $incoming = reliableCallbackIncoming('legacy-custom-constructor');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
    expect($incoming->fresh()->overall_status)->toBe('succeeded')
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and(ReliableCallbackFeatureProbe::$constructorCalls)->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'constructor-custom')->count())->toBe(1)
        ->and(Schema::hasTable('talkto_dispatch_intents'))->toBeFalse();
});

test('a reliable handler is not started inside an existing outer database transaction', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('outer-transaction');
    $before = $incoming->fresh()->getRawOriginal();
    DB::beginTransaction();
    try {
        expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded())))->toThrow(Exception::class);
        expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0)
            ->and($incoming->fresh()->getRawOriginal())->toBe($before)
            ->and(TalktoAttempt::query()->count())->toBe(0)
            ->and(TalktoDispatchIntent::query()->count())->toBe(0)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::transactionLevel())->toBe(1);
    } finally {
        DB::rollBack();
    }
});

test('cross connection attempt or event models reject before the original handler', function (string $binding): void {
    reliableCallbackEnable();
    config(['database.connections.reliable_callback_other' => config('database.connections.sqlite')]);
    config(['talkto.models.'.$binding => $binding === 'attempt' ? ReliableCallbackForeignAttempt::class : ReliableCallbackForeignEvent::class]);
    $incoming = reliableCallbackIncoming('cross-connection-'.$binding);
    $before = $incoming->fresh()->getRawOriginal();
    expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded())))->toThrow(Exception::class);
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0)
        ->and($incoming->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('talkto_attempts')->count())->toBe(0)
        ->and(DB::table('talkto_events')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['attempt', 'event']);

test('a nonprimitive callback result fails without creating success or automatically retrying the handler', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('invalid-result-payload');
    $resource = fopen('php://memory', 'r+');
    try {
        expect(fn () => app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['unsafe' => $resource]))))->toThrow(Exception::class);
    } finally {
        fclose($resource);
    }
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and($incoming->fresh()->overall_status)->toBe('processing')
        ->and($incoming->fresh()->locked_by)->toStartWith('reliable-callback:')
        ->and($incoming->fresh()->next_retry_at)->toBeNull()
        ->and(TalktoAttempt::query()->sole()->status)->toBe('processing')
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(0);
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
    expect(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
});

test('callback recovery publishes the configured existing job and executing duplicate envelopes does not redeliver', function (): void {
    reliableCallbackEnable();
    config(['talkto.jobs.send_message' => ReliableCallbackCustomSendJob::class, 'talkto.callbacks.reliable.recovery_enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    $client = new ReliableCallbackHttpClient;
    app()->instance(TalktoHttpClient::class, $client);
    $incoming = reliableCallbackIncoming('actual-custom-job');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['saved' => true])));
    $callback = reliableCallbackMessage($incoming);
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--message-id' => $callback->message_id, '--min-age' => 0]))->toBe(0)
        ->and(TalktoDispatchIntent::query()->sole()->job_class)->toBe(ReliableCallbackCustomSendJob::class)
        ->and(TalktoDispatchIntent::query()->sole()->purpose)->toBe('result_callback');
    Queue::connection()->push(new ReliableCallbackCustomSendJob($callback->id), '', 'reliable-custom');
    $first = Queue::connection()->pop('reliable-custom');
    expect($first)->not->toBeNull()->and($first->resolveName())->toBe(ReliableCallbackCustomSendJob::class);
    $first->fire();
    $first->delete();
    $completed = $callback->fresh()->getRawOriginal();
    $second = Queue::connection()->pop('reliable-custom');
    expect($second)->not->toBeNull();
    $second->fire();
    $second->delete();
    expect($callback->fresh()->overall_status)->toBe('completed')
        ->and($callback->fresh()->getRawOriginal())->toBe($completed)
        ->and(count($client->requests))->toBe(1)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(1);
});

test('normal after commit callback publication is independent of the manual recovery switch', function (): void {
    reliableCallbackEnable();
    config(['talkto.dispatch_intents.publish_after_commit' => true]);
    $incoming = reliableCallbackIncoming('automatic-publication');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
    expect($incoming->fresh()->overall_status)->toBe('succeeded')
        ->and(TalktoDispatchIntent::query()->sole()->status)->toBe('published')
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(config('talkto.callbacks.reliable.recovery_enabled'))->toBeFalse();
});

test('callback publication failure after atomic result commit leaves recoverable evidence rather than a failed handler result', function (): void {
    reliableCallbackEnable();
    config(['talkto.dispatch_intents.publish_after_commit' => true]);
    TalktoDispatchTestHooks::set('dispatch_intent.before_claim', fn () => Schema::drop('jobs'));
    $incoming = reliableCallbackIncoming('atomic-queue-outage');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['saved' => true])));
    expect($incoming->fresh()->overall_status)->toBe('succeeded')
        ->and(reliableCallbackMessage($incoming)->payload['result'])->toBe(['saved' => true])
        ->and(TalktoAttempt::query()->sole()->status)->toBe('succeeded')
        ->and(TalktoDispatchIntent::query()->sole()->status)->toBe('claimed')
        ->and(TalktoDispatchIntent::query()->sole()->publication_attempts)->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
});

test('terminal original and callback records stay terminal through repeated callback recovery and duplicate incoming jobs', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('terminal-protection');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
    $callback = reliableCallbackMessage($incoming);
    $callback->forceFill(['overall_status' => 'completed', 'transport_status' => 'sent', 'completed_at' => now(), 'sent_at' => now()])->save();
    $originalState = $incoming->fresh()->getRawOriginal();
    $callbackState = $callback->fresh()->getRawOriginal();
    config(['talkto.callbacks.reliable.recovery_enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    for ($i = 0; $i < 2; $i++) {
        expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0);
        app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['wrong' => true])));
    }
    expect($incoming->fresh()->getRawOriginal())->toBe($originalState)
        ->and($callback->fresh()->getRawOriginal())->toBe($callbackState)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and(TalktoDispatchIntent::query()->sole()->status)->toBe('cancelled');
});

test('legacy installation without optional tables executes real queues signed reception and final callback application', function (): void {
    config([
        'talkto.security.require_signature' => true, 'talkto.security.signature_version' => 'v2', 'talkto.security.accept_versions' => ['v2'],
        'talkto.incoming.source-service' => ['secret' => 'synthetic-callback-fixture-secret', 'allowed_commands' => ['domain.command' => ['driver' => 'none']]],
        'talkto.incoming.target-service' => ['secret' => 'synthetic-callback-fixture-secret', 'allowed_commands' => ['talkto.result' => ['driver' => 'none']]],
        'talkto.outgoing.target-service' => ['url' => 'https://target-callback.invalid', 'secret' => 'synthetic-callback-fixture-secret'],
        'database.connections.reliable_callback_source' => config('database.connections.sqlite'),
        'queue.connections.callback_source_queue' => ['driver' => 'database', 'connection' => 'reliable_callback_source', 'table' => 'jobs', 'queue' => 'source-probe', 'retry_after' => 90, 'after_commit' => false],
    ]);
    config(['talkto.database.connection' => 'reliable_callback_source', 'talkto.service' => 'source-service', 'queue.default' => 'callback_source_queue']);
    reliableCallbackMigrateBase();
    reliableCallbackCreateJobs('reliable_callback_source');
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql.' '.json_encode($query->bindings, JSON_THROW_ON_ERROR);
    });
    $client = new ReliableCallbackHttpClient(function (array $envelope, array $headers): TalktoHttpResponse {
        $snapshot = [config('talkto.database.connection'), config('talkto.service'), config('queue.default')];
        try {
            if ($envelope['command'] === 'talkto.result') {
                config(['talkto.database.connection' => 'reliable_callback_source', 'talkto.service' => 'source-service', 'queue.default' => 'callback_source_queue']);
                $response = app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers);

                return new TalktoHttpResponse(200, json_encode($response, JSON_THROW_ON_ERROR));
            }
            config(['talkto.database.connection' => null, 'talkto.service' => 'target-service', 'queue.default' => 'callback_database']);
            $request = Request::create('/api/talkto/receive', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($envelope, JSON_THROW_ON_ERROR));
            foreach ($headers as $name => $value) {
                $request->headers->set($name, $value);
            }
            $response = app(ReceiveIncomingTalktoMessagePipeline::class)->receive($request, app(TalktoSignatureVerifier::class));

            return new TalktoHttpResponse($response->getStatusCode(), $response->getContent());
        } finally {
            config(['talkto.database.connection' => $snapshot[0], 'talkto.service' => $snapshot[1], 'queue.default' => $snapshot[2]]);
        }
    });
    app()->instance(TalktoHttpClient::class, $client);
    $original = (new TalktoFlowBuilder('legacy-round-trip', app(TalktoOutgoingMessageFactory::class)))
        ->to('target-service')->command('domain.command')->payload(['unicode' => 'سلام', 'empty' => []])->send();
    $sourceJob = Queue::connection('callback_source_queue')->pop('source-probe');
    expect($sourceJob)->not->toBeNull();
    $sourceJob->fire();
    $sourceJob->delete();
    expect(DB::connection('reliable_callback_source')->table('talkto_messages')->where('message_id', $original->message_id)->value('overall_status'))->toBe('destination_received');
    config(['talkto.database.connection' => null, 'talkto.service' => 'target-service', 'queue.default' => 'callback_database']);
    app()->instance(TalktoIncomingCommandResolver::class, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['applied' => true], ['unicode' => 'نتیجه'])));
    $incomingJob = Queue::connection('callback_database')->pop('callback-probe');
    expect($incomingJob)->not->toBeNull();
    $incomingJob->fire();
    $incomingJob->delete();
    $callbackJob = Queue::connection('callback_database')->pop('callback-probe');
    expect($callbackJob)->not->toBeNull();
    $callbackJob->fire();
    $callbackJob->delete();
    expect(DB::connection('reliable_callback_source')->table('talkto_messages')->where('message_id', $original->message_id)->value('overall_status'))->toBe('completed')
        ->and(TalktoMessage::query()->where('message_id', $original->message_id)->value('overall_status'))->toBe('succeeded')
        ->and(TalktoMessage::query()->where('parent_message_id', $original->message_id)->value('overall_status'))->toBe('completed')
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and(count($client->requests))->toBe(2)
        ->and(DB::connection('reliable_callback_source')->table('jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(array_filter($queries, fn (string $sql): bool => str_contains(strtolower($sql), 'dispatch_intents')))->toBe([])
        ->and(Schema::hasTable('talkto_dispatch_intents'))->toBeFalse()
        ->and(Schema::connection('reliable_callback_source')->hasTable('talkto_dispatch_intents'))->toBeFalse();
});

test('signed reliable callback delivery retries a lost HTTP acknowledgement without rerunning the original handler', function (): void {
    reliableCallbackEnable();
    config([
        'talkto.security.require_signature' => true, 'talkto.security.signature_version' => 'v2', 'talkto.security.accept_versions' => ['v2'],
        'talkto.incoming.target-service' => ['secret' => 'synthetic-callback-fixture-secret', 'allowed_commands' => ['talkto.result' => ['driver' => 'none']]],
        'talkto.outgoing.target-service' => ['url' => 'https://target-callback.invalid', 'secret' => 'synthetic-callback-fixture-secret'],
        'database.connections.reliable_callback_source' => config('database.connections.sqlite'),
    ]);
    config(['talkto.database.connection' => 'reliable_callback_source', 'talkto.service' => 'source-service']);
    reliableCallbackMigrateBase();
    $original = app(TalktoOutgoingMessageFactory::class)->create('target-service', 'domain.command', ['synthetic' => true], ['message_id' => 'http-ack-lost']);
    config(['talkto.database.connection' => null, 'talkto.service' => 'target-service']);
    $deliveries = 0;
    $acks = 0;
    $client = new ReliableCallbackHttpClient(function (array $envelope, array $headers) use (&$deliveries, &$acks): TalktoHttpResponse {
        config(['talkto.database.connection' => 'reliable_callback_source', 'talkto.service' => 'source-service']);
        try {
            $response = app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers);
            expect($response['accepted'])->toBeTrue();
            $deliveries++;
            if ($deliveries === 1) {
                throw new RuntimeException('synthetic callback HTTP acknowledgement lost');
            }
            $acks++;

            return new TalktoHttpResponse(200, json_encode($response, JSON_THROW_ON_ERROR));
        } finally {
            config(['talkto.database.connection' => null, 'talkto.service' => 'target-service']);
        }
    });
    app()->instance(TalktoHttpClient::class, $client);
    $incoming = reliableCallbackIncoming($original->message_id);
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['applied' => true])));
    $callback = reliableCallbackMessage($incoming);
    config(['talkto.callbacks.reliable.recovery_enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0);
    $job = Queue::connection()->pop('callback-probe');
    expect($job)->not->toBeNull();
    $job->fire();
    $job->delete();
    expect($deliveries)->toBe(1)
        ->and($acks)->toBe(0)
        ->and($callback->fresh()->overall_status)->toBe('failed_retryable')
        ->and(DB::connection('reliable_callback_source')->table('talkto_messages')->where('message_id', $original->message_id)->value('overall_status'))->toBe('completed');
    $callback->fresh()->forceFill(['next_retry_at' => now()->subMinute(), 'next_attempt_at' => now()->subMinute()])->save();
    expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing', '--limit' => 10]))->toBe(0);
    $retry = Queue::connection()->pop('callback-probe');
    expect($retry)->not->toBeNull();
    $retry->fire();
    $retry->delete();
    expect($deliveries)->toBe(2)
        ->and($acks)->toBe(1)
        ->and($callback->fresh()->overall_status)->toBe('completed')
        ->and($incoming->fresh()->overall_status)->toBe('succeeded')
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(1)
        ->and(TalktoDispatchIntent::query()->count())->toBe(1)
        ->and(TalktoDispatchIntent::query()->sole()->publication_attempts)->toBe(1)
        ->and(DB::connection('reliable_callback_source')->table('talkto_events')->where('event_type', 'result_callback_applied')->count())->toBe(1)
        ->and(DB::connection('reliable_callback_source')->table('talkto_events')->where('event_type', 'result_callback_duplicate')->count())->toBe(1)
        ->and($client->requests[0]['envelope']['payload'])->toBe($client->requests[1]['envelope']['payload']);
});

test('a separate database queue acceptance with lost acknowledgement preserves unknown callback publication', function (): void {
    reliableCallbackEnable();
    config(['database.connections.reliable_callback_broker' => config('database.connections.sqlite'),
        'queue.connections.callback_external' => ['driver' => 'database', 'connection' => 'reliable_callback_broker', 'table' => 'jobs', 'queue' => 'external-callback', 'retry_after' => 90, 'after_commit' => false],
        'queue.default' => 'callback_external']);
    reliableCallbackCreateJobs('reliable_callback_broker');
    $incoming = reliableCallbackIncoming('external-queue-lost-ack');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded(['saved' => true])));
    config(['talkto.callbacks.reliable.recovery_enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    TalktoDispatchTestHooks::set('dispatch_intent.after_queue', fn () => throw new RuntimeException('synthetic queue acknowledgement lost'));
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0);
    TalktoDispatchTestHooks::reset();
    expect($incoming->fresh()->overall_status)->toBe('succeeded')
        ->and(TalktoDispatchIntent::query()->sole()->status)->toBe('unknown')
        ->and(TalktoDispatchIntent::query()->sole()->publication_attempts)->toBe(1)
        ->and(DB::connection('reliable_callback_broker')->table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::connection('reliable_callback_broker')->table('jobs')->count())->toBe(1)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
});

test('rolled back native queue acceptance leaves a callback claim safely recoverable after expiry', function (): void {
    reliableCallbackEnable();
    $incoming = reliableCallbackIncoming('atomic-queue-lost-ack');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, reliableCallbackResolver(TalktoIncomingCommandResult::succeeded()));
    config(['talkto.callbacks.reliable.recovery_enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    TalktoDispatchTestHooks::set('dispatch_intent.after_queue', fn () => throw new RuntimeException('synthetic atomic acknowledgement failure'));
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(TalktoDispatchIntent::query()->sole()->status)->toBe('claimed')
        ->and(TalktoDispatchIntent::query()->sole()->publication_attempts)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
    TalktoDispatchTestHooks::reset();
    TalktoDispatchIntent::query()->update(['claim_expires_at' => now()->subMinute()]);
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(TalktoDispatchIntent::query()->sole()->status)->toBe('published')
        ->and(TalktoDispatchIntent::query()->sole()->publication_attempts)->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($incoming->fresh()->overall_status)->toBe('succeeded')
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(1);
});

test('legacy retry dead letter and stale operations execute existing jobs without optional callback storage', function (string $operation): void {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql.' '.json_encode($query->bindings, JSON_THROW_ON_ERROR);
    });
    $client = new ReliableCallbackHttpClient;
    app()->instance(TalktoHttpClient::class, $client);
    $attributes = match ($operation) {
        'retry' => ['overall_status' => 'failed_retryable', 'transport_status' => 'failed_retryable', 'next_retry_at' => now()->subMinute(), 'next_attempt_at' => now()->subMinute()],
        'dead letter' => ['overall_status' => 'failed_final', 'transport_status' => 'failed_final', 'failed_at' => now()],
        'stale' => ['overall_status' => 'sending', 'transport_status' => 'sending', 'locked_at' => now()->subDay(), 'locked_by' => 'sender:synthetic-worker'],
    };
    $message = app(TalktoOutgoingMessageFactory::class)->create('source-service', 'domain.command', ['synthetic' => true], $attributes);
    $message->forceFill($attributes)->save();
    if ($operation === 'dead letter') {
        $deadLetter = app(TalktoDeadLetterQueue::class)->store($message, 'synthetic final failure');
        expect(Artisan::call('talkto:dlq-reprocess', ['--id' => $deadLetter->id, '--limit' => 10]))->toBe(0);
    } elseif ($operation === 'retry') {
        expect(Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing', '--limit' => 10]))->toBe(0);
    } else {
        expect(Artisan::call('talkto:recover-stale', ['--direction' => 'outgoing', '--older-than' => 1, '--limit' => 10]))->toBe(0);
    }
    $job = Queue::connection()->pop('callback-probe');
    expect($job)->not->toBeNull();
    $job->fire();
    $job->delete();
    expect($message->fresh()->overall_status)->toBe('sent')
        ->and(count($client->requests))->toBe(1)
        ->and(ReliableCallbackFeatureProbe::$handlerCalls)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(array_filter($queries, fn (string $sql): bool => str_contains(strtolower($sql), 'dispatch_intents')))->toBe([])
        ->and(Schema::hasTable('talkto_dispatch_intents'))->toBeFalse();
})->with(['retry', 'dead letter', 'stale']);

function reliableCallbackMigrateBase(): void
{
    $migrations = glob(__DIR__.'/../../database/migrations/*.php');
    sort($migrations);
    foreach ($migrations as $migration) {
        (require $migration)->up();
    }
}

function reliableCallbackCreateJobs(string $connection): void
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

function reliableCallbackEnable(): void
{
    config(['talkto.callbacks.reliable.enabled' => true, 'talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => false]);
    foreach (glob(__DIR__.'/../../database/dispatch-intents/*.php') as $migration) {
        (require $migration)->up();
    }
}

function reliableCallbackMessage(TalktoMessage $incoming): TalktoMessage
{
    return TalktoMessage::query()->where('direction', 'outgoing')->where('parent_message_id', $incoming->message_id)->sole();
}

function reliableCallbackIncoming(string $id, array $extra = []): TalktoMessage
{
    $payload = ['resource' => $id];

    return TalktoMessage::query()->create(array_merge([
        'message_id' => $id, 'correlation_id' => 'correlation-'.$id, 'direction' => 'incoming',
        'source_service' => 'source-service', 'target_service' => 'target-service', 'command' => 'domain.command',
        'business_key' => 'business-'.$id, 'idempotency_key' => 'idempotency-'.$id,
        'payload' => $payload, 'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload), 'schema_version' => 1,
        'destination_receive_status' => 'received', 'destination_action_status' => 'queued', 'overall_status' => 'queued',
        'attempts' => 0, 'retry_count' => 0, 'max_attempts' => 5, 'received_at' => now(),
    ], $extra));
}

function reliableCallbackResolver(IncomingCommandResultContract|Throwable $result, ?Closure $beforeReturn = null): object
{
    return new class($result, $beforeReturn)
    {
        public function __construct(private readonly IncomingCommandResultContract|Throwable $result, private readonly ?Closure $beforeReturn) {}

        public function resolve(TalktoMessage $message): TalktoIncomingCommandHandler
        {
            return new class($this->result, $this->beforeReturn) implements TalktoIncomingCommandHandler
            {
                public function __construct(private readonly IncomingCommandResultContract|Throwable $result, private readonly ?Closure $beforeReturn) {}

                public function handle(TalktoMessage $message): IncomingCommandResultContract
                {
                    ReliableCallbackFeatureProbe::$handlerCalls++;
                    if ($this->beforeReturn !== null) {
                        ($this->beforeReturn)($message);
                    }
                    if ($this->result instanceof Throwable) {
                        throw $this->result;
                    }

                    return $this->result;
                }
            };
        }
    };
}

class ReliableCallbackFeatureProbe
{
    public static int $handlerCalls = 0;

    public static int $senderCalls = 0;

    public static array $resultCalls = [];

    public static int $constructorCalls = 0;

    public static int $factoryResolverCalls = 0;

    public static ?Closure $cancelSaveWhen = null;
}

class ReliableCallbackOverriddenFactory extends TalktoResultCallbackMessageFactory
{
    public function createForIncomingResult(TalktoMessage $incomingMessage, IncomingCommandResultContract $result, array $options = []): TalktoMessage
    {
        ReliableCallbackFeatureProbe::$senderCalls++;

        return parent::createForIncomingResult($incomingMessage, $result, $options);
    }
}

class ReliableCallbackUniqueSendJob extends SendTalktoMessage implements ShouldBeUnique {}

class ReliableCallbackMiddlewareSendJob extends SendTalktoMessage
{
    public $middleware = ['synthetic-middleware'];
}

class ReliableCallbackCustomSendJob extends SendTalktoMessage
{
    public $queue = 'reliable-custom';
}

class ReliableCallbackConstructorSendJob extends SendTalktoMessage
{
    public function __construct(int $talktoMessageId)
    {
        ReliableCallbackFeatureProbe::$constructorCalls++;
        parent::__construct($talktoMessageId);
        $this->onQueue('constructor-custom');
    }
}

class ReliableCallbackForeignAttempt extends TalktoAttempt
{
    public function getConnectionName()
    {
        return 'reliable_callback_other';
    }
}

class ReliableCallbackForeignMessage extends TalktoMessage
{
    public function getConnectionName()
    {
        return 'reliable_callback_other';
    }
}

class ReliableCallbackForeignModelFactory extends TalktoOutgoingMessageFactory
{
    protected function messageModelClass(): string
    {
        ReliableCallbackFeatureProbe::$factoryResolverCalls++;

        return ReliableCallbackForeignMessage::class;
    }

    protected function eventModelClass(): string
    {
        ReliableCallbackFeatureProbe::$factoryResolverCalls++;

        return ReliableCallbackForeignEvent::class;
    }
}

class ReliableCallbackCustomMessage extends TalktoMessage {}

class ReliableCallbackCustomAttempt extends TalktoAttempt {}

class ReliableCallbackCustomEvent extends TalktoEvent {}

class ReliableCallbackCustomIntent extends TalktoDispatchIntent {}

class ReliableCallbackFailingMessage extends TalktoMessage
{
    public function save(array $options = [])
    {
        if ($this->direction === 'outgoing') {
            throw new RuntimeException('synthetic model persistence failure');
        }

        return parent::save($options);
    }
}

trait ReliableCallbackCancelSave
{
    public function save(array $options = [])
    {
        $when = ReliableCallbackFeatureProbe::$cancelSaveWhen;

        return $when !== null && $when($this)
            ? false
            : parent::save($options);
    }
}

class ReliableCallbackCancellingMessage extends TalktoMessage
{
    use ReliableCallbackCancelSave;
}

class ReliableCallbackCancellingAttempt extends TalktoAttempt
{
    use ReliableCallbackCancelSave;
}

class ReliableCallbackCancellingEvent extends TalktoEvent
{
    use ReliableCallbackCancelSave;
}

class ReliableCallbackCancellingIntent extends TalktoDispatchIntent
{
    use ReliableCallbackCancelSave;
}

class ReliableCallbackFailingAttempt extends TalktoAttempt
{
    public function save(array $options = [])
    {
        if ($this->status === 'succeeded') {
            throw new RuntimeException('synthetic model persistence failure');
        }

        return parent::save($options);
    }
}

class ReliableCallbackFailingEvent extends TalktoEvent
{
    public function save(array $options = [])
    {
        if ($this->event_type === 'result_callback_persisted') {
            throw new RuntimeException('synthetic model persistence failure');
        }

        return parent::save($options);
    }
}

class ReliableCallbackFailingIntent extends TalktoDispatchIntent
{
    public function save(array $options = [])
    {
        if ($this->purpose === 'result_callback') {
            throw new RuntimeException('synthetic model persistence failure');
        }

        return parent::save($options);
    }
}

class ReliableCallbackForeignEvent extends TalktoEvent
{
    public function getConnectionName()
    {
        return 'reliable_callback_other';
    }
}

class ReliableCallbackHttpClient implements TalktoHttpClient
{
    public array $requests = [];

    public function __construct(private readonly ?Closure $onPost = null) {}

    public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
    {
        $this->requests[] = compact('url', 'headers', 'envelope', 'timeout');

        return $this->onPost !== null
            ? ($this->onPost)($envelope, $headers)
            : new TalktoHttpResponse(200, json_encode(['accepted' => true, 'status' => 'applied'], JSON_THROW_ON_ERROR));
    }
}

class ReliableCallbackCustomSender implements ResultCallbackSenderContract
{
    public function sendResult(Model $message, IncomingCommandResultContract $result, array $options = []): mixed
    {
        ReliableCallbackFeatureProbe::$senderCalls++;

        return ['sent' => false, 'queued' => false, 'custom' => true];
    }
}

class ReliableCallbackCountingResult implements IncomingCommandResultContract
{
    private function called(string $method): int
    {
        return ReliableCallbackFeatureProbe::$resultCalls[$method] = (ReliableCallbackFeatureProbe::$resultCalls[$method] ?? 0) + 1;
    }

    public function isSucceeded(): bool
    {
        $this->called(__FUNCTION__);

        return true;
    }

    public function isRetryable(): bool
    {
        $this->called(__FUNCTION__);

        return false;
    }

    public function isSkipped(): bool
    {
        $this->called(__FUNCTION__);

        return false;
    }

    public function errorClass(): ?string
    {
        $this->called(__FUNCTION__);

        return null;
    }

    public function errorMessage(): ?string
    {
        $this->called(__FUNCTION__);

        return null;
    }

    public function result(): array
    {
        return ['snapshot' => $this->called(__FUNCTION__)];
    }

    public function meta(): array
    {
        return ['snapshot' => $this->called(__FUNCTION__)];
    }
}
