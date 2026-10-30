<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Exceptions\TalktoReliableCallbackPersistenceException;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoDispatchIntent;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Pipelines\ProcessIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoDispatchIntentService;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResolver;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackSender;
use Mrezdev\LaravelTalkto\Services\TalktoStaleMessageRecoveryService;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    RetryCallbackCompatibilityHandler::$calls = 0;
    RetryCallbackCompatibilityHandler::$results = [];
    RetryCallbackCompatibilitySender::$calls = 0;
    config([
        'talkto.service' => 'retry-target',
        'talkto.callbacks.enabled' => true,
        'talkto.callbacks.auto_dispatch' => true,
        'talkto.callbacks.reliable.enabled' => true,
        'talkto.callbacks.reliable.recovery_enabled' => false,
        'talkto.dispatch_intents.enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false,
        'talkto.retry.enabled' => true,
        'talkto.retry.incoming_enabled' => true,
        'talkto.retry.backoff_seconds' => [10, 30, 60, 120, 300],
        'talkto.retry.jitter_seconds' => 0,
        'talkto.jobs.send_message' => RetryCallbackCompatibilitySendJob::class,
        'talkto.outgoing.retry-source' => ['url' => 'https://retry-callback.invalid', 'secret' => 'synthetic-retry-callback-secret'],
        'talkto.incoming.retry-source.allowed_commands' => ['retry.command' => ['handler' => RetryCallbackCompatibilityHandler::class]],
        'queue.default' => 'retry_callback_database',
        'queue.connections.retry_callback_database' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'retry-callback', 'retry_after' => 90, 'after_commit' => false],
    ]);
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    expect($this->artisan('migrate')->run())->toBe(0);
    foreach (glob(__DIR__.'/../../database/dispatch-intents/*.php') as $migration) {
        (require $migration)->up();
    }
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
    DB::purge('retry_callback_external');
    DB::purge('retry_callback_named');
    DB::purge('retry_callback_foreign_legacy');
});

test('independent same-status retry outcomes retain exact metadata and payload identity', function (bool $identical): void {
    $this->freezeTime();
    $metaA = ['nested' => ['unicode' => 'سلام 世界', 'empty' => [], 'null' => null, 'version' => 1]];
    $metaB = $identical ? $metaA : ['nested' => ['unicode' => 'سلام 世界', 'empty' => [], 'null' => null, 'version' => 2]];
    $results = [
        TalktoIncomingCommandResult::failedRetryable('same error', 'temporary', $metaA),
        TalktoIncomingCommandResult::failedRetryable('same error', 'temporary', $metaB),
    ];
    RetryCallbackCompatibilityHandler::$results = $results;
    $incoming = retryCallbackCompatibilityIncoming('metadata-'.(int) $identical);
    retryCallbackCompatibilityProcess($incoming);
    $first = TalktoMessage::query()->where('direction', 'outgoing')->sole()->getRawOriginal();
    retryCallbackCompatibilityRetry($this, $incoming);
    $callbacks = TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get();

    expect($incoming->fresh()->overall_status)->toBe('failed_retryable')
        ->and($incoming->fresh()->retry_count)->toBe(2)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2)
        ->and($callbacks)->toHaveCount(2)
        ->and($callbacks[0]->getRawOriginal())->toBe($first)
        ->and($callbacks[1]->message_id)->not->toBe($callbacks[0]->message_id)
        ->and($callbacks[1]->idempotency_key)->not->toBe($callbacks[0]->idempotency_key)
        ->and($callbacks[0]->payload['meta'])->toBe($metaA)
        ->and($callbacks[1]->payload['meta'])->toBe($metaB)
        ->and($callbacks[0]->payload_hash === $callbacks[1]->payload_hash)->toBe($identical)
        ->and(TalktoDispatchIntent::query()->count())->toBe(2)
        ->and(TalktoAttempt::query()->orderBy('attempt_no')->pluck('attempt_no')->all())->toBe([1, 2]);
    retryCallbackCompatibilityEvidence('metadata-'.(int) $identical, retryCallbackCompatibilitySnapshot($incoming));
})->with(['different nested metadata' => false, 'identical independent result payloads' => true]);

test('legitimate incoming retries preserve normal terminal transitions and retry exhaustion', function (string $outcome): void {
    $this->freezeTime();
    $failureA = TalktoIncomingCommandResult::failedRetryable('Network timeout A', 'temporary', ['result_number' => 1]);
    $failureB = TalktoIncomingCommandResult::failedRetryable('Network timeout B', 'temporary', ['result_number' => 2]);
    [$results, $expectedStatus, $expectedRetryCount] = match ($outcome) {
        'success' => [[$failureA, TalktoIncomingCommandResult::succeeded(['applied' => true])], 'succeeded', 1],
        'final' => [[$failureA, TalktoIncomingCommandResult::failedFinal('definitive rejection', 'business')], 'failed_final', 1],
        'skipped' => [[$failureA, TalktoIncomingCommandResult::skipped('already applied')], 'skipped', 1],
        'eventual success' => [[$failureA, $failureB, TalktoIncomingCommandResult::succeeded(['applied' => true])], 'succeeded', 2],
        'exhaustion' => [[$failureA, $failureB, TalktoIncomingCommandResult::failedRetryable('Network timeout C', 'temporary')], 'failed_final', 2],
        'handler exception' => [[new RuntimeException('exception A'), new RuntimeException('exception B'), TalktoIncomingCommandResult::succeeded()], 'succeeded', 2],
    };
    RetryCallbackCompatibilityHandler::$results = $results;
    $incoming = retryCallbackCompatibilityIncoming('transition-'.str_replace(' ', '-', $outcome), ['max_attempts' => 3]);
    retryCallbackCompatibilityProcess($incoming);
    $first = TalktoMessage::query()->where('direction', 'outgoing')->sole()->getRawOriginal();
    for ($i = 1; $i < count($results); $i++) {
        retryCallbackCompatibilityRetry($this, $incoming);
    }
    $fresh = $incoming->fresh();
    $callbacks = TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get();
    expect($fresh->overall_status)->toBe($expectedStatus)
        ->and($fresh->destination_action_status)->toBe($expectedStatus)
        ->and($fresh->retry_count)->toBe($expectedRetryCount)
        ->and($fresh->getAttribute('attempts'))->toBe(count($results))
        ->and($fresh->next_retry_at)->toBeNull()
        ->and($fresh->next_attempt_at)->toBeNull()
        ->and($fresh->locked_at)->toBeNull()
        ->and($fresh->locked_by)->toBeNull()
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(count($results))
        ->and($callbacks)->toHaveCount(count($results))
        ->and($callbacks[0]->getRawOriginal())->toBe($first)
        ->and($callbacks->pluck('message_id')->unique())->toHaveCount(count($results))
        ->and(TalktoDispatchIntent::query()->count())->toBe(count($results))
        ->and(TalktoAttempt::query()->orderBy('attempt_no')->pluck('attempt_no')->all())->toBe(range(1, count($results)))
        ->and(TalktoDeadLetter::query()->where('message_id', $incoming->message_id)->exists())->toBe($expectedStatus === 'failed_final');
    if ($outcome === 'exhaustion') {
        expect($callbacks->pluck('payload.status')->all())->toBe(['failed_retryable', 'failed_retryable', 'failed_retryable'])
            ->and($callbacks->last()->payload['retryable'])->toBeTrue()
            ->and(TalktoAttempt::query()->orderByDesc('attempt_no')->first()->status)->toBe('failed_final');
    }
    $before = $fresh->getRawOriginal();
    retryCallbackCompatibilityProcess($incoming);
    expect($incoming->fresh()->getRawOriginal())->toBe($before)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(count($results));
    retryCallbackCompatibilityEvidence('transition-'.$outcome, retryCallbackCompatibilitySnapshot($incoming));
})->with(['success', 'final', 'skipped', 'eventual success', 'exhaustion', 'handler exception']);

test('retry backoff counters and eligibility remain unchanged after each same-status outcome', function (): void {
    $this->freezeTime();
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), TalktoIncomingCommandResult::failedRetryable('B')];
    $incoming = retryCallbackCompatibilityIncoming('backoff-sequence');
    retryCallbackCompatibilityProcess($incoming);
    $first = $incoming->fresh();
    expect($first->next_retry_at->equalTo($first->next_attempt_at))->toBeTrue()
        ->and((int) $first->last_attempted_at->diffInSeconds($first->next_retry_at))->toBe(10);
    retryCallbackCompatibilityProcess($incoming);
    expect(RetryCallbackCompatibilityHandler::$calls)->toBe(1)
        ->and(TalktoAttempt::query()->count())->toBe(1);
    expect(Artisan::call('talkto:retry-failed', ['--direction' => 'incoming', '--limit' => 1]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
    retryCallbackCompatibilityRetry($this, $incoming);
    $second = $incoming->fresh();
    expect($second->retry_count)->toBe(2)
        ->and($second->next_retry_at->equalTo($second->next_attempt_at))->toBeTrue()
        ->and((int) $second->last_attempted_at->diffInSeconds($second->next_retry_at))->toBe(30)
        ->and(TalktoAttempt::query()->orderBy('attempt_no')->pluck('status')->all())->toBe(['failed_retryable', 'failed_retryable']);
});

test('published callback history is preserved when the next attempt reports another retryable failure', function (bool $acceptedTransport): void {
    $this->freezeTime();
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), TalktoIncomingCommandResult::failedRetryable('B')];
    $incoming = retryCallbackCompatibilityIncoming('published-history-'.(int) $acceptedTransport);
    retryCallbackCompatibilityProcess($incoming);
    $firstCallback = TalktoMessage::query()->where('direction', 'outgoing')->sole();
    $firstIntent = TalktoDispatchIntent::query()->sole();
    app(TalktoDispatchIntentService::class)->publish($firstIntent->id);
    expect($firstIntent->fresh()->status)->toBe('published');
    if ($acceptedTransport) {
        app()->instance(TalktoHttpClient::class, new RetryCallbackCompatibilityHttpClient);
        $job = Queue::connection()->pop('retry-results');
        expect($job)->not->toBeNull();
        $job->fire();
        $job->delete();
        expect($firstCallback->fresh()->overall_status)->toBe('completed');
    }
    $callbackBefore = $firstCallback->fresh()->getRawOriginal();
    $intentBefore = $firstIntent->fresh()->getRawOriginal();
    retryCallbackCompatibilityRetry($this, $incoming);
    expect($firstCallback->fresh()->getRawOriginal())->toBe($callbackBefore)
        ->and($firstIntent->fresh()->getRawOriginal())->toBe($intentBefore)
        ->and($incoming->fresh()->retry_count)->toBe(2)
        ->and($incoming->fresh()->overall_status)->toBe('failed_retryable')
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(2)
        ->and(TalktoDispatchIntent::query()->count())->toBe(2)
        ->and(DB::table('jobs')->where('queue', 'retry-results')->count())->toBe($acceptedTransport ? 0 : 1);
    retryCallbackCompatibilityEvidence('published-history-'.(int) $acceptedTransport, retryCallbackCompatibilitySnapshot($incoming));
})->with(['actually published' => false, 'accepted controlled HTTP transport' => true]);

test('same logical attempt manual replay is idempotent while a changed replay still fails closed', function (): void {
    $this->freezeTime();
    $firstResult = TalktoIncomingCommandResult::failedRetryable('A', 'temporary', ['version' => 1]);
    $secondResult = TalktoIncomingCommandResult::failedRetryable('B', 'temporary', ['version' => 2]);
    RetryCallbackCompatibilityHandler::$results = [$firstResult, $secondResult];
    $incoming = retryCallbackCompatibilityIncoming('manual-replay');
    retryCallbackCompatibilityProcess($incoming);
    $sender = app(ResultCallbackSenderContract::class);
    $replayFirst = $sender->sendResult($incoming->fresh(), $firstResult);
    expect($replayFirst['duplicate'])->toBeTrue()
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(1)
        ->and(TalktoDispatchIntent::query()->count())->toBe(1);
    retryCallbackCompatibilityRetry($this, $incoming);
    $beforeCallbacks = TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get()->map->getRawOriginal()->all();
    $beforeIntents = TalktoDispatchIntent::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    $replaySecond = $sender->sendResult($incoming->fresh(), $secondResult);
    expect($replaySecond['duplicate'])->toBeTrue()
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(2)
        ->and(TalktoDispatchIntent::query()->count())->toBe(2);
    expect(fn () => $sender->sendResult($incoming->fresh(), TalktoIncomingCommandResult::failedRetryable('forged changed same attempt')))->toThrow(TalktoReliableCallbackPersistenceException::class);
    expect(TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($beforeCallbacks)
        ->and(TalktoDispatchIntent::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($beforeIntents)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2)
        ->and($incoming->fresh()->overall_status)->toBe('failed_retryable');
});

test('identical attempt timestamps cannot collide with the next independent result', function (): void {
    $this->freezeTime();
    config(['talkto.retry.backoff_seconds' => [0]]);
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('identical'), TalktoIncomingCommandResult::failedRetryable('identical')];
    $incoming = retryCallbackCompatibilityIncoming('same-timestamp');
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming, false);
    $attempts = TalktoAttempt::query()->orderBy('attempt_no')->get();
    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->getRawOriginal('created_at'))->toBe($attempts[1]->getRawOriginal('created_at'))
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->pluck('message_id')->unique())->toHaveCount(2)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->pluck('payload_hash')->unique())->toHaveCount(1)
        ->and($incoming->fresh()->retry_count)->toBe(2);
});

test('later manual replay requires unique retained reliable processing evidence', function (string $corruption): void {
    $this->freezeTime();
    $resultA = TalktoIncomingCommandResult::failedRetryable('A');
    $resultB = TalktoIncomingCommandResult::failedRetryable('B');
    RetryCallbackCompatibilityHandler::$results = [$resultA, $resultB];
    $incoming = retryCallbackCompatibilityIncoming('retained-evidence-'.$corruption);
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    $attempt = TalktoAttempt::query()->where('attempt_no', 2)->sole();
    if ($corruption === 'missing') {
        $attempt->delete();
    } elseif ($corruption === 'unmarked') {
        $meta = $attempt->meta;
        unset($meta['reliable_callback_claim']);
        $attempt->forceFill(['meta' => $meta])->save();
    } else {
        $duplicate = $attempt->getRawOriginal();
        unset($duplicate['id']);
        DB::table($attempt->getTable())->insert($duplicate);
    }
    $callbacks = TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get()->map->getRawOriginal()->all();
    $intents = TalktoDispatchIntent::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    expect(fn () => app(ResultCallbackSenderContract::class)->sendResult($incoming->fresh(), $resultB))->toThrow(TalktoReliableCallbackPersistenceException::class);
    expect(TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($callbacks)
        ->and(TalktoDispatchIntent::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($intents)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2)
        ->and($incoming->fresh()->retry_count)->toBe(2)
        ->and($incoming->fresh()->overall_status)->toBe('failed_retryable');
})->with(['missing', 'unmarked', 'ambiguous']);

test('a changed status cannot create a second immutable result for the same later attempt', function (): void {
    $this->freezeTime();
    $resultA = TalktoIncomingCommandResult::failedRetryable('A');
    $resultB = TalktoIncomingCommandResult::failedRetryable('B');
    RetryCallbackCompatibilityHandler::$results = [$resultA, $resultB];
    $incoming = retryCallbackCompatibilityIncoming('same-attempt-different-status');
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    $latest = TalktoMessage::query()->where('direction', 'outgoing')->orderByDesc('id')->first();
    $before = $latest->getRawOriginal();
    $sender = app(ResultCallbackSenderContract::class);
    expect($sender->sendResult($incoming->fresh(), $resultB, ['callback_message_id' => $latest->message_id])['duplicate'])->toBeTrue();
    expect(fn () => $sender->sendResult($incoming->fresh(), TalktoIncomingCommandResult::succeeded(['fabricated' => true])))->toThrow(TalktoReliableCallbackPersistenceException::class);
    expect($latest->fresh()->getRawOriginal())->toBe($before)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(2)
        ->and(TalktoDispatchIntent::query()->count())->toBe(2)
        ->and($incoming->fresh()->overall_status)->toBe('failed_retryable')
        ->and($incoming->fresh()->last_error)->toBe('B');
});

test('manual replay never manufactures a missing later callback beside immutable earlier history', function (bool $acceptedTransport): void {
    $this->freezeTime();
    $result = TalktoIncomingCommandResult::failedRetryable('identical retryable result', 'temporary', ['stable' => true]);
    RetryCallbackCompatibilityHandler::$results = [$result, $result];
    $incoming = retryCallbackCompatibilityIncoming('missing-later-history-'.(int) $acceptedTransport);
    retryCallbackCompatibilityProcess($incoming);
    $earlierCallback = TalktoMessage::query()->where('direction', 'outgoing')->sole();
    $earlierIntent = TalktoDispatchIntent::query()->sole();
    app(TalktoDispatchIntentService::class)->publish($earlierIntent->id);
    if ($acceptedTransport) {
        app()->instance(TalktoHttpClient::class, new RetryCallbackCompatibilityHttpClient);
        $job = Queue::connection()->pop('retry-results');
        expect($job)->not->toBeNull();
        $job->fire();
        $job->delete();
        expect($earlierCallback->fresh()->overall_status)->toBe('completed');
    }
    retryCallbackCompatibilityRetry($this, $incoming);
    $later = TalktoMessage::query()->where('direction', 'outgoing')->orderByDesc('id')->first();
    expect($later->message_id)->not->toBe($earlierCallback->message_id);
    // Deliberately remove only the later callback/intent to model missing records.
    // This is a controlled retention/history shape, not an old deployment run.
    TalktoDispatchIntent::query()->where('message_id', $later->message_id)->delete();
    $later->delete();
    $callbackBefore = $earlierCallback->fresh()->getRawOriginal();
    $intentBefore = $earlierIntent->fresh()->getRawOriginal();
    $before = retryCallbackCompatibilitySnapshot($incoming);
    $exception = null;
    $summary = null;
    try {
        $summary = app(ResultCallbackSenderContract::class)->sendResult($incoming->fresh(), $result);
    } catch (Throwable $failure) {
        $exception = $failure;
    }
    retryCallbackCompatibilityEvidence('missing-later-records-'.(int) $acceptedTransport, [
        'model' => 'Two actual current processing attempts, followed by deliberate deletion of only later callback and intent; not an actual old deployment',
        'before' => $before, 'after' => retryCallbackCompatibilitySnapshot($incoming), 'summary' => $summary,
        'exception' => $exception === null ? null : ['class' => $exception::class, 'message' => $exception->getMessage()],
    ]);
    expect($exception)->toBeInstanceOf(TalktoReliableCallbackPersistenceException::class)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(1)
        ->and(TalktoDispatchIntent::query()->count())->toBe(1)
        ->and($earlierCallback->fresh()->getRawOriginal())->toBe($callbackBefore)
        ->and($earlierIntent->fresh()->getRawOriginal())->toBe($intentBefore)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2)
        ->and($incoming->fresh()->overall_status)->toBe('failed_retryable')
        ->and($incoming->fresh()->retry_count)->toBe(2);
})->with(['previously published' => false, 'previously accepted by controlled HTTP' => true]);

test('manual replay refuses an orphan later callback without verified publication evidence', function (): void {
    $this->freezeTime();
    $resultA = TalktoIncomingCommandResult::failedRetryable('A');
    $resultB = TalktoIncomingCommandResult::failedRetryable('B');
    RetryCallbackCompatibilityHandler::$results = [$resultA, $resultB];
    $incoming = retryCallbackCompatibilityIncoming('orphan-later-intent');
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    $later = TalktoMessage::query()->where('direction', 'outgoing')->orderByDesc('id')->first();
    TalktoDispatchIntent::query()->where('message_id', $later->message_id)->delete();
    $before = $later->getRawOriginal();
    expect(fn () => app(ResultCallbackSenderContract::class)->sendResult($incoming->fresh(), $resultB))->toThrow(TalktoReliableCallbackPersistenceException::class);
    expect($later->fresh()->getRawOriginal())->toBe($before)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(2)
        ->and(TalktoDispatchIntent::query()->count())->toBe(1)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
});

test('manual attempt replay refuses raw callback direction or idempotency fingerprint corruption', function (string $field): void {
    $this->freezeTime();
    $resultA = TalktoIncomingCommandResult::failedRetryable('A');
    $resultB = TalktoIncomingCommandResult::failedRetryable('B');
    RetryCallbackCompatibilityHandler::$results = [$resultA, $resultB];
    $incoming = retryCallbackCompatibilityIncoming('manual-binding-'.$field);
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    $later = TalktoMessage::query()->where('direction', 'outgoing')->orderByDesc('id')->first();
    DB::table($later->getTable())->where('id', $later->id)->update([$field => $field === 'direction' ? 'incoming' : 'synthetic-invalid-fingerprint']);
    $before = $later->fresh()->getRawOriginal();
    $intents = TalktoDispatchIntent::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    expect(fn () => app(ResultCallbackSenderContract::class)->sendResult($incoming->fresh(), $resultB))->toThrow(TalktoReliableCallbackPersistenceException::class);
    expect($later->fresh()->getRawOriginal())->toBe($before)
        ->and(TalktoMessage::query()->where('parent_message_id', $incoming->message_id)->count())->toBe(2)
        ->and(TalktoDispatchIntent::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($intents)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2)
        ->and($incoming->fresh()->retry_count)->toBe(2);
})->with(['direction', 'idempotency_fingerprint']);

test('disabling reliable mode reuses later callback history without another legacy publication', function (string $state, bool $dropIntents): void {
    $this->freezeTime();
    if ($state === 'unknown') {
        config([
            'database.connections.retry_callback_external' => config('database.connections.sqlite'),
            'queue.connections.retry_callback_external' => ['driver' => 'database', 'connection' => 'retry_callback_external', 'table' => 'jobs', 'queue' => 'retry-results', 'retry_after' => 90, 'after_commit' => false],
            'talkto.jobs.send_message' => RetryCallbackCompatibilityExternalSendJob::class,
        ]);
        retryCallbackCompatibilityCreateJobs('retry_callback_external');
    }
    $result = TalktoIncomingCommandResult::succeeded(['actual' => 2], ['nested' => ['unicode' => 'سلام', 'empty' => []]]);
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), $result];
    $incoming = retryCallbackCompatibilityIncoming('disabled-later-'.$state.'-'.(int) $dropIntents);
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    $callback = TalktoMessage::query()->where('direction', 'outgoing')->orderByDesc('id')->first();
    $intent = TalktoDispatchIntent::query()->where('message_id', $callback->message_id)->sole();
    if ($state !== 'ready') {
        if ($state === 'unknown') {
            TalktoDispatchTestHooks::set('dispatch_intent.after_queue', fn () => throw new RuntimeException('synthetic accepted publication acknowledgement loss'));
        }
        app(TalktoDispatchIntentService::class)->publish($intent->id);
        TalktoDispatchTestHooks::reset();
    }
    if ($state === 'accepted') {
        app()->instance(TalktoHttpClient::class, new RetryCallbackCompatibilityHttpClient);
        $job = Queue::connection()->pop('retry-results');
        expect($job)->not->toBeNull();
        $job->fire();
        $job->delete();
        expect($callback->fresh()->overall_status)->toBe('completed');
    }
    expect($intent->fresh()->status)->toBe(match ($state) {
        'ready' => 'ready', 'unknown' => 'unknown', default => 'published',
    });
    $callbacksBefore = TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get()->map->getRawOriginal()->all();
    $intentBefore = $intent->fresh()->getRawOriginal();
    $broker = $state === 'unknown' ? 'retry_callback_external' : 'sqlite';
    $jobsBefore = DB::connection($broker)->table('jobs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    config(['talkto.callbacks.reliable.enabled' => false, 'talkto.dispatch_intents.enabled' => false]);
    if ($dropIntents) {
        Schema::drop('talkto_dispatch_intents');
    }
    $intentQueries = [];
    $operationQueries = [];
    DB::listen(function ($query) use (&$intentQueries, &$operationQueries): void {
        $operationQueries[] = $query->sql;
        if (str_contains($query->sql, 'talkto_dispatch_intents')) {
            $intentQueries[] = $query->sql;
        }
    });
    $summary = app(ResultCallbackSenderContract::class)->sendResult($incoming->fresh(), $result);
    $operationSql = $operationQueries;
    $after = TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get()->map->getRawOriginal()->all();
    $jobsAfter = DB::connection($broker)->table('jobs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    retryCallbackCompatibilityEvidence('disabled-later-'.$state.'-'.(int) $dropIntents, [
        'callback_state' => $state, 'optional_ledger_dropped' => $dropIntents, 'callbacks_before' => $callbacksBefore, 'callbacks_after' => $after,
        'jobs_before' => $jobsBefore, 'jobs_after' => $jobsAfter, 'summary' => $summary, 'intent_queries' => $intentQueries,
        'operation_sql_count' => count($operationSql), 'operation_sql' => $operationSql,
    ]);
    expect($after)->toBe($callbacksBefore)
        ->and($jobsAfter)->toBe($jobsBefore)
        ->and($summary['queued'])->toBeFalse()
        ->and($summary['duplicate'])->toBeTrue()
        ->and($summary['callback_message_id'])->toBe($callback->message_id)
        ->and($intentQueries)->toBe([])
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
    if (! $dropIntents) {
        expect($intent->fresh()->getRawOriginal())->toBe($intentBefore);
    }
})->with([
    'ready callback' => ['ready', false], 'actually published' => ['published', false],
    'controlled HTTP accepted' => ['accepted', false], 'actual separate broker unknown' => ['unknown', false],
    'published with optional ledger absent' => ['published', true],
]);

test('disabled mode refuses a custom id that would bypass later reliable publication history', function (): void {
    $this->freezeTime();
    $result = TalktoIncomingCommandResult::succeeded(['actual' => 2]);
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), $result];
    $incoming = retryCallbackCompatibilityIncoming('disabled-custom-id');
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    $callbacks = TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get()->map->getRawOriginal()->all();
    config(['talkto.callbacks.reliable.enabled' => false, 'talkto.dispatch_intents.enabled' => false]);
    Schema::drop('talkto_dispatch_intents');
    $summary = app(ResultCallbackSenderContract::class)->sendResult($incoming->fresh(), $result, ['callback_message_id' => 'different-disabled-custom-id']);
    expect($summary['queued'])->toBeFalse()
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($callbacks)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
});

test('disabled mode does not fall back when its later reliable event points at missing or incompatible history', function (string $field): void {
    $this->freezeTime();
    $result = TalktoIncomingCommandResult::succeeded(['actual' => 2]);
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), $result];
    $incoming = retryCallbackCompatibilityIncoming('disabled-incompatible-'.$field);
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    $later = TalktoMessage::query()->where('direction', 'outgoing')->orderByDesc('id')->first();
    if ($field === 'missing') {
        $later->delete();
    } else {
        DB::table($later->getTable())->where('id', $later->id)->update([$field => $field === 'payload_hash' ? str_repeat('0', 64) : 'synthetic-unrelated-parent']);
    }
    $before = TalktoMessage::query()->where('parent_message_id', $incoming->message_id)->orWhere('id', $later->id)->orderBy('id')->get()->map->getRawOriginal()->all();
    config(['talkto.callbacks.reliable.enabled' => false, 'talkto.dispatch_intents.enabled' => false]);
    Schema::drop('talkto_dispatch_intents');
    $summary = app(ResultCallbackSenderContract::class)->sendResult($incoming->fresh(), $result);
    expect($summary['queued'])->toBeFalse()
        ->and($summary['status'])->toBe('failed')
        ->and(TalktoMessage::query()->where('parent_message_id', $incoming->message_id)->orWhere('id', $later->id)->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
})->with(['missing', 'payload_hash', 'parent_message_id']);

test('disabled later replay samples custom result accessors once while reusing the actual callback', function (): void {
    $this->freezeTime();
    $handlerResult = new RetryCallbackCompatibilityCountingResult;
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), $handlerResult];
    $incoming = retryCallbackCompatibilityIncoming('disabled-counting-contract');
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    $callback = TalktoMessage::query()->where('direction', 'outgoing')->orderByDesc('id')->first();
    $before = $callback->getRawOriginal();
    $manualResult = new RetryCallbackCompatibilityCountingResult;
    config(['talkto.callbacks.reliable.enabled' => false, 'talkto.dispatch_intents.enabled' => false]);
    Schema::drop('talkto_dispatch_intents');
    $summary = app(ResultCallbackSenderContract::class)->sendResult($incoming->fresh(), $manualResult);
    $expectedReads = ['isSucceeded' => 1, 'isRetryable' => 1, 'isSkipped' => 1, 'errorClass' => 1, 'errorMessage' => 1, 'result' => 1, 'meta' => 1];
    expect($summary['queued'])->toBeFalse()
        ->and($summary['callback_message_id'])->toBe($callback->message_id)
        ->and($manualResult->reads)->toBe($expectedReads)
        ->and($handlerResult->reads)->toBe($expectedReads)
        ->and($callback->fresh()->getRawOriginal())->toBe($before)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('legacy factory only calls preserve cross connection storage when no reliable evidence exists', function (): void {
    config([
        'talkto.callbacks.reliable.enabled' => false,
        'talkto.dispatch_intents.enabled' => false,
        'database.connections.retry_callback_foreign_legacy' => config('database.connections.sqlite'),
        'talkto.database.connection' => 'retry_callback_foreign_legacy',
    ]);
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migration) {
        (require $migration)->up();
    }
    $incoming = retryCallbackCompatibilityIncoming('legacy-cross-connection-factory', ['attempts' => 2, 'retry_count' => 1]);
    $incoming->setConnection('retry_callback_foreign_legacy');
    config(['talkto.database.connection' => null]);
    Schema::drop('talkto_dispatch_intents');
    $result = TalktoIncomingCommandResult::succeeded(['factory_only' => true]);
    $path = __DIR__.'/../Fixtures/RetryCompatibilityBaseline/TalktoResultCallbackMessageFactory.php';
    $baselineClass = 'Mrezdev\\LaravelTalkto\\Services\\RetryCompatibilityCrossConnectionBaselineFactory';
    if (! class_exists($baselineClass, false)) {
        $source = file_get_contents($path);
        eval(str_replace('class TalktoResultCallbackMessageFactory', 'class RetryCompatibilityCrossConnectionBaselineFactory', substr($source, strlen('<?php'))));
    }
    $baseline = new $baselineClass(app(TalktoOutgoingMessageFactory::class));
    $oldCallback = $baseline->createForIncomingResult($incoming, $result);
    expect(hash_file('sha256', $path))->toBe('fa05d12d9c9d7d4caa28d930c62eda6e9afbe1c8a476722592867a480708503a')
        ->and($oldCallback->getConnection())->not->toBe($incoming->getConnection())
        ->and($oldCallback->message_id)->toBe('cb-'.sha1($incoming->message_id.'|succeeded'))
        ->and(DB::connection('sqlite')->table('talkto_events')->where('event_type', 'result_callback_persisted')->count())->toBe(0);
    $before = $oldCallback->fresh()->getRawOriginal();
    $failure = null;
    $current = null;
    try {
        $current = app(TalktoResultCallbackMessageFactory::class)->createForIncomingResult($incoming, $result);
    } catch (Throwable $exception) {
        $failure = ['class' => $exception::class, 'message' => $exception->getMessage()];
    }
    retryCallbackCompatibilityEvidence('legacy-cross-connection-factory', [
        'actual_baseline_factory_succeeded' => true, 'baseline_factory_sha256' => hash_file('sha256', $path),
        'baseline_callback' => $before, 'current_exception' => $failure, 'current_callback_id' => $current?->message_id,
        'scope' => 'Public factory-only API with reliable mode disabled, two-attempt incoming row on another actual connection and no reliable coordination events; no sender/publication invoked',
    ]);
    expect($failure)->toBeNull()
        ->and($current->message_id)->toBe($oldCallback->message_id)
        ->and($oldCallback->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::connection('sqlite')->table('talkto_messages')->where('direction', 'outgoing')->count())->toBe(1)
        ->and(DB::connection('retry_callback_foreign_legacy')->table('talkto_messages')->where('direction', 'incoming')->count())->toBe(1)
        ->and(DB::connection('retry_callback_foreign_legacy')->table('talkto_messages')->where('direction', 'outgoing')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('losing the second processing claim cannot finalize its result or mutate previous callback history', function (): void {
    $this->freezeTime();
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), TalktoIncomingCommandResult::failedRetryable('B')];
    $incoming = retryCallbackCompatibilityIncoming('second-attempt-lost-fence');
    retryCallbackCompatibilityProcess($incoming);
    $first = TalktoMessage::query()->where('direction', 'outgoing')->sole()->getRawOriginal();
    TalktoDispatchTestHooks::set('reliable_callback.after_handler', function () use ($incoming): void {
        if (RetryCallbackCompatibilityHandler::$calls === 2) {
            DB::table('talkto_messages')->where('id', $incoming->id)->update(['locked_by' => 'reliable-callback:synthetic-different-owner']);
        }
    });
    expect(fn () => retryCallbackCompatibilityRetry($this, $incoming))->toThrow(TalktoReliableCallbackPersistenceException::class);
    expect($incoming->fresh()->overall_status)->toBe('processing')
        ->and($incoming->fresh()->retry_count)->toBe(1)
        ->and(TalktoAttempt::query()->orderBy('attempt_no')->pluck('status')->all())->toBe(['failed_retryable', 'processing'])
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->sole()->getRawOriginal())->toBe($first)
        ->and(TalktoDispatchIntent::query()->count())->toBe(1)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
});

test('second attempt result rollback keeps existing callback and prevents automatic handler replay', function (string $hook): void {
    $this->freezeTime();
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), TalktoIncomingCommandResult::failedRetryable('B')];
    $incoming = retryCallbackCompatibilityIncoming('second-rollback-'.str_replace('.', '-', $hook));
    retryCallbackCompatibilityProcess($incoming);
    $first = TalktoMessage::query()->where('direction', 'outgoing')->sole()->getRawOriginal();
    TalktoDispatchTestHooks::set($hook, fn () => throw new RuntimeException('synthetic second result transaction rollback'));
    expect(fn () => retryCallbackCompatibilityRetry($this, $incoming))->toThrow(TalktoReliableCallbackPersistenceException::class);
    TalktoDispatchTestHooks::reset();
    retryCallbackCompatibilityProcess($incoming);
    config(['talkto.callbacks.reliable.enabled' => false]);
    $incoming->fresh()->forceFill(['locked_at' => now()->subDay()])->save();
    $stale = app(TalktoStaleMessageRecoveryService::class)->recover('incoming', 0, 10, false);
    expect($incoming->fresh()->overall_status)->toBe('processing')
        ->and($incoming->fresh()->locked_by)->toStartWith('reliable-callback:')
        ->and($incoming->fresh()->retry_count)->toBe(1)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->sole()->getRawOriginal())->toBe($first)
        ->and(TalktoDispatchIntent::query()->count())->toBe(1)
        ->and($stale['recovered'])->toBe(0)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
})->with(['reliable_callback.result_persisted', 'reliable_callback.callback_persisted', 'reliable_callback.before_commit']);

test('second committed result is recoverable without executing either incoming handler attempt again', function (): void {
    $this->freezeTime();
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), TalktoIncomingCommandResult::failedRetryable('B')];
    $incoming = retryCallbackCompatibilityIncoming('second-postcommit');
    retryCallbackCompatibilityProcess($incoming);
    TalktoDispatchTestHooks::set('reliable_callback.committed', fn () => throw new RuntimeException('synthetic postcommit interruption'));
    expect(fn () => retryCallbackCompatibilityRetry($this, $incoming))->toThrow(RuntimeException::class);
    TalktoDispatchTestHooks::reset();
    $before = TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get()->map->getRawOriginal()->all();
    config(['talkto.callbacks.reliable.recovery_enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    for ($i = 0; $i < 2; $i++) {
        expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0);
    }
    expect(TalktoDispatchIntent::query()->orderBy('id')->pluck('status')->all())->toBe(['published', 'published'])
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before)
        ->and(DB::table('jobs')->where('queue', 'retry-results')->count())->toBe(2)
        ->and($incoming->fresh()->overall_status)->toBe('failed_retryable')
        ->and($incoming->fresh()->retry_count)->toBe(2)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
});

test('consecutive reliable retryable results with different errors commit independent immutable callbacks', function (): void {
    $this->freezeTime();
    RetryCallbackCompatibilityHandler::$results = [
        TalktoIncomingCommandResult::failedRetryable('Network timeout A', 'temporary_network', ['attempt_detail' => 'A']),
        TalktoIncomingCommandResult::failedRetryable('Network timeout B', 'temporary_network', ['attempt_detail' => 'B']),
    ];
    $incoming = retryCallbackCompatibilityIncoming('retry-error-collision');
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, app(TalktoIncomingCommandResolver::class));
    $first = retryCallbackCompatibilitySnapshot($incoming);
    $firstCallback = TalktoMessage::query()->where('direction', 'outgoing')->sole()->getRawOriginal();
    expect($incoming->fresh()->overall_status)->toBe('failed_retryable')
        ->and($incoming->fresh()->retry_count)->toBe(1)
        ->and($incoming->fresh()->locked_by)->toBeNull()
        ->and(TalktoAttempt::query()->sole()->attempt_no)->toBe(1)
        ->and(TalktoDispatchIntent::query()->sole()->purpose)->toBe('result_callback');
    $this->travelTo($incoming->fresh()->next_retry_at->copy()->addSecond());
    expect(Artisan::call('talkto:retry-failed', ['--direction' => 'incoming', '--limit' => 1]))->toBe(0);
    $job = Queue::connection()->pop('retry-callback');
    expect($job)->not->toBeNull();
    $exception = null;
    try {
        $job->fire();
    } catch (Throwable $failure) {
        $exception = ['class' => $failure::class, 'message' => $failure->getMessage(), 'previous' => $failure->getPrevious()?->getMessage()];
    } finally {
        $job->delete();
    }
    retryCallbackCompatibilityEvidence('consecutive-different-errors', [
        'first' => $first,
        'second' => retryCallbackCompatibilitySnapshot($incoming),
        'exception' => $exception,
    ]);

    expect($exception)->toBeNull()
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2)
        ->and($incoming->fresh()->overall_status)->toBe('failed_retryable')
        ->and($incoming->fresh()->destination_action_status)->toBe('failed')
        ->and($incoming->fresh()->retry_count)->toBe(2)
        ->and($incoming->fresh()->getAttribute('attempts'))->toBe(2)
        ->and($incoming->fresh()->last_error)->toBe('Network timeout B')
        ->and($incoming->fresh()->locked_by)->toBeNull()
        ->and(TalktoAttempt::query()->orderBy('attempt_no')->pluck('status')->all())->toBe(['failed_retryable', 'failed_retryable'])
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(2)
        ->and(TalktoMessage::query()->whereKey($firstCallback['id'])->sole()->getRawOriginal())->toBe($firstCallback)
        ->and(TalktoDispatchIntent::query()->count())->toBe(2);
});

test('previous unknown publication remains immutable while a later same-status result commits independently', function (): void {
    $this->freezeTime();
    config([
        'database.connections.retry_callback_external' => config('database.connections.sqlite'),
        'queue.connections.retry_callback_external' => ['driver' => 'database', 'connection' => 'retry_callback_external', 'table' => 'jobs', 'queue' => 'retry-results', 'retry_after' => 90, 'after_commit' => false],
        'talkto.jobs.send_message' => RetryCallbackCompatibilityExternalSendJob::class,
    ]);
    retryCallbackCompatibilityCreateJobs('retry_callback_external');
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), TalktoIncomingCommandResult::failedRetryable('B')];
    $incoming = retryCallbackCompatibilityIncoming('unknown-history');
    retryCallbackCompatibilityProcess($incoming);
    TalktoDispatchTestHooks::set('dispatch_intent.after_queue', fn () => throw new RuntimeException('synthetic accepted queue publication acknowledgement loss'));
    $firstIntent = TalktoDispatchIntent::query()->sole();
    app(TalktoDispatchIntentService::class)->publish($firstIntent->id);
    TalktoDispatchTestHooks::reset();
    expect($firstIntent->fresh()->status)->toBe('unknown')
        ->and(DB::connection('retry_callback_external')->table('jobs')->count())->toBe(1);
    $firstCallback = TalktoMessage::query()->where('direction', 'outgoing')->sole();
    $callbackBefore = $firstCallback->getRawOriginal();
    $intentBefore = $firstIntent->fresh()->getRawOriginal();
    retryCallbackCompatibilityRetry($this, $incoming);
    config(['talkto.callbacks.reliable.recovery_enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true]);
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and($incoming->fresh()->overall_status)->toBe('failed_retryable')
        ->and($incoming->fresh()->retry_count)->toBe(2)
        ->and($firstCallback->fresh()->getRawOriginal())->toBe($callbackBefore)
        ->and($firstIntent->fresh()->getRawOriginal())->toBe($intentBefore)
        ->and(DB::connection('retry_callback_external')->table('jobs')->count())->toBe(2)
        ->and(TalktoDispatchIntent::query()->orderBy('id')->pluck('status')->all())->toBe(['unknown', 'published'])
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
    retryCallbackCompatibilityEvidence('unknown-publication', retryCallbackCompatibilitySnapshot($incoming));
});

test('reliable retry after a historical legacy callback never mutates or backfills that record', function (): void {
    $this->freezeTime();
    config(['talkto.callbacks.reliable.enabled' => false, 'talkto.dispatch_intents.enabled' => false]);
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('legacy A'), TalktoIncomingCommandResult::failedRetryable('reliable B')];
    $incoming = retryCallbackCompatibilityIncoming('legacy-to-reliable');
    retryCallbackCompatibilityProcess($incoming);
    $historical = TalktoMessage::query()->where('direction', 'outgoing')->sole();
    $before = $historical->getRawOriginal();
    expect($historical->message_id)->toBe('cb-'.sha1($incoming->message_id.'|failed_retryable'))
        ->and(TalktoDispatchIntent::query()->count())->toBe(0);
    config(['talkto.callbacks.reliable.enabled' => true, 'talkto.dispatch_intents.enabled' => true]);
    retryCallbackCompatibilityRetry($this, $incoming);
    $latest = TalktoMessage::query()->where('direction', 'outgoing')->orderByDesc('id')->first();
    expect($historical->fresh()->getRawOriginal())->toBe($before)
        ->and($latest->message_id)->not->toBe($historical->message_id)
        ->and($latest->payload['error_message'])->toBe('reliable B')
        ->and(TalktoDispatchIntent::query()->count())->toBe(1)
        ->and(TalktoDispatchIntent::query()->where('message_id', $historical->message_id)->exists())->toBeFalse()
        ->and($incoming->fresh()->overall_status)->toBe('failed_retryable')
        ->and($incoming->fresh()->retry_count)->toBe(2)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
});

test('disabled reliable callbacks retain legacy retry identity without optional storage', function (): void {
    $this->freezeTime();
    config(['talkto.callbacks.reliable.enabled' => false, 'talkto.dispatch_intents.enabled' => false]);
    Schema::drop('talkto_dispatch_intents');
    $intentQueries = [];
    $queries = [];
    DB::listen(function ($query) use (&$intentQueries, &$queries): void {
        $queries[] = $query->sql;
        if (str_contains($query->sql, 'talkto_dispatch_intents')) {
            $intentQueries[] = $query->sql;
        }
    });
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('legacy A'), TalktoIncomingCommandResult::failedRetryable('legacy B')];
    $incoming = retryCallbackCompatibilityIncoming('legacy-only');
    retryCallbackCompatibilityProcess($incoming);
    $callback = TalktoMessage::query()->where('direction', 'outgoing')->sole();
    $before = $callback->getRawOriginal();
    retryCallbackCompatibilityRetry($this, $incoming);
    $measuredQueries = $queries;
    expect($incoming->fresh()->overall_status)->toBe('failed_retryable')
        ->and($incoming->fresh()->retry_count)->toBe(2)
        ->and($incoming->fresh()->last_error)->toBe('legacy B')
        ->and($callback->fresh()->getRawOriginal())->toBe($before)
        ->and($callback->message_id)->toBe('cb-'.sha1($incoming->message_id.'|failed_retryable'))
        ->and($callback->idempotency_key)->toBe('talkto:callback:'.$incoming->message_id.':failed_retryable')
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(1)
        ->and($intentQueries)->toBe([])
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
    retryCallbackCompatibilityEvidence('legacy-only-two-attempts', [
        'sql_scope' => 'Entire two-processing-attempt fixture including incoming creation, retry command/job and first callback observation',
        'sql_count' => count($measuredQueries), 'sql' => $measuredQueries,
        'intent_queries' => $intentQueries, 'handler_calls' => RetryCallbackCompatibilityHandler::$calls,
        'callback_count' => TalktoMessage::query()->where('direction', 'outgoing')->count(),
        'callback_message_id' => $callback->message_id, 'callback_idempotency_key' => $callback->idempotency_key,
    ]);
});

test('public custom callback ids retain no-attempt behavior and reject conflicting later-attempt overrides', function (): void {
    $this->freezeTime();
    $manualIncoming = retryCallbackCompatibilityIncoming('public-custom-id');
    $manual = TalktoIncomingCommandResult::succeeded(['manual' => true]);
    $sender = app(ResultCallbackSenderContract::class);
    $first = $sender->sendResult($manualIncoming, $manual, ['callback_message_id' => 'explicit-custom-callback']);
    $replay = $sender->sendResult($manualIncoming, $manual, ['callback_message_id' => 'explicit-custom-callback']);
    expect($first['callback_message_id'])->toBe('explicit-custom-callback')
        ->and($replay['callback_message_id'])->toBe('explicit-custom-callback')
        ->and($replay['duplicate'])->toBeTrue();
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), TalktoIncomingCommandResult::failedRetryable('B')];
    $incoming = retryCallbackCompatibilityIncoming('attempt-custom-id');
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    $before = TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get()->map->getRawOriginal()->all();
    expect(fn () => $sender->sendResult($incoming->fresh(), RetryCallbackCompatibilityHandler::$results[1], ['callback_message_id' => 'different-custom-attempt-id']))->toThrow(TalktoReliableCallbackPersistenceException::class);
    expect(TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before)
        ->and(TalktoDispatchIntent::query()->count())->toBe(3)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
});

test('custom result contracts retain their own frozen nested result on every retry', function (): void {
    $this->freezeTime();
    RetryCallbackCompatibilityHandler::$results = [new RetryCallbackCompatibilityResult(1), new RetryCallbackCompatibilityResult(2)];
    $incoming = retryCallbackCompatibilityIncoming('custom-contract');
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    $callbacks = TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get();
    expect($callbacks)->toHaveCount(2)
        ->and($callbacks[0]->payload['result'])->toBe(['version' => 1, 'empty' => []])
        ->and($callbacks[1]->payload['result'])->toBe(['version' => 2, 'empty' => []])
        ->and($callbacks[0]->payload['meta'])->toBe(['nested' => ['unicode' => 'سلام 世界', 'null' => null, 'version' => 1]])
        ->and($callbacks[1]->payload['meta'])->toBe(['nested' => ['unicode' => 'سلام 世界', 'null' => null, 'version' => 2]])
        ->and($incoming->fresh()->retry_count)->toBe(2)
        ->and(TalktoAttempt::query()->orderBy('attempt_no')->get()[1]->meta['result_meta'])->toBe(['nested' => ['unicode' => 'سلام 世界', 'null' => null, 'version' => 2]])
        ->and(TalktoAttempt::query()->orderBy('attempt_no')->get()[1]->error_message)->toBe('custom-2');
});

test('supported configured models and a dedicated database connection preserve independent retry results', function (): void {
    $this->freezeTime();
    config([
        'database.connections.retry_callback_named' => config('database.connections.sqlite'),
        'talkto.database.connection' => 'retry_callback_named',
        'talkto.database.tables.messages' => 'custom_retry_messages',
        'talkto.database.tables.attempts' => 'custom_retry_attempts',
        'talkto.database.tables.events' => 'custom_retry_events',
        'talkto.database.tables.dead_letters' => 'custom_retry_dead_letters',
        'talkto.database.tables.nonces' => 'custom_retry_nonces',
        'talkto.database.tables.dispatch_intents' => 'custom_retry_intents',
        'talkto.models.message' => RetryCallbackCompatibilityMessage::class,
        'talkto.models.attempt' => RetryCallbackCompatibilityAttempt::class,
        'talkto.models.event' => RetryCallbackCompatibilityEvent::class,
        'talkto.models.dispatch_intent' => RetryCallbackCompatibilityIntent::class,
        'queue.connections.retry_callback_database.connection' => 'retry_callback_named',
    ]);
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migration) {
        (require $migration)->up();
    }
    foreach (glob(__DIR__.'/../../database/dispatch-intents/*.php') as $migration) {
        (require $migration)->up();
    }
    retryCallbackCompatibilityCreateJobs('retry_callback_named');
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('custom A'), TalktoIncomingCommandResult::failedRetryable('custom B')];
    $incoming = retryCallbackCompatibilityIncoming('custom-models-and-connection');
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    $callbacks = RetryCallbackCompatibilityMessage::query()->where('direction', 'outgoing')->orderBy('id')->get();
    expect($incoming)->toBeInstanceOf(RetryCallbackCompatibilityMessage::class)
        ->and($incoming->getConnectionName())->toBe('retry_callback_named')
        ->and($incoming->fresh()->overall_status)->toBe('failed_retryable')
        ->and($incoming->fresh()->retry_count)->toBe(2)
        ->and($callbacks)->toHaveCount(2)
        ->and($callbacks[1]->payload['error_message'])->toBe('custom B')
        ->and($callbacks[0]->message_id)->not->toBe($callbacks[1]->message_id)
        ->and(RetryCallbackCompatibilityAttempt::query()->orderBy('attempt_no')->pluck('attempt_no')->all())->toBe([1, 2])
        ->and(RetryCallbackCompatibilityEvent::query()->where('event_type', 'result_callback_persisted')->count())->toBe(2)
        ->and(RetryCallbackCompatibilityIntent::query()->count())->toBe(2)
        ->and(DB::connection('sqlite')->table('talkto_messages')->count())->toBe(0)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
});

test('an inherited default callback sender retains reliable retry support', function (): void {
    $this->freezeTime();
    app()->instance(ResultCallbackSenderContract::class, app(RetryCallbackCompatibilityInheritedSender::class));
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), TalktoIncomingCommandResult::failedRetryable('B')];
    $incoming = retryCallbackCompatibilityIncoming('inherited-sender');
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    expect($incoming->fresh()->retry_count)->toBe(2)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(2)
        ->and(TalktoDispatchIntent::query()->count())->toBe(2)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
});

test('legacy custom callback sender remains supported across retries without ledger storage', function (): void {
    $this->freezeTime();
    config(['talkto.callbacks.reliable.enabled' => false, 'talkto.dispatch_intents.enabled' => false]);
    Schema::drop('talkto_dispatch_intents');
    app()->instance(ResultCallbackSenderContract::class, new RetryCallbackCompatibilitySender);
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), TalktoIncomingCommandResult::failedRetryable('B')];
    $incoming = retryCallbackCompatibilityIncoming('legacy-custom-sender');
    retryCallbackCompatibilityProcess($incoming);
    retryCallbackCompatibilityRetry($this, $incoming);
    expect($incoming->fresh()->retry_count)->toBe(2)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2)
        ->and(RetryCallbackCompatibilitySender::$calls)->toBe(2)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->count())->toBe(0);
});

test('unsupported custom sender is refused before the next reliable handler attempt', function (): void {
    $this->freezeTime();
    RetryCallbackCompatibilityHandler::$results = [TalktoIncomingCommandResult::failedRetryable('A'), TalktoIncomingCommandResult::failedRetryable('B')];
    $incoming = retryCallbackCompatibilityIncoming('unsupported-next-sender');
    retryCallbackCompatibilityProcess($incoming);
    $before = TalktoMessage::query()->where('direction', 'outgoing')->sole()->getRawOriginal();
    app()->instance(ResultCallbackSenderContract::class, new RetryCallbackCompatibilitySender);
    expect(fn () => retryCallbackCompatibilityRetry($this, $incoming))->toThrow(RuntimeException::class);
    expect(RetryCallbackCompatibilityHandler::$calls)->toBe(1)
        ->and(RetryCallbackCompatibilitySender::$calls)->toBe(0)
        ->and(TalktoAttempt::query()->count())->toBe(1)
        ->and(TalktoMessage::query()->where('direction', 'outgoing')->sole()->getRawOriginal())->toBe($before)
        ->and(TalktoDispatchIntent::query()->count())->toBe(1);
});

test('legacy internal overrides keep their original method signatures loadable with reliable mode disabled', function (string $method): void {
    $root = dirname(__DIR__, 2);
    $definition = $method === 'prepared factory'
        ? <<<'PHP'
class LegacyPreparedRetryFactory extends \Mrezdev\LaravelTalkto\Services\TalktoResultCallbackMessageFactory {
    public function createPreparedForReliableResult(\Mrezdev\LaravelTalkto\Models\TalktoMessage $incomingMessage, \Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData $callback): \Mrezdev\LaravelTalkto\Models\TalktoMessage {
        throw new \LogicException('This legacy-only class-loading fixture does not execute reliable persistence.');
    }
}
PHP
        : <<<'PHP'
class LegacyPersistRetryService extends \Mrezdev\LaravelTalkto\Services\TalktoReliableCallbackService {
    public function persist(\Mrezdev\LaravelTalkto\Support\TalktoReliableCallbackContext $context, \Mrezdev\LaravelTalkto\Models\TalktoMessage $incoming, \Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract $result, array $options = []): \Mrezdev\LaravelTalkto\Models\TalktoMessage {
        throw new \LogicException('This legacy-only class-loading fixture does not execute reliable persistence.');
    }
}
PHP;
    $code = 'require '.var_export($root.'/vendor/autoload.php', true).';'
        .'$app = new \Illuminate\Foundation\Application('.var_export($root, true).');'
        .'$app->instance("config", new \Illuminate\Config\Repository(["talkto" => ["callbacks" => ["reliable" => ["enabled" => false]]]]));'
        .$definition
        .'echo json_encode(["class_loaded" => true, "reliable_enabled" => config("talkto.callbacks.reliable.enabled")], JSON_THROW_ON_ERROR);';
    $process = new Process([PHP_BINARY, '-r', $code], $root);
    $process->setTimeout(20);
    $process->run();
    retryCallbackCompatibilityEvidence('legacy-signature-'.$method, [
        'exit_code' => $process->getExitCode(), 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput(),
    ]);
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().' '.$process->getOutput())
        ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['class_loaded' => true, 'reliable_enabled' => false]);
})->with(['prepared factory', 'persistence service']);

test('large same-status results preserve both immutable bodies and bounded operation counts', function (int $bodyBytes): void {
    $this->freezeTime();
    $body = str_repeat('x', $bodyBytes);
    RetryCallbackCompatibilityHandler::$results = [
        new TalktoIncomingCommandResult(false, true, 'temporary', 'A', ['body' => $body], ['nested' => ['empty' => [], 'unicode' => 'سلام']]),
        new TalktoIncomingCommandResult(false, true, 'temporary', 'B', ['body' => $body], ['nested' => ['empty' => [], 'unicode' => 'سلام']]),
    ];
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $incoming = retryCallbackCompatibilityIncoming('large-retry-'.$bodyBytes);
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $measurements = [];
    $start = microtime(true);
    $beforeCount = $queries;
    retryCallbackCompatibilityProcess($incoming);
    $measurements[] = ['sql_count' => $queries - $beforeCount, 'elapsed_ms' => (microtime(true) - $start) * 1000, 'peak_php_allocated_bytes' => memory_get_peak_usage(true)];
    $start = microtime(true);
    $beforeCount = $queries;
    retryCallbackCompatibilityRetry($this, $incoming);
    $measurements[] = ['sql_count' => $queries - $beforeCount, 'elapsed_ms' => (microtime(true) - $start) * 1000, 'peak_php_allocated_bytes' => memory_get_peak_usage(true)];
    $callbacks = TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get();
    expect($callbacks)->toHaveCount(2)
        ->and($callbacks[0]->payload['result']['body'])->toBe($body)
        ->and($callbacks[1]->payload['result']['body'])->toBe($body)
        ->and($callbacks[0]->payload['error_message'])->toBe('A')
        ->and($callbacks[1]->payload['error_message'])->toBe('B')
        ->and($callbacks[0]->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($callbacks[0]->payload))
        ->and($callbacks[1]->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($callbacks[1]->payload))
        ->and(TalktoDispatchIntent::query()->count())->toBe(2)
        ->and($incoming->fresh()->retry_count)->toBe(2)
        ->and(RetryCallbackCompatibilityHandler::$calls)->toBe(2);
    retryCallbackCompatibilityEvidence('large-'.$bodyBytes, [
        'body_bytes' => $bodyBytes, 'operations' => $measurements,
        'callbacks' => $callbacks->map(fn ($callback) => ['message_id' => $callback->message_id, 'idempotency_key' => $callback->idempotency_key, 'payload_hash' => $callback->payload_hash,
            'callback_json_bytes' => strlen(json_encode($callback->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'identity_metadata_bytes' => strlen($callback->message_id) + strlen($callback->idempotency_key)])->all(),
        'handler_invocations' => RetryCallbackCompatibilityHandler::$calls, 'intent_count' => TalktoDispatchIntent::query()->count(),
        'publication_count' => TalktoDispatchIntent::query()->sum('publication_attempts'), 'queued_jobs' => DB::table('jobs')->count(),
    ]);
})->with([1024, 65536, 262144, 1048576, 2516582]);

function retryCallbackCompatibilityIncoming(string $id, array $extra = []): TalktoMessage
{
    $payload = ['resource' => $id];
    $class = config('talkto.models.message', TalktoMessage::class);

    return $class::query()->create(array_merge([
        'message_id' => $id, 'correlation_id' => 'correlation-'.$id, 'direction' => 'incoming',
        'source_service' => 'retry-source', 'target_service' => 'retry-target', 'command' => 'retry.command',
        'business_key' => 'business-'.$id, 'idempotency_key' => 'idempotency-'.$id,
        'payload' => $payload, 'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload), 'schema_version' => 1,
        'destination_receive_status' => 'received', 'destination_action_status' => 'queued', 'overall_status' => 'queued',
        'attempts' => 0, 'retry_count' => 0, 'max_attempts' => 5, 'received_at' => now(),
    ], $extra));
}

function retryCallbackCompatibilityProcess(TalktoMessage $incoming): void
{
    app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, app(TalktoIncomingCommandResolver::class));
}

function retryCallbackCompatibilityCreateJobs(string $connection): void
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

function retryCallbackCompatibilityRetry(object $testCase, TalktoMessage $incoming, bool $advanceTime = true): void
{
    if ($advanceTime) {
        $testCase->travelTo($incoming->fresh()->next_retry_at->copy()->addSecond());
    }
    expect(Artisan::call('talkto:retry-failed', ['--direction' => 'incoming', '--limit' => 1]))->toBe(0);
    $job = Queue::connection()->pop('retry-callback');
    expect($job)->not->toBeNull();
    try {
        $job->fire();
    } finally {
        $job->delete();
    }
}

function retryCallbackCompatibilitySnapshot(TalktoMessage $incoming): array
{
    return [
        'handler_invocations' => RetryCallbackCompatibilityHandler::$calls,
        'incoming' => $incoming->fresh()->getRawOriginal(),
        'attempts' => TalktoAttempt::query()->where('message_id', $incoming->message_id)->orderBy('attempt_no')->get()->map(fn ($attempt) => $attempt->getRawOriginal())->all(),
        'callbacks' => TalktoMessage::query()->where('parent_message_id', $incoming->message_id)->orderBy('id')->get()->map(fn ($callback) => $callback->getRawOriginal())->all(),
        'intents' => TalktoDispatchIntent::query()->orderBy('id')->get()->map(fn ($intent) => $intent->getRawOriginal())->all(),
        'events' => TalktoEvent::query()->where('message_id', $incoming->message_id)->orderBy('id')->get()->map(fn ($event) => $event->getRawOriginal())->all(),
        'jobs' => DB::table('jobs')->orderBy('id')->get()->map(fn ($job) => (array) $job)->all(),
    ];
}

function retryCallbackCompatibilityEvidence(string $scenario, array $data): void
{
    $path = getenv('TALKTO_RETRY_CALLBACK_EVIDENCE_PATH');
    if (! is_string($path) || $path === '') {
        return;
    }
    $evidence = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
    $evidence[$scenario] = $data;
    file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
}

class RetryCallbackCompatibilityHandler implements TalktoIncomingCommandHandler
{
    public static int $calls = 0;

    public static array $results = [];

    public function handle(TalktoMessage $message): IncomingCommandResultContract
    {
        $result = self::$results[self::$calls++] ?? throw new RuntimeException('Synthetic result sequence exhausted.');
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}

class RetryCallbackCompatibilitySendJob extends SendTalktoMessage
{
    public $queue = 'retry-results';
}

class RetryCallbackCompatibilityExternalSendJob extends SendTalktoMessage
{
    public $queue = 'retry-results';

    public $connection = 'retry_callback_external';
}

class RetryCallbackCompatibilityResult implements IncomingCommandResultContract
{
    public function __construct(private readonly int $version) {}

    public function isSucceeded(): bool
    {
        return false;
    }

    public function isRetryable(): bool
    {
        return true;
    }

    public function isSkipped(): bool
    {
        return false;
    }

    public function errorClass(): ?string
    {
        return 'temporary_custom';
    }

    public function errorMessage(): ?string
    {
        return 'custom-'.$this->version;
    }

    public function result(): array
    {
        return ['version' => $this->version, 'empty' => []];
    }

    public function meta(): array
    {
        return ['nested' => ['unicode' => 'سلام 世界', 'null' => null, 'version' => $this->version]];
    }
}

class RetryCallbackCompatibilityCountingResult implements IncomingCommandResultContract
{
    public array $reads = [];

    private function read(string $method): int
    {
        return $this->reads[$method] = ($this->reads[$method] ?? 0) + 1;
    }

    public function isSucceeded(): bool
    {
        $this->read(__FUNCTION__);

        return true;
    }

    public function isRetryable(): bool
    {
        $this->read(__FUNCTION__);

        return false;
    }

    public function isSkipped(): bool
    {
        $this->read(__FUNCTION__);

        return false;
    }

    public function errorClass(): ?string
    {
        $this->read(__FUNCTION__);

        return null;
    }

    public function errorMessage(): ?string
    {
        $this->read(__FUNCTION__);

        return null;
    }

    public function result(): array
    {
        return ['snapshot' => $this->read(__FUNCTION__)];
    }

    public function meta(): array
    {
        return ['nested' => ['snapshot' => $this->read(__FUNCTION__), 'empty' => [], 'unicode' => 'سلام']];
    }
}

class RetryCallbackCompatibilityHttpClient implements TalktoHttpClient
{
    public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
    {
        return new TalktoHttpResponse(200, json_encode(['accepted' => true, 'status' => 'applied'], JSON_THROW_ON_ERROR));
    }
}

class RetryCallbackCompatibilitySender implements ResultCallbackSenderContract
{
    public static int $calls = 0;

    public function sendResult(Model $message, IncomingCommandResultContract $result, array $options = []): mixed
    {
        self::$calls++;

        return ['sent' => false, 'queued' => false, 'custom' => true];
    }
}

class RetryCallbackCompatibilityInheritedSender extends TalktoResultCallbackSender {}

class RetryCallbackCompatibilityMessage extends TalktoMessage {}

class RetryCallbackCompatibilityAttempt extends TalktoAttempt {}

class RetryCallbackCompatibilityEvent extends TalktoEvent {}

class RetryCallbackCompatibilityIntent extends TalktoDispatchIntent {}
