<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoFlowBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Mrezdev\LaravelTalkto\Tests\TestCase;

uses(TestCase::class);

test('mysql durable builder compatibility fails before business effects and preserves real queue recovery', function (): void {
    if (getenv('TALKTO_MYSQL_ENABLED') !== '1') {
        throw new RuntimeException('Requires an isolated MySQL 8.x server with CREATE DATABASE permission.');
    }
    $host = getenv('TALKTO_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('TALKTO_MYSQL_PORT') ?: 3306);
    $user = getenv('TALKTO_MYSQL_USER') ?: 'root';
    $password = getenv('TALKTO_MYSQL_PASSWORD') ?: '';
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $version = (string) $admin->query('SELECT VERSION()')->fetchColumn();
    expect($version)->toMatch('/^8\./')->not->toContain('MariaDB');
    $database = 'talkto_builder_'.bin2hex(random_bytes(12));
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $evidence = ['php_version' => PHP_VERSION, 'laravel_version' => app()->version(), 'mysql_version' => $version,
        'queue_backend' => 'Actual Laravel DatabaseQueue pop/fire, same actual MySQL connection; no Queue/Bus fake.', 'cases' => []];
    $evidencePath = getenv('TALKTO_BUILDER_COMPATIBILITY_MYSQL_EVIDENCE_PATH');
    try {
        config([
            'database.default' => 'builder_compatibility_mysql',
            'database.connections.builder_compatibility_mysql' => ['driver' => 'mysql', 'host' => $host, 'port' => $port,
                'database' => $database, 'username' => $user, 'password' => $password, 'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00'],
            'talkto.database.connection' => 'builder_compatibility_mysql', 'talkto.service' => 'testing',
            'talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.recovery_enabled' => true,
            'talkto.dispatch_intents.publish_after_commit' => true,
            'talkto.outgoing.peer' => ['url' => 'https://builder-mysql.invalid', 'secret' => 'synthetic-builder-secret'],
            'queue.default' => 'builder_compatibility_queue',
            'queue.connections.builder_compatibility_queue' => ['driver' => 'database', 'connection' => 'builder_compatibility_mysql',
                'table' => 'jobs', 'queue' => 'builder-default', 'retry_after' => 90, 'after_commit' => true],
        ]);
        $migrations = glob(__DIR__.'/../../database/migrations/*.php');
        sort($migrations);
        foreach (array_merge($migrations, glob(__DIR__.'/../../database/dispatch-intents/*.php')) as $migration) {
            (require $migration)->up();
        }
        $db = DB::connection('builder_compatibility_mysql');
        $db->statement('CREATE TABLE jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload LONGTEXT NOT NULL, attempts TINYINT UNSIGNED NOT NULL, reserved_at INT UNSIGNED NULL, available_at INT UNSIGNED NOT NULL, created_at INT UNSIGNED NOT NULL, INDEX jobs_queue_index(queue)) ENGINE=InnoDB');
        $db->statement('CREATE TABLE builder_business (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, marker VARCHAR(80) NOT NULL) ENGINE=InnoDB');
        $client = new MysqlBuilderCompatibilityHttpClient;
        app()->instance(TalktoHttpClient::class, $client);
        $counts = fn (): array => ['messages' => $db->table('talkto_messages')->count(),
            'intents' => $db->table('talkto_dispatch_intents')->count(), 'events' => $db->table('talkto_events')->count(),
            'jobs' => $db->table('jobs')->count(), 'business_rows' => $db->table('builder_business')->count(),
            'http_calls' => $client->calls, 'custom_job_executions' => MysqlBuilderCompatibilityCustomJob::$executions];
        $reset = function () use ($db, $client): void {
            TalktoDispatchTestHooks::reset();
            config(['talkto.dispatch_intents.enabled' => true, 'talkto.dispatch_intents.publish_after_commit' => true,
                'talkto.jobs.send_message' => SendTalktoMessage::class, 'talkto.models.message' => TalktoMessage::class]);
            foreach (['talkto_dispatch_intents', 'talkto_events', 'talkto_attempts', 'talkto_dead_letters', 'talkto_messages', 'jobs', 'builder_business'] as $table) {
                $db->table($table)->delete();
            }
            $client->calls = 0;
            MysqlBuilderCompatibilityProbe::$selectionCalls = 0;
            MysqlBuilderCompatibilityProbe::$dispatchCalls = 0;
            MysqlBuilderCompatibilityProbe::$factoryCalls = 0;
            MysqlBuilderCompatibilityCustomJob::$executions = 0;
        };
        $flow = fn (string $class = TalktoFlowBuilder::class, ?TalktoOutgoingMessageFactory $factory = null): TalktoFlowBuilder => (new $class('mysql-builder-compatibility', $factory ?? app(TalktoOutgoingMessageFactory::class)))
            ->to('peer')->command('domain.command')->payload(['synthetic' => true]);
        $recover = function (string $messageId): array {
            $exit = Artisan::call('talkto:dispatch-intents', ['--execute' => true, '--message-id' => $messageId,
                '--min-age' => 0, '--limit' => 10, '--json' => true]);
            expect($exit)->toBe(0);

            return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        };
        $consume = function (string $queueName, string $jobClass) use ($db): void {
            $job = Queue::connection('builder_compatibility_queue')->pop($queueName);
            expect($job)->not->toBeNull()->and($job->resolveName())->toBe($jobClass);
            $job->fire();
            $job->delete();
            expect($db->table('jobs')->count())->toBe(0);
        };

        foreach ([TalktoFlowBuilder::class, MysqlBuilderCompatibilityPlain::class, MysqlBuilderCompatibilityPlainChild::class] as $class) {
            $reset();
            $message = $flow($class)->send();
            expect($message->exists)->toBeTrue()->and($db->table('talkto_dispatch_intents')->value('status'))->toBe('published')
                ->and($db->table('jobs')->count())->toBe(1);
            $consume('builder-default', SendTalktoMessage::class);
            expect($client->calls)->toBe(1)->and($message->fresh()->transport_status)->toBe('sent');
            $evidence['cases']['durable-'.$class] = $counts();
        }

        foreach ([MysqlBuilderCompatibilitySelection::class, MysqlBuilderCompatibilityDispatch::class,
            MysqlBuilderCompatibilityBoth::class, MysqlBuilderCompatibilitySelectionChild::class,
            MysqlBuilderCompatibilityDispatchChild::class] as $class) {
            foreach (['send', 'run'] as $operation) {
                $reset();
                $called = false;
                $before = $counts();
                $action = function () use (&$called, $db): array {
                    $called = true;
                    $db->table('builder_business')->insert(['marker' => 'must-not-run']);

                    return ['synthetic' => true];
                };
                expect(fn () => $operation === 'run' ? $flow($class)->run($action) : $flow($class)->send())
                    ->toThrow(InvalidArgumentException::class, 'Durable dispatch does not support a custom TalktoFlowBuilder::');
                expect($called)->toBeFalse()->and($counts())->toBe($before)
                    ->and(MysqlBuilderCompatibilityProbe::$selectionCalls)->toBe(0)
                    ->and(MysqlBuilderCompatibilityProbe::$dispatchCalls)->toBe(0);
                $evidence['cases']['rejected-'.$class.'-'.$operation] = $counts() + ['source_action_called' => $called];
            }
        }

        $reset();
        $sourceCalled = false;
        $factory = app(MysqlBuilderCompatibilityFactory::class);
        expect(fn () => $flow(factory: $factory)->send())->toThrow(InvalidArgumentException::class, 'custom outgoing factory create');
        expect(fn () => $flow(factory: $factory)->run(function () use (&$sourceCalled, $db): array {
            $sourceCalled = true;
            $db->table('builder_business')->insert(['marker' => 'factory-must-not-run']);

            return ['synthetic' => true];
        }))->toThrow(InvalidArgumentException::class, 'custom outgoing factory create');
        expect($sourceCalled)->toBeFalse()->and(MysqlBuilderCompatibilityProbe::$factoryCalls)->toBe(0)
            ->and($counts())->toMatchArray(['messages' => 0, 'intents' => 0, 'events' => 0, 'jobs' => 0, 'business_rows' => 0]);
        $evidence['cases']['unsupported-factory'] = $counts() + ['source_action_called' => $sourceCalled];

        foreach ([MysqlBuilderCompatibilitySelection::class, MysqlBuilderCompatibilityDispatch::class, MysqlBuilderCompatibilityBoth::class] as $class) {
            $reset();
            config(['talkto.dispatch_intents.enabled' => false]);
            $message = $flow($class)->run(function () use ($db): array {
                $db->table('builder_business')->insert(['marker' => 'legacy-ran']);

                return ['synthetic' => true];
            });
            expect($message->source_action_status)->toBe('succeeded')->and($db->table('builder_business')->count())->toBe(1)
                ->and($db->table('talkto_dispatch_intents')->count())->toBe(0)->and($db->table('jobs')->count())->toBe(1);
            $selection = $class !== MysqlBuilderCompatibilityDispatch::class;
            $consume($selection ? 'builder-custom' : 'builder-override', $selection ? MysqlBuilderCompatibilityCustomJob::class : SendTalktoMessage::class);
            expect(MysqlBuilderCompatibilityProbe::$selectionCalls)->toBe($selection ? 1 : 0)
                ->and(MysqlBuilderCompatibilityProbe::$dispatchCalls)->toBe($class === MysqlBuilderCompatibilitySelection::class ? 0 : 1);
            $evidence['cases']['legacy-'.$class] = $counts();
        }

        $reset();
        expect(fn () => $db->transaction(function () use ($db, $flow): void {
            $db->table('builder_business')->insert(['marker' => 'rolled-back']);
            $flow()->send();
            throw new RuntimeException('Synthetic outer transaction rollback.');
        }))->toThrow(RuntimeException::class, 'Synthetic outer transaction rollback.');
        expect($counts())->toMatchArray(['messages' => 0, 'intents' => 0, 'events' => 0, 'jobs' => 0, 'business_rows' => 0]);
        $evidence['cases']['atomic-rollback'] = $counts();

        $reset();
        config(['talkto.jobs.send_message' => MysqlBuilderCompatibilityCustomJob::class]);
        TalktoDispatchTestHooks::set('dispatch_intent.before_claim', fn () => throw new RuntimeException('Synthetic producer interruption after commit.'));
        $message = $flow()->options(['message_id' => 'mysql-builder-custom', 'idempotency_key' => 'mysql-builder-custom-key'])->send();
        expect($db->table('talkto_dispatch_intents')->value('job_class'))->toBe(MysqlBuilderCompatibilityCustomJob::class)
            ->and($db->table('talkto_dispatch_intents')->value('status'))->toBe('ready')->and($db->table('jobs')->count())->toBe(0);
        $same = $flow()->idempotencyKey('mysql-builder-custom-key')->send();
        expect($same->id)->toBe($message->id)->and($db->table('talkto_messages')->count())->toBe(1)
            ->and($db->table('talkto_dispatch_intents')->count())->toBe(1)->and($db->table('jobs')->count())->toBe(0);
        TalktoDispatchTestHooks::reset();
        config(['talkto.jobs.send_message' => SendTalktoMessage::class]);
        $drift = $recover($message->message_id);
        expect($drift['candidates'][0]['result']['reason'])->toBe('routing_configuration_changed')
            ->and($db->table('jobs')->count())->toBe(0)->and($db->table('talkto_dispatch_intents')->value('status'))->toBe('ready');
        config(['talkto.jobs.send_message' => MysqlBuilderCompatibilityCustomJob::class]);
        $recovered = $recover($message->message_id);
        expect($recovered['candidates'][0]['result']['published'])->toBeTrue()->and($db->table('jobs')->count())->toBe(1);
        $repeat = $recover($message->message_id);
        expect($repeat['count'])->toBe(0)->and($db->table('jobs')->count())->toBe(1);
        $consume('builder-custom', MysqlBuilderCompatibilityCustomJob::class);
        expect($client->calls)->toBe(1)->and(MysqlBuilderCompatibilityCustomJob::$executions)->toBe(1)
            ->and($message->fresh()->transport_status)->toBe('sent');
        $evidence['cases']['custom-job-interruption-recovery-and-drift'] = $counts() + ['recovered' => true,
            'wrong_configuration_rejected' => true, 'repeated_recovery_count' => $repeat['count']];

        $reset();
        config(['talkto.models.message' => MysqlBuilderCompatibilityMessage::class]);
        $customModel = $flow(MysqlBuilderCompatibilityPlainChild::class)->send();
        expect($customModel)->toBeInstanceOf(MysqlBuilderCompatibilityMessage::class)
            ->and($customModel->getConnection()->getName())->toBe('builder_compatibility_mysql')
            ->and($db->table('talkto_dispatch_intents')->value('message_class'))->toBe(MysqlBuilderCompatibilityMessage::class)
            ->and($db->table('talkto_dispatch_intents')->value('status'))->toBe('published');
        $consume('builder-default', SendTalktoMessage::class);
        expect($client->calls)->toBe(1)->and($customModel->fresh()->transport_status)->toBe('sent');
        $evidence['cases']['custom-model-and-named-connection'] = $counts();

        $reset();
        config(['talkto.dispatch_intents.publish_after_commit' => false]);
        $terminal = $flow()->send();
        $terminal->forceFill(['overall_status' => 'completed', 'transport_status' => 'sent', 'completed_at' => now()])->save();
        $before = $terminal->fresh()->getRawOriginal();
        $recover($terminal->message_id);
        expect($terminal->fresh()->getRawOriginal())->toBe($before)->and($db->table('jobs')->count())->toBe(0)
            ->and($db->table('talkto_dispatch_intents')->value('status'))->toBe('cancelled');
        $evidence['cases']['terminal-not-resurrected'] = $counts() + ['terminal_unchanged' => true];
        $evidence['result'] = 'PASS';
    } catch (Throwable $exception) {
        $evidence['result'] = 'FAIL';
        $evidence['failure_class'] = $exception::class;
        throw $exception;
    } finally {
        TalktoDispatchTestHooks::reset();
        DB::purge('builder_compatibility_mysql');
        $admin->exec("DROP DATABASE `{$database}`");
        $evidence['owned_database_dropped'] = true;
        if (is_string($evidencePath) && $evidencePath !== '') {
            file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }
});

class MysqlBuilderCompatibilityProbe
{
    public static int $selectionCalls = 0;

    public static int $dispatchCalls = 0;

    public static int $factoryCalls = 0;
}

class MysqlBuilderCompatibilityPlain extends TalktoFlowBuilder {}

class MysqlBuilderCompatibilityPlainChild extends MysqlBuilderCompatibilityPlain {}

class MysqlBuilderCompatibilityMessage extends TalktoMessage {}

class MysqlBuilderCompatibilitySelection extends TalktoFlowBuilder
{
    protected function sendJobClass(): string
    {
        MysqlBuilderCompatibilityProbe::$selectionCalls++;

        return MysqlBuilderCompatibilityCustomJob::class;
    }
}

class MysqlBuilderCompatibilitySelectionChild extends MysqlBuilderCompatibilitySelection {}

class MysqlBuilderCompatibilityDispatch extends TalktoFlowBuilder
{
    protected function dispatchSendJob(int $messageId): void
    {
        MysqlBuilderCompatibilityProbe::$dispatchCalls++;
        SendTalktoMessage::dispatch($messageId)->onQueue('builder-override')->afterCommit();
    }
}

class MysqlBuilderCompatibilityDispatchChild extends MysqlBuilderCompatibilityDispatch {}

class MysqlBuilderCompatibilityBoth extends MysqlBuilderCompatibilitySelection
{
    protected function dispatchSendJob(int $messageId): void
    {
        MysqlBuilderCompatibilityProbe::$dispatchCalls++;
        parent::dispatchSendJob($messageId);
    }
}

class MysqlBuilderCompatibilityCustomJob extends SendTalktoMessage
{
    public static int $executions = 0;

    public function __construct(int $messageId)
    {
        parent::__construct($messageId);
        $this->onQueue('builder-custom');
    }

    public function handle(TalktoOutgoingEnvelopeBuilder $builder, ?TalktoRetryPolicy $retryPolicy = null): void
    {
        self::$executions++;
        parent::handle($builder, $retryPolicy);
    }
}

class MysqlBuilderCompatibilityFactory extends TalktoOutgoingMessageFactory
{
    public function create(string $target, string $command, mixed $payload = [], array $options = []): TalktoMessage
    {
        MysqlBuilderCompatibilityProbe::$factoryCalls++;

        return parent::create($target, $command, $payload, $options);
    }
}

class MysqlBuilderCompatibilityHttpClient implements TalktoHttpClient
{
    public int $calls = 0;

    public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
    {
        $this->calls++;

        return new TalktoHttpResponse(200, '{"received":true,"status":"queued"}');
    }
}
