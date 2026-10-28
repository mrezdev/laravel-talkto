<?php

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDispatchIntent;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoFlowBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;

beforeEach(function (): void {
    CompatibilityBuilderProbe::$jobSelectionCalls = 0;
    CompatibilityBuilderProbe::$dispatchCalls = 0;
    CompatibilityBuilderProbe::$validationCalls = 0;
    CompatibilityBuilderProbe::$factoryCalls = 0;
    config([
        'talkto.service' => 'testing',
        'talkto.dispatch_intents.enabled' => true,
        'talkto.dispatch_intents.recovery_enabled' => false,
        'talkto.dispatch_intents.publish_after_commit' => true,
        'talkto.outgoing.peer' => ['url' => 'https://builder-compatibility.invalid', 'secret' => 'synthetic-builder-secret'],
        'queue.default' => 'compatibility_database',
        'queue.connections.compatibility_database' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'compatibility-default', 'retry_after' => 90, 'after_commit' => false],
    ]);
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->loadMigrationsFrom(__DIR__.'/../../database/dispatch-intents');
    expect($this->artisan('migrate')->run())->toBe(0);
    compatibilityCreateJobs('sqlite');
    Schema::create('compatibility_business', function (Blueprint $table): void {
        $table->id();
        $table->string('marker');
    });
});

afterEach(function (): void {
    TalktoDispatchTestHooks::reset();
    DB::purge('compatibility_custom');
});

test('durable builder overrides reject before persistence source actions validation and dispatch', function (string $builder, string $method, string $operation): void {
    $existing = app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', ['existing' => true]);
    SendTalktoMessage::dispatch((int) $existing->id)->onQueue('existing-job')->afterCommit();
    DB::table('compatibility_business')->insert(['marker' => 'existing-business']);
    $before = compatibilityState();
    $sourceCalls = 0;
    $caught = null;
    try {
        $flow = compatibilityFlow($builder)->option('message_id', 'rejected-builder');
        if ($operation === 'run') {
            $flow->run(function () use (&$sourceCalls, $existing): array {
                $sourceCalls++;
                DB::table('compatibility_business')->insert(['marker' => 'must-not-write']);
                $existing->update(['overall_status' => 'failed']);

                return ['synthetic' => true];
            });
        } else {
            $flow->send();
        }
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($caught?->getMessage())->toContain('TalktoFlowBuilder::'.$method.'()', 'talkto.jobs.send_message', 'adapter')
        ->and(compatibilityState())->toBe($before)
        ->and($sourceCalls)->toBe(0)
        ->and(CompatibilityBuilderProbe::$jobSelectionCalls)->toBe(0)
        ->and(CompatibilityBuilderProbe::$dispatchCalls)->toBe(0)
        ->and(CompatibilityBuilderProbe::$validationCalls)->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe(0);
})->with([
    'job class direct' => [CompatibilityJobBuilder::class, 'sendJobClass'],
    'dispatch direct' => [CompatibilityDispatchBuilder::class, 'dispatchSendJob'],
    'both overrides' => [CompatibilityBothBuilder::class, 'sendJobClass'],
    'inherited job class' => [CompatibilityInheritedJobBuilder::class, 'sendJobClass'],
    'inherited dispatch' => [CompatibilityInheritedDispatchBuilder::class, 'dispatchSendJob'],
    'inherited both' => [CompatibilityInheritedBothBuilder::class, 'sendJobClass'],
    'invalid job selection' => [CompatibilityInvalidJobBuilder::class, 'sendJobClass'],
    'validation with side effects' => [CompatibilityValidationBuilder::class, 'sendJobClass'],
])->with(['send', 'run']);

test('standard and inherited builders without dispatch overrides retain durable publication', function (string $builder, string $operation): void {
    $sourceCalls = 0;
    $flow = compatibilityFlow($builder)->option('message_id', 'compatible-builder');
    $message = $operation === 'send' ? $flow->send() : $flow->run(function () use (&$sourceCalls): array {
        $sourceCalls++;
        DB::table('compatibility_business')->insert(['marker' => 'committed-business']);

        return ['payload' => ['synthetic' => true], 'result' => ['ok' => true]];
    });

    expect($message)->toBeInstanceOf(TalktoMessage::class)
        ->and($message->message_id)->toBe('compatible-builder')
        ->and($message->source_action_status)->toBe($operation === 'run' ? 'succeeded' : 'succeeded_assumed')
        ->and($sourceCalls)->toBe($operation === 'run' ? 1 : 0)
        ->and(DB::table('compatibility_business')->count())->toBe($operation === 'run' ? 1 : 0)
        ->and(TalktoMessage::query()->count())->toBe(1)
        ->and(TalktoEvent::query()->where('event_type', 'message_created')->count())->toBe(1)
        ->and(TalktoDispatchIntent::query()->sole()->status)->toBe('published')
        ->and(DB::table('jobs')->count())->toBe(1);
    $queued = Queue::connection()->pop('compatibility-default');
    expect($queued)->not->toBeNull()->and($queued->resolveName())->toBe(SendTalktoMessage::class);
    $queued->delete();
})->with([TalktoFlowBuilder::class, CompatibilityPlainBuilder::class, CompatibilityInheritedPlainBuilder::class])->with(['send', 'run']);

test('disabled durable mode preserves standard and overridden dispatch without any ledger queries', function (string $builder, string $jobClass, string $queueName, int $selectionCalls, int $dispatchCalls, string $operation): void {
    config(['talkto.dispatch_intents.enabled' => false]);
    Schema::drop('talkto_dispatch_intents');
    $ledgerQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$ledgerQueries): void {
        if (str_contains($query->sql, 'talkto_dispatch_intents')) {
            $ledgerQueries[] = $query->sql;
        }
    });
    $sourceCalls = 0;
    $flow = compatibilityFlow($builder);
    $message = $operation === 'send' ? $flow->send() : $flow->run(function () use (&$sourceCalls): array {
        $sourceCalls++;
        DB::table('compatibility_business')->insert(['marker' => 'legacy-business']);

        return ['synthetic' => true];
    });

    expect($message)->toBeInstanceOf(TalktoMessage::class)
        ->and(TalktoMessage::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($sourceCalls)->toBe($operation === 'run' ? 1 : 0)
        ->and(CompatibilityBuilderProbe::$jobSelectionCalls)->toBe($selectionCalls)
        ->and(CompatibilityBuilderProbe::$dispatchCalls)->toBe($dispatchCalls)
        ->and($ledgerQueries)->toBe([]);
    $queued = Queue::connection()->pop($queueName);
    expect($queued)->not->toBeNull()->and($queued->resolveName())->toBe($jobClass);
    $queued->delete();
})->with([
    'standard' => [TalktoFlowBuilder::class, SendTalktoMessage::class, 'compatibility-default', 0, 0],
    'plain subclass' => [CompatibilityPlainBuilder::class, SendTalktoMessage::class, 'compatibility-default', 0, 0],
    'job class override' => [CompatibilityJobBuilder::class, CompatibilityCustomSendJob::class, 'compatibility-custom', 1, 0],
    'dispatch override' => [CompatibilityDispatchBuilder::class, SendTalktoMessage::class, 'compatibility-override', 0, 1],
    'both overrides' => [CompatibilityBothBuilder::class, CompatibilityCustomSendJob::class, 'compatibility-both', 1, 1],
    'inherited job override' => [CompatibilityInheritedJobBuilder::class, CompatibilityCustomSendJob::class, 'compatibility-custom', 1, 0],
    'inherited dispatch override' => [CompatibilityInheritedDispatchBuilder::class, SendTalktoMessage::class, 'compatibility-override', 0, 1],
])->with(['send', 'run']);

test('configured custom job publishes with the supported unchanged builder', function (): void {
    config(['talkto.jobs.send_message' => CompatibilityCustomSendJob::class]);
    $message = compatibilityFlow(CompatibilityInheritedPlainBuilder::class)->send();
    $intent = TalktoDispatchIntent::query()->sole();
    $queued = Queue::connection()->pop('compatibility-custom');

    expect($intent->job_class)->toBe(CompatibilityCustomSendJob::class)
        ->and($intent->queue_name)->toBe('compatibility-custom')
        ->and($intent->status)->toBe('published')
        ->and($queued->resolveName())->toBe(CompatibilityCustomSendJob::class)
        ->and(unserialize($queued->payload()['data']['command'])->talktoMessageId)->toBe((int) $message->id);
    $queued->delete();
});

test('configured custom job survives deferred publication recovery and actual queue execution', function (): void {
    config(['talkto.jobs.send_message' => CompatibilityCustomSendJob::class,
        'talkto.dispatch_intents.publish_after_commit' => false, 'talkto.dispatch_intents.recovery_enabled' => true]);
    $message = compatibilityFlow(CompatibilityPlainBuilder::class)->send();
    expect(TalktoDispatchIntent::query()->sole()->status)->toBe('ready')->and(DB::table('jobs')->count())->toBe(0);
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--message-id' => $message->message_id, '--min-age' => 0]))->toBe(0);
    $queued = Queue::connection()->pop('compatibility-custom');
    $client = new CompatibilityHttpClient;
    app()->instance(TalktoHttpClient::class, $client);
    expect($queued->resolveName())->toBe(CompatibilityCustomSendJob::class);
    $queued->fire();
    $queued->delete();

    expect($client->calls)->toBe(1)
        ->and($message->fresh()->overall_status)->toBe('destination_received')
        ->and(TalktoDispatchIntent::query()->sole()->status)->toBe('published')
        ->and(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(TalktoDispatchIntent::query()->count())->toBe(1)
        ->and($client->calls)->toBe(1);
});

test('durable compatible builders preserve idempotency without duplicate intents or publications', function (): void {
    config(['talkto.jobs.send_message' => CompatibilityCustomSendJob::class]);
    $first = compatibilityFlow(CompatibilityPlainBuilder::class)->idempotencyKey('same-builder-operation')->send();
    $second = compatibilityFlow(CompatibilityInheritedPlainBuilder::class)->idempotencyKey('same-builder-operation')->send();

    expect($second->id)->toBe($first->id)
        ->and(TalktoMessage::query()->count())->toBe(1)
        ->and(TalktoDispatchIntent::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('an unsupported builder cannot mutate or republish an existing idempotent message', function (): void {
    $existing = compatibilityFlow()->idempotencyKey('existing-intended-operation')->send();
    $before = compatibilityState();

    expect(fn () => compatibilityFlow(CompatibilityJobBuilder::class)->idempotencyKey('existing-intended-operation')->send())
        ->toThrow(InvalidArgumentException::class)
        ->and(compatibilityState())->toBe($before)
        ->and(TalktoMessage::query()->sole()->id)->toBe($existing->id);
});

test('recovery still refuses configuration drift from a stored supported custom job', function (bool $jobDrift): void {
    config(['talkto.jobs.send_message' => $jobDrift ? CompatibilityCustomSendJob::class : CompatibilityDefaultQueueSendJob::class,
        'talkto.dispatch_intents.publish_after_commit' => false, 'talkto.dispatch_intents.recovery_enabled' => true]);
    $message = compatibilityFlow(CompatibilityPlainBuilder::class)->send();
    if ($jobDrift) {
        config(['talkto.jobs.send_message' => SendTalktoMessage::class]);
    } else {
        config(['queue.connections.compatibility_database.driver' => 'sync']);
    }
    $beforeMessage = $message->fresh()->getRawOriginal();
    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($message->fresh()->getRawOriginal())->toBe($beforeMessage)
        ->and(TalktoDispatchIntent::query()->sole()->publication_attempts)->toBe(0);
})->with(['configured class changed' => [true], 'configured driver changed' => [false]]);

test('stored custom job queue is preserved when only the default queue name changes', function (): void {
    config(['talkto.jobs.send_message' => CompatibilityDefaultQueueSendJob::class,
        'talkto.dispatch_intents.publish_after_commit' => false, 'talkto.dispatch_intents.recovery_enabled' => true]);
    compatibilityFlow(CompatibilityPlainBuilder::class)->send();
    config(['queue.connections.compatibility_database.queue' => 'new-default']);

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(TalktoDispatchIntent::query()->sole()->queue_name)->toBe('compatibility-default')
        ->and(DB::table('jobs')->where('queue', 'compatibility-default')->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'new-default')->count())->toBe(0);
});
test('unsupported configured job features still reject without a message intent or job', function (string $jobClass): void {
    config(['talkto.jobs.send_message' => $jobClass]);
    $before = compatibilityState();
    $caught = null;
    try {
        compatibilityFlow(CompatibilityPlainBuilder::class)->send();
    } catch (Throwable $exception) {
        $caught = $exception;
    }
    expect($caught)->toBeInstanceOf(Throwable::class)
        ->and(compatibilityState())->toBe($before)
        ->and(DB::connection()->transactionLevel())->toBe(0);
})->with([CompatibilityUniqueSendJob::class, CompatibilityMiddlewareSendJob::class, CompatibilityMissingConstructorArgumentJob::class]);

test('custom outgoing factory preflight rejects before source action audit or overridden create', function (string $operation): void {
    $before = compatibilityState();
    $sourceCalls = 0;
    $factory = app(CompatibilityOutgoingFactory::class);
    $flow = compatibilityFlow(TalktoFlowBuilder::class, $factory);
    $caught = null;
    try {
        if ($operation === 'send') {
            $flow->send();
        } else {
            $flow->run(function () use (&$sourceCalls): array {
                $sourceCalls++;
                DB::table('compatibility_business')->insert(['marker' => 'must-not-write']);

                return ['synthetic' => true];
            });
        }
    } catch (Throwable $exception) {
        $caught = $exception;
    }
    expect($caught)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($caught?->getMessage())->toContain('factory', 'create', 'adapter')
        ->and($sourceCalls)->toBe(0)
        ->and(CompatibilityBuilderProbe::$factoryCalls)->toBe(0)
        ->and(compatibilityState())->toBe($before);
})->with(['send', 'run']);

test('disabled durable mode preserves a configured outgoing factory create override', function (): void {
    config(['talkto.dispatch_intents.enabled' => false]);
    Schema::drop('talkto_dispatch_intents');
    $message = compatibilityFlow(TalktoFlowBuilder::class, app(CompatibilityOutgoingFactory::class))->send();

    expect($message->exists)->toBeTrue()
        ->and(CompatibilityBuilderProbe::$factoryCalls)->toBe(1)
        ->and(TalktoMessage::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('compatible builders preserve custom model tables and named database connections', function (): void {
    config([
        'database.connections.compatibility_custom' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'talkto.database.connection' => 'compatibility_custom',
        'talkto.database.tables.messages' => 'compatibility_messages',
        'talkto.database.tables.events' => 'compatibility_events',
        'talkto.database.tables.dispatch_intents' => 'compatibility_intents',
        'talkto.models.message' => CompatibilityMessage::class,
        'talkto.models.event' => CompatibilityEvent::class,
        'talkto.models.dispatch_intent' => CompatibilityIntent::class,
        'talkto.jobs.send_message' => CompatibilityCustomSendJob::class,
        'queue.connections.compatibility_database.connection' => 'compatibility_custom',
    ]);
    $migrations = glob(__DIR__.'/../../database/migrations/*.php');
    sort($migrations);
    foreach (array_merge($migrations, glob(__DIR__.'/../../database/dispatch-intents/*.php')) as $migration) {
        (require $migration)->up();
    }
    compatibilityCreateJobs('compatibility_custom');
    $message = compatibilityFlow(CompatibilityInheritedPlainBuilder::class)->send();
    $queued = Queue::connection()->pop('compatibility-custom');

    expect($message)->toBeInstanceOf(CompatibilityMessage::class)
        ->and($message->getConnection()->getName())->toBe('compatibility_custom')
        ->and(DB::connection('compatibility_custom')->table('compatibility_messages')->count())->toBe(1)
        ->and(DB::connection('compatibility_custom')->table('compatibility_events')->where('event_type', 'message_created')->count())->toBe(1)
        ->and(DB::connection('compatibility_custom')->table('compatibility_intents')->value('status'))->toBe('published')
        ->and($queued->resolveName())->toBe(CompatibilityCustomSendJob::class)
        ->and(DB::connection('sqlite')->table('talkto_messages')->count())->toBe(0)
        ->and(DB::connection('sqlite')->table('talkto_dispatch_intents')->count())->toBe(0)
        ->and(DB::connection('sqlite')->table('jobs')->count())->toBe(0);
    $queued->delete();
});

test('terminal advancement after a supported custom flow is never downgraded or republished by recovery', function (): void {
    config(['talkto.jobs.send_message' => CompatibilityCustomSendJob::class,
        'talkto.dispatch_intents.publish_after_commit' => false, 'talkto.dispatch_intents.recovery_enabled' => true]);
    $message = compatibilityFlow(CompatibilityPlainBuilder::class)->send();
    $message->update(['overall_status' => 'succeeded', 'destination_action_status' => 'succeeded', 'completed_at' => now(), 'last_error' => null]);
    $beforeMessage = $message->fresh()->getRawOriginal();

    expect(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($message->fresh()->getRawOriginal())->toBe($beforeMessage)
        ->and(TalktoDispatchIntent::query()->sole()->status)->toBe('cancelled')
        ->and(Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--min-age' => 0]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('compatible durable nested rollback still removes business message intent event and queued work', function (): void {
    $before = compatibilityState();
    DB::beginTransaction();
    try {
        compatibilityFlow(CompatibilityInheritedPlainBuilder::class)->run(function (): array {
            DB::table('compatibility_business')->insert(['marker' => 'rolled-back']);

            return ['synthetic' => true];
        });
        expect(TalktoMessage::query()->count())->toBe(1)
            ->and(TalktoDispatchIntent::query()->count())->toBe(1)
            ->and(DB::table('jobs')->count())->toBe(0);
    } finally {
        DB::rollBack();
    }
    expect(compatibilityState())->toBe($before);
});

test('validation retains the baseline dispatch branch when it changes the durable flag', function (string $builder, bool $initialEnabled, bool $finalEnabled, string $operation): void {
    config(['talkto.dispatch_intents.enabled' => $initialEnabled]);
    $sourceCalls = 0;
    $flow = compatibilityFlow($builder);
    $message = $operation === 'send' ? $flow->send() : $flow->run(function () use (&$sourceCalls): array {
        $sourceCalls++;
        DB::table('compatibility_business')->insert(['marker' => 'validation-selected-business']);

        return ['synthetic' => true];
    });

    expect(config('talkto.dispatch_intents.enabled'))->toBe($finalEnabled)
        ->and(CompatibilityBuilderProbe::$validationCalls)->toBe(1)
        ->and($sourceCalls)->toBe($operation === 'run' ? 1 : 0)
        ->and($message)->toBeInstanceOf(TalktoMessage::class)
        ->and(TalktoMessage::query()->count())->toBe(1)
        ->and(TalktoEvent::query()->where('event_type', 'message_created')->count())->toBe(1)
        ->and(TalktoDispatchIntent::query()->count())->toBe($finalEnabled ? 1 : 0)
        ->and(DB::table('jobs')->count())->toBe(1);
    $queued = Queue::connection()->pop('compatibility-default');
    expect($queued)->not->toBeNull()->and($queued->resolveName())->toBe(SendTalktoMessage::class);
    $queued->delete();
})->with([
    'validation enables durable mode' => [CompatibilityEnablingValidationBuilder::class, false, true],
    'validation disables durable mode' => [CompatibilityDisablingValidationBuilder::class, true, false],
])->with(['send', 'run']);

test('validation cannot replace the factory and bypass durable preflight before source work', function (string $operation): void {
    $before = compatibilityState();
    $sourceCalls = 0;
    $flow = compatibilityFlow(CompatibilityReplacingFactoryBuilder::class);
    $caught = null;
    try {
        if ($operation === 'send') {
            $flow->send();
        } else {
            $flow->run(function () use (&$sourceCalls): array {
                $sourceCalls++;
                DB::table('compatibility_business')->insert(['marker' => 'must-not-run-after-factory-change']);

                return ['synthetic' => true];
            });
        }
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($caught?->getMessage())->toContain('factory', 'create', 'adapter')
        ->and(CompatibilityBuilderProbe::$validationCalls)->toBe(1)
        ->and(CompatibilityBuilderProbe::$factoryCalls)->toBe(0)
        ->and($sourceCalls)->toBe(0)
        ->and(compatibilityState())->toBe($before)
        ->and(DB::connection()->transactionLevel())->toBe(0);
})->with(['send', 'run']);

test('a builder that enables durable mode during validation is checked before persistence and source work', function (string $operation): void {
    config(['talkto.dispatch_intents.enabled' => false]);
    $before = compatibilityState();
    $sourceCalls = 0;
    $flow = compatibilityFlow(CompatibilityEnablingJobBuilder::class);
    $caught = null;
    try {
        if ($operation === 'send') {
            $flow->send();
        } else {
            $flow->run(function () use (&$sourceCalls): array {
                $sourceCalls++;
                DB::table('compatibility_business')->insert(['marker' => 'must-not-run-after-enabling']);

                return ['synthetic' => true];
            });
        }
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($caught?->getMessage())->toContain('TalktoFlowBuilder::sendJobClass()', 'adapter')
        ->and(config('talkto.dispatch_intents.enabled'))->toBeTrue()
        ->and(CompatibilityBuilderProbe::$validationCalls)->toBe(1)
        ->and(CompatibilityBuilderProbe::$jobSelectionCalls)->toBe(0)
        ->and(CompatibilityBuilderProbe::$dispatchCalls)->toBe(0)
        ->and($sourceCalls)->toBe(0)
        ->and(compatibilityState())->toBe($before);
})->with(['send', 'run']);
function compatibilityState(): array
{
    $state = [];
    foreach (['talkto_messages', 'talkto_events', 'talkto_dispatch_intents', 'talkto_attempts', 'talkto_dead_letters', 'jobs', 'compatibility_business'] as $table) {
        $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }

    return $state;
}

function compatibilityCreateJobs(string $connection): void
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

function compatibilityFlow(string $class = TalktoFlowBuilder::class, ?TalktoOutgoingMessageFactory $factory = null): TalktoFlowBuilder
{
    return (new $class('compatibility-flow', $factory ?? app(TalktoOutgoingMessageFactory::class)))
        ->to('peer')->command('domain.command')->payload(['synthetic' => true]);
}

class CompatibilityBuilderProbe
{
    public static int $jobSelectionCalls = 0;

    public static int $dispatchCalls = 0;

    public static int $validationCalls = 0;

    public static int $factoryCalls = 0;
}

class CompatibilityPlainBuilder extends TalktoFlowBuilder {}

class CompatibilityInheritedPlainBuilder extends CompatibilityPlainBuilder {}

class CompatibilityCustomSendJob extends SendTalktoMessage
{
    public function __construct(int $messageId)
    {
        parent::__construct($messageId);
        $this->onQueue('compatibility-custom');
    }
}

class CompatibilityDefaultQueueSendJob extends SendTalktoMessage {}

class CompatibilityUniqueSendJob extends SendTalktoMessage implements ShouldBeUnique {}

class CompatibilityMiddlewareSendJob extends SendTalktoMessage
{
    public $middleware = ['requires-adapter'];
}

class CompatibilityMissingConstructorArgumentJob extends SendTalktoMessage
{
    public function __construct(int $messageId, string $required)
    {
        parent::__construct($messageId);
    }
}

class CompatibilityJobBuilder extends TalktoFlowBuilder
{
    protected function sendJobClass(): string
    {
        CompatibilityBuilderProbe::$jobSelectionCalls++;

        return CompatibilityCustomSendJob::class;
    }
}

class CompatibilityDispatchBuilder extends TalktoFlowBuilder
{
    protected function dispatchSendJob(int $messageId): void
    {
        CompatibilityBuilderProbe::$dispatchCalls++;
        SendTalktoMessage::dispatch($messageId)->onQueue('compatibility-override')->afterCommit();
    }
}

class CompatibilityBothBuilder extends CompatibilityJobBuilder
{
    protected function dispatchSendJob(int $messageId): void
    {
        CompatibilityBuilderProbe::$dispatchCalls++;
        $class = $this->sendJobClass();
        $class::dispatch($messageId)->onQueue('compatibility-both')->afterCommit();
    }
}

class CompatibilityInheritedJobBuilder extends CompatibilityJobBuilder {}

class CompatibilityInheritedDispatchBuilder extends CompatibilityDispatchBuilder {}

class CompatibilityInheritedBothBuilder extends CompatibilityBothBuilder {}

class CompatibilityInvalidJobBuilder extends TalktoFlowBuilder
{
    protected function sendJobClass(): string
    {
        CompatibilityBuilderProbe::$jobSelectionCalls++;

        return stdClass::class;
    }
}

class CompatibilityValidationBuilder extends CompatibilityJobBuilder
{
    protected function validateReady(): void
    {
        CompatibilityBuilderProbe::$validationCalls++;
        DB::table('compatibility_business')->insert(['marker' => 'must-not-validate']);
        parent::validateReady();
    }
}

class CompatibilityEnablingValidationBuilder extends TalktoFlowBuilder
{
    protected function validateReady(): void
    {
        CompatibilityBuilderProbe::$validationCalls++;
        parent::validateReady();
        config(['talkto.dispatch_intents.enabled' => true]);
    }
}

class CompatibilityDisablingValidationBuilder extends TalktoFlowBuilder
{
    protected function validateReady(): void
    {
        CompatibilityBuilderProbe::$validationCalls++;
        parent::validateReady();
        config(['talkto.dispatch_intents.enabled' => false]);
    }
}

class CompatibilityReplacingFactoryBuilder extends TalktoFlowBuilder
{
    protected function validateReady(): void
    {
        CompatibilityBuilderProbe::$validationCalls++;
        parent::validateReady();
        $this->messageFactory = app(CompatibilityOutgoingFactory::class);
    }
}

class CompatibilityEnablingJobBuilder extends CompatibilityJobBuilder
{
    protected function validateReady(): void
    {
        CompatibilityBuilderProbe::$validationCalls++;
        parent::validateReady();
        config(['talkto.dispatch_intents.enabled' => true]);
    }
}
class CompatibilityOutgoingFactory extends TalktoOutgoingMessageFactory
{
    public function create(string $target, string $command, mixed $payload = [], array $options = []): TalktoMessage
    {
        CompatibilityBuilderProbe::$factoryCalls++;

        return parent::create($target, $command, $payload, $options);
    }
}

class CompatibilityMessage extends TalktoMessage {}

class CompatibilityEvent extends TalktoEvent {}

class CompatibilityIntent extends TalktoDispatchIntent {}

class CompatibilityHttpClient implements TalktoHttpClient
{
    public int $calls = 0;

    public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
    {
        $this->calls++;

        return new TalktoHttpResponse(200, '{"received":true,"status":"queued"}');
    }
}
