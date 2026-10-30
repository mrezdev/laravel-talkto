<?php

use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

test('reliable callbacks persist results atomically and recover without executing handlers on real mysql queues', function (): void {
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
    $suffix = bin2hex(random_bytes(12));
    $database = 'talkto_callback_'.$suffix;
    $sourceDatabase = 'talkto_callback_source_'.$suffix;
    $queueDatabase = 'talkto_callback_queue_'.$suffix;
    foreach ([$database, $sourceDatabase, $queueDatabase] as $name) {
        $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    $directory = sys_get_temp_dir().'/talkto-callback-barriers-'.bin2hex(random_bytes(12));
    $processes = [];
    $path = getenv('TALKTO_RELIABLE_CALLBACK_MYSQL_EVIDENCE_PATH');
    $evidence = ['mysql_version' => $version, 'php_version' => PHP_VERSION, 'laravel_version' => app()->version(),
        'queue_backend' => 'Actual Laravel DatabaseQueue and jobs; signed callback receiver reached through controlled in-process HTTP transport. No Queue/Bus fake.', 'cases' => []];
    try {
        mkdir($directory, 0700);
        $connection = ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $database, 'username' => $user, 'password' => $password,
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00'];
        $sourceConnection = array_merge($connection, ['database' => $sourceDatabase]);
        $queueConnection = array_merge($connection, ['database' => $queueDatabase]);
        config(['database.connections.callback_parent' => $connection, 'database.connections.callback_source_parent' => $sourceConnection,
            'database.connections.callback_queue_parent' => $queueConnection, 'talkto.service' => 'destination']);
        foreach (['callback_parent', 'callback_source_parent'] as $name) {
            config(['talkto.database.connection' => $name]);
            $migrations = glob(__DIR__.'/../../database/migrations/*.php');
            sort($migrations);
            foreach ($migrations as $migration) {
                (require $migration)->up();
            }
        }
        config(['talkto.database.connection' => 'callback_parent']);
        $db = DB::connection('callback_parent');
        $sourceDb = DB::connection('callback_source_parent');
        $queueDb = DB::connection('callback_queue_parent');
        $installIntents = function () use ($db): void {
            if (! $db->getSchemaBuilder()->hasTable('talkto_dispatch_intents')) {
                foreach (glob(__DIR__.'/../../database/dispatch-intents/*.php') as $migration) {
                    (require $migration)->up();
                }
            }
        };
        $installIntents();
        foreach ([$db, $queueDb] as $owner) {
            $owner->statement('CREATE TABLE jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload LONGTEXT NOT NULL, attempts TINYINT UNSIGNED NOT NULL, reserved_at INT UNSIGNED NULL, available_at INT UNSIGNED NOT NULL, created_at INT UNSIGNED NOT NULL, INDEX jobs_queue_index(queue)) ENGINE=InnoDB');
        }
        $db->statement('CREATE TABLE callback_probe_metrics (name VARCHAR(50) PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');
        foreach (['publications', 'callback_publications', 'executions', 'handler_calls', 'http_calls', 'command_deliveries', 'deliveries', 'acknowledgements', 'deadlock_probe'] as $metric) {
            $db->table('callback_probe_metrics')->insert(['name' => $metric, 'value' => 0]);
        }
        $reset = function (bool $intents = true) use ($db, $sourceDb, $queueDb, $installIntents): void {
            if ($intents) {
                $installIntents();
            }
            if ($db->getSchemaBuilder()->hasTable('talkto_dispatch_intents')) {
                $db->table('talkto_dispatch_intents')->delete();
                if (! $intents) {
                    $db->getSchemaBuilder()->drop('talkto_dispatch_intents');
                }
            }
            foreach ([$db, $sourceDb] as $owner) {
                foreach (['talkto_events', 'talkto_attempts', 'talkto_dead_letters', 'talkto_nonces', 'talkto_messages'] as $table) {
                    $owner->table($table)->delete();
                }
            }
            $db->table('jobs')->delete();
            $queueDb->table('jobs')->delete();
            $db->table('callback_probe_metrics')->update(['value' => 0]);
        };
        $seed = function (string $id, int $maxAttempts = 5) use ($db, $sourceDb): void {
            $payload = ['synthetic' => true];
            $hash = app(TalktoPayloadHasher::class)->hash($payload);
            $attributes = ['message_id' => $id, 'correlation_id' => 'correlation-'.$id, 'command' => 'domain.command', 'source_service' => 'source',
                'target_service' => 'destination', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'payload_hash' => $hash,
                'business_key' => 'business-'.$id, 'idempotency_key' => 'business-key-'.$id, 'attempts' => 0, 'retry_count' => 0,
                'max_attempts' => $maxAttempts, 'created_at' => now(), 'updated_at' => now()];
            $db->table('talkto_messages')->insert($attributes + ['direction' => 'incoming', 'overall_status' => 'queued', 'destination_action_status' => 'queued', 'received_at' => now()]);
            $sourceDb->table('talkto_messages')->insert($attributes + ['direction' => 'outgoing', 'overall_status' => 'destination_received',
                'source_action_status' => 'succeeded_assumed', 'transport_status' => 'sent', 'destination_receive_status' => 'received']);
        };
        $start = function (string $name, string $operation, array $extra = []) use ($connection, $sourceConnection, $queueConnection, $directory, &$processes): Process {
            $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/ReliableCallbackMysqlWorker.php'], dirname(__DIR__, 2));
            $process->setInput(json_encode(array_merge(['worker' => $name, 'operation' => $operation, 'message_id' => $name,
                'connection' => $connection, 'source_connection' => $sourceConnection, 'queue_connection' => $queueConnection, 'directory' => $directory], $extra), JSON_THROW_ON_ERROR));
            $process->setTimeout(55);
            $process->start();
            $processes[] = $process;

            return $process;
        };
        $finish = function (string $name, Process $process) use (&$evidence): array {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput().' '.$process->getOutput());
            $value = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $value['process_exit_code'] = $process->getExitCode();
            $value['process_stderr'] = $process->getErrorOutput();
            $evidence['cases'][$name] = $value;

            return $value;
        };
        $run = fn (string $name, string $operation, array $extra = []) => $finish($name, $start($name, $operation, $extra));
        $await = function (string $name, string $marker, Process $process) use ($directory): array {
            $deadline = microtime(true) + 25;
            $file = $directory.'/'.$name.'.'.$marker.'.json';
            while (! is_file($file)) {
                if (! $process->isRunning() || microtime(true) > $deadline) {
                    throw new RuntimeException('Callback fixture milestone absent: '.$name.'.'.$marker.' '.$process->getErrorOutput().' '.$process->getOutput());
                }
                usleep(10000);
                clearstatcache();
            }

            return json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        };
        $kill = function (string $name, Process $process, string $marker) use ($await, &$evidence): void {
            $observed = $await($name, $marker, $process);
            $process->stop(0.1);
            $evidence['cases'][$name] = ['deliberately_terminated' => true, 'exit_code' => $process->getExitCode(), 'milestone' => $observed];
        };
        $callbackId = fn (string $id, string $status = 'succeeded'): string => 'cb-'.sha1($id.'|'.$status);
        $assertProtected = function (string $id) use ($db): void {
            $incoming = $db->table('talkto_messages')->where('message_id', $id)->first();
            expect($incoming->overall_status)->toBe('processing')->and($incoming->destination_action_status)->toBe('processing')
                ->and($incoming->locked_by)->toStartWith('reliable-callback:')
                ->and($db->table('talkto_messages')->where('parent_message_id', $id)->count())->toBe(0)
                ->and($db->table('talkto_dispatch_intents')->count())->toBe(0)
                ->and($db->table('talkto_attempts')->where('message_id', $id)->value('status'))->toBe('processing');
        };

        // Legacy installation without either reliable-callback storage or dispatch-intent table.
        $reset(false);
        $legacy = ['reliable_enabled' => false, 'durable_enabled' => false];
        $sent = $run('legacy-complete-flow', 'legacy-source-send', $legacy);
        expect($sent['exceptions'])->toBe([])->and($sent['intents'])->toBe([])->and($sent['jobs'])->toHaveCount(1)
            ->and($sent['metrics']['command_deliveries'])->toBe(1)->and($sent['operation_intent_sql_count'])->toBe(0);
        $processed = $run('legacy-incoming-consumer', 'consume', $legacy + ['message_id' => 'legacy-complete-flow']);
        expect($processed['exceptions'])->toBe([])->and($processed['metrics']['handler_calls'])->toBe(1)->and($processed['callbacks'])->toHaveCount(1)
            ->and($processed['jobs'])->toHaveCount(1)->and($processed['operation_intent_sql_count'])->toBe(0);
        $delivered = $run('legacy-callback-consumer', 'consume', $legacy + ['message_id' => 'legacy-complete-flow']);
        expect($delivered['exceptions'])->toBe([])->and($delivered['jobs'])->toBe([])->and($delivered['metrics']['handler_calls'])->toBe(1)
            ->and($delivered['metrics']['deliveries'])->toBe(1)->and($delivered['metrics']['acknowledgements'])->toBe(1)
            ->and($delivered['source_messages'][0]['overall_status'])->toBe('completed')
            ->and($db->table('talkto_messages')->where('direction', 'outgoing')->value('overall_status'))->toBe('completed')
            ->and($delivered['operation_intent_sql_count'])->toBe(0);

        // Legacy transport Retry, DLQ reprocess and stale worker recovery also need no intent table.
        $reset(false);
        $seed('legacy-retry');
        $run('legacy-retry', 'process', $legacy);
        $failed = $run('legacy-retry-failed', 'consume', $legacy + ['message_id' => 'legacy-retry', 'http_outage' => true]);
        expect($db->table('talkto_messages')->where('direction', 'outgoing')->value('overall_status'))->toBe('failed_retryable');
        $retried = $run('legacy-retry-command', 'retry', $legacy + ['message_id' => 'legacy-retry']);
        expect($retried['jobs'])->toHaveCount(1)->and($retried['operation_intent_sql_count'])->toBe(0);
        $completed = $run('legacy-retry-completed', 'consume', $legacy + ['message_id' => 'legacy-retry']);
        expect($completed['metrics']['handler_calls'])->toBe(1)->and($completed['metrics']['deliveries'])->toBe(1)
            ->and($completed['source_messages'][0]['overall_status'])->toBe('completed')->and($completed['intents'])->toBe([]);

        $reset(false);
        $seed('legacy-dlq');
        $run('legacy-dlq', 'process', $legacy);
        $failed = $run('legacy-dlq-failed', 'consume', $legacy + ['message_id' => 'legacy-dlq', 'http_outage' => true, 'http_status' => 400]);
        expect($db->table('talkto_messages')->where('direction', 'outgoing')->value('overall_status'))->toBe('failed_final')
            ->and($db->table('talkto_dead_letters')->count())->toBe(1);
        $reprocessed = $run('legacy-dlq-command', 'dlq', $legacy + ['message_id' => 'legacy-dlq']);
        expect($reprocessed['jobs'])->toHaveCount(1)->and($reprocessed['operation_intent_sql_count'])->toBe(0);
        $completed = $run('legacy-dlq-completed', 'consume', $legacy + ['message_id' => 'legacy-dlq']);
        expect($completed['metrics']['handler_calls'])->toBe(1)->and($completed['metrics']['deliveries'])->toBe(1)
            ->and($completed['source_messages'][0]['overall_status'])->toBe('completed')->and($completed['intents'])->toBe([]);

        $reset(false);
        $seed('legacy-stale');
        $run('legacy-stale', 'process', $legacy);
        $interrupted = $start('legacy-stale-interrupted', 'consume', $legacy + ['message_id' => 'legacy-stale', 'pause_http' => true]);
        $kill('legacy-stale-interrupted', $interrupted, 'http-started');
        $db->table('talkto_messages')->where('direction', 'outgoing')->update(['locked_at' => now()->subHour()]);
        $db->table('jobs')->whereNotNull('reserved_at')->update(['reserved_at' => 0]);
        $recovered = $run('legacy-stale-command', 'stale', $legacy + ['message_id' => 'legacy-stale']);
        expect($recovered['jobs'])->toHaveCount(2)->and($recovered['operation_intent_sql_count'])->toBe(0);
        $first = $run('legacy-stale-consumer-first', 'consume', $legacy + ['message_id' => 'legacy-stale']);
        $second = $run('legacy-stale-consumer-second', 'consume', $legacy + ['message_id' => 'legacy-stale']);
        expect($first['metrics']['deliveries'])->toBe(1)->and($second['metrics']['deliveries'])->toBe(1)
            ->and($second['metrics']['handler_calls'])->toBe(1)->and($second['jobs'])->toBe([])
            ->and($second['source_messages'][0]['overall_status'])->toBe('completed')->and($second['intents'])->toBe([]);

        // Detectable prerequisites fail before any Handler or processing/result write.
        foreach (['missing_queue_table' => true, 'sync_queue' => true, 'foreign_attempt' => true, 'durable_enabled' => false] as $option => $value) {
            $reset();
            $name = 'preflight-'.str_replace('_', '-', $option);
            $seed($name);
            $rejected = $run($name, 'process', [$option => $value]);
            expect($rejected['exceptions'])->not->toBe([])->and($rejected['metrics']['handler_calls'])->toBe(0)
                ->and($rejected['callbacks'])->toBe([])->and($rejected['intents'])->toBe([])->and($rejected['jobs'])->toBe([])
                ->and($db->table('talkto_messages')->where('message_id', $name)->value('overall_status'))->toBe('queued')
                ->and($db->table('talkto_attempts')->count())->toBe(0);
        }
        $reset(false);
        $seed('missing-intent-table');
        $rejected = $run('missing-intent-table', 'process');
        expect($rejected['exceptions'])->not->toBe([])->and($rejected['metrics']['handler_calls'])->toBe(0)
            ->and($rejected['callbacks'])->toBe([])->and($rejected['jobs'])->toBe([])->and($rejected['attempts'])->toBe([]);

        foreach (['nested-commit', 'nested-rollback'] as $operation) {
            $reset();
            $seed($operation);
            $rejected = $run($operation, $operation);
            expect($rejected['exceptions'])->not->toBe([])->and($rejected['metrics']['handler_calls'])->toBe(0)
                ->and($rejected['callbacks'])->toBe([])->and($rejected['intents'])->toBe([])->and($rejected['jobs'])->toBe([])
                ->and($db->table('talkto_messages')->where('message_id', $operation)->value('overall_status'))->toBe('queued');
        }

        // Every eligible result commits its unchanged wire representation together with the callback intent.
        foreach (['succeeded', 'skipped', 'failed_retryable', 'failed_final', 'unexpected'] as $type) {
            $reset();
            $name = 'result-'.str_replace('_', '-', $type);
            $seed($name);
            $processed = $run($name, 'process', ['result_type' => $type]);
            expect($processed['exceptions'])->toBe([])->and($processed['metrics']['handler_calls'])->toBe(1)
                ->and($processed['callbacks'])->toHaveCount(1)->and($processed['intents'])->toHaveCount(1)
                ->and($processed['intents'][0]['purpose'])->toBe('result_callback')->and($processed['intents'][0]['status'])->toBe('published')
                ->and($processed['metrics']['callback_publications'])->toBe(1)->and($processed['jobs'])->toHaveCount(1);
            $callback = $db->table('talkto_messages')->where('direction', 'outgoing')->sole();
            $payload = json_decode($callback->payload, true, flags: JSON_THROW_ON_ERROR);
            $expectedStatus = $type === 'unexpected' ? 'failed_retryable' : $type;
            expect($payload['status'])->toBe($expectedStatus)->and($callback->message_id)->toBe($callbackId($name, $expectedStatus))
                ->and($callback->parent_message_id)->toBe($name)->and($callback->correlation_id)->toBe('correlation-'.$name)
                ->and($callback->payload_hash)->toBe(app(TalktoPayloadHasher::class)->hash($payload));
            $consumed = $run($name.'-consumer', 'consume', ['message_id' => $name]);
            expect($consumed['exceptions'])->toBe([])->and($consumed['metrics']['handler_calls'])->toBe(1)
                ->and($consumed['metrics']['deliveries'])->toBe(1)->and($consumed['metrics']['acknowledgements'])->toBe(1)
                ->and($db->table('talkto_messages')->where('direction', 'outgoing')->value('overall_status'))->toBe('completed');
        }

        // The handler's retryable result remains retryable on the wire after local retry exhaustion.
        $reset();
        $seed('retry-exhaustion', 1);
        $exhausted = $run('retry-exhaustion', 'process', ['result_type' => 'failed_retryable', 'incoming_retry_enabled' => true]);
        expect($exhausted['exceptions'])->toBe([])
            ->and($db->table('talkto_messages')->where('message_id', 'retry-exhaustion')->value('overall_status'))->toBe('failed_final');
        $payload = json_decode($db->table('talkto_messages')->where('direction', 'outgoing')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        expect($payload['retryable'])->toBeTrue()->and($payload['status'])->toBe('failed_retryable')
            ->and($exhausted['callbacks'][0]['message_id'])->toBe($callbackId('retry-exhaustion', 'failed_retryable'));
        $delivered = $run('retry-exhaustion-consumer', 'consume', ['message_id' => 'retry-exhaustion']);
        expect($delivered['metrics']['handler_calls'])->toBe(1)->and($delivered['metrics']['deliveries'])->toBe(1);

        // Supported configured subclasses use their actual custom queues and existing jobs.
        $reset();
        $custom = ['custom_jobs' => true, 'message_id' => 'custom-complete-flow'];
        $sent = $run('custom-complete-flow', 'legacy-source-send', $custom);
        expect($sent['exceptions'])->toBe([])->and($sent['jobs'])->toHaveCount(1)
            ->and($sent['jobs'][0]['queue'])->toBe('callback-custom-in');
        $processed = $run('custom-incoming-consumer', 'consume', $custom + ['queue_name' => 'callback-custom-in']);
        expect($processed['exceptions'])->toBe([])->and($processed['job_class'])->toBe('ReliableCallbackMysqlIncomingJob')
            ->and($processed['metrics']['handler_calls'])->toBe(1)->and($processed['jobs'])->toHaveCount(1)
            ->and($processed['jobs'][0]['queue'])->toBe('callback-custom-out');
        $delivered = $run('custom-outgoing-consumer', 'consume', $custom + ['queue_name' => 'callback-custom-out']);
        expect($delivered['exceptions'])->toBe([])->and($delivered['job_class'])->toBe('ReliableCallbackMysqlSendJob')
            ->and($delivered['metrics']['handler_calls'])->toBe(1)->and($delivered['metrics']['deliveries'])->toBe(1)
            ->and($delivered['source_messages'][0]['overall_status'])->toBe('completed');

        $reset();
        $seed('custom-model-binding');
        $customModel = $run('custom-model-binding', 'process', ['custom_model' => true]);
        expect($customModel['exceptions'])->toBe([])->and($customModel['intents'][0]['message_class'])->toBe('ReliableCallbackMysqlMessage');
        $customDelivery = $run('custom-model-consumer', 'consume', ['message_id' => 'custom-model-binding', 'custom_model' => true]);
        expect($customDelivery['metrics']['handler_calls'])->toBe(1)->and($customDelivery['metrics']['deliveries'])->toBe(1)
            ->and($customDelivery['source_messages'][0]['overall_status'])->toBe('completed');

        // A processor dies while durable processing evidence exists, including after the handler returned.
        foreach (['reliable_callback.before_handler', 'reliable_callback.after_handler', 'reliable_callback.result_persisted', 'reliable_callback.callback_persisted', 'reliable_callback.before_commit'] as $index => $hook) {
            $reset();
            $name = 'crash-before-commit-'.$index;
            $seed($name);
            $producer = $start($name, 'process', ['hook' => $hook]);
            $kill($name, $producer, 'hook');
            $assertProtected($name);
            $expectedCalls = $hook === 'reliable_callback.before_handler' ? 0 : 1;
            expect($db->table('callback_probe_metrics')->where('name', 'handler_calls')->value('value'))->toBe($expectedCalls);
            $db->table('talkto_messages')->where('message_id', $name)->update(['locked_at' => now()->subHour()]);
            $repeat = $run($name.'-repeat', 'process', ['message_id' => $name, 'reliable_enabled' => false]);
            $stale = $run($name.'-stale', 'stale', ['message_id' => $name, 'reliable_enabled' => false]);
            $retry = $run($name.'-retry', 'retry', ['message_id' => $name, 'reliable_enabled' => false]);
            expect($repeat['metrics']['handler_calls'])->toBe($expectedCalls)->and($stale['metrics']['handler_calls'])->toBe($expectedCalls)
                ->and($retry['metrics']['handler_calls'])->toBe($expectedCalls)->and($retry['jobs'])->toBe([]);
            $assertProtected($name);
        }
        $reset();
        $seed('handler-running');
        $producer = $start('handler-running', 'process', ['pause_handler' => true]);
        $kill('handler-running', $producer, 'handler-started');
        $assertProtected('handler-running');
        expect($db->table('callback_probe_metrics')->where('name', 'handler_calls')->value('value'))->toBe(1);

        // Explicit persistence/serialization failures retain protected investigation evidence.
        foreach (['invalid-result', 'result-write-throw', 'callback-write-throw'] as $name) {
            $reset();
            $seed($name);
            $options = $name === 'invalid-result' ? ['result_type' => 'invalid'] : ['hook' => $name === 'result-write-throw' ? 'reliable_callback.result_persisted' : 'reliable_callback.callback_persisted', 'throw_hook' => true];
            $failure = $run($name, 'process', $options);
            expect($failure['exceptions'])->not->toBe([])->and($failure['metrics']['handler_calls'])->toBe(1);
            $assertProtected($name);
            expect($db->table('talkto_events')->where('message_id', $name)->where('event_type', 'reliable_callback_investigation_required')->exists())->toBeTrue();
            $repeat = $run($name.'-repeat', 'process', ['message_id' => $name]);
            expect($repeat['metrics']['handler_calls'])->toBe(1)->and($repeat['callbacks'])->toBe([])->and($repeat['intents'])->toBe([]);
        }

        // Committed results survive producer death before publication, and existing jobs finish delivery.
        foreach ([true, false] as $sameConnection) {
            $reset();
            $name = $sameConnection ? 'postcommit-atomic' : 'postcommit-separate';
            $seed($name);
            $producer = $start($name, 'process', ['publish_after_commit' => false, 'same_connection' => $sameConnection, 'hook' => 'reliable_callback.committed']);
            $kill($name, $producer, 'hook');
            expect($db->table('talkto_messages')->where('message_id', $name)->value('overall_status'))->toBe('succeeded')
                ->and($db->table('talkto_messages')->where('parent_message_id', $name)->count())->toBe(1)
                ->and($db->table('talkto_dispatch_intents')->value('status'))->toBe('ready')
                ->and($db->table('talkto_attempts')->where('message_id', $name)->value('status'))->toBe('succeeded');
            $recovered = $run($name.'-recover', 'recover', ['message_id' => $name, 'callback_id' => $callbackId($name), 'same_connection' => $sameConnection]);
            expect($recovered['command_exit'])->toBe(0)->and($recovered['jobs'])->toHaveCount(1)->and($recovered['intents'][0]['status'])->toBe('published');
            $delivered = $run($name.'-consume', 'consume', ['message_id' => $name, 'same_connection' => $sameConnection]);
            $repeated = $run($name.'-repeat', 'recover', ['message_id' => $name, 'callback_id' => $callbackId($name), 'same_connection' => $sameConnection]);
            expect($delivered['metrics']['handler_calls'])->toBe(1)->and($delivered['metrics']['deliveries'])->toBe(1)
                ->and($repeated['jobs'])->toBe([])->and($repeated['metrics']['callback_publications'])->toBe(1)
                ->and($repeated['metrics']['handler_calls'])->toBe(1)->and($repeated['callbacks'])->toHaveCount(1);
        }

        // A distinct post-publication-ACK process death cannot republish an acknowledged callback.
        $reset();
        $seed('post-publication-ack-crash');
        $producer = $start('post-publication-ack-crash', 'process', ['hook' => 'reliable_callback.committed']);
        $await('post-publication-ack-crash', 'hook', $producer);
        $incomingAfterAck = (array) $db->table('talkto_messages')->where('message_id', 'post-publication-ack-crash')->sole();
        $intentAfterAck = (array) $db->table('talkto_dispatch_intents')->sole();
        $metricsAfterAck = $db->table('callback_probe_metrics')->pluck('value', 'name')->all();
        expect($incomingAfterAck['overall_status'])->toBe('succeeded')
            ->and($db->table('talkto_attempts')->where('message_id', 'post-publication-ack-crash')->value('status'))->toBe('succeeded')
            ->and($db->table('talkto_messages')->where('parent_message_id', 'post-publication-ack-crash')->count())->toBe(1)
            ->and($intentAfterAck['status'])->toBe('published')->and($intentAfterAck['publication_attempts'])->toBe(1)
            ->and($db->table('jobs')->count())->toBe(1)->and($metricsAfterAck['handler_calls'])->toBe(1)
            ->and($metricsAfterAck['callback_publications'])->toBe(1)->and($metricsAfterAck['deliveries'])->toBe(0)
            ->and($metricsAfterAck['acknowledgements'])->toBe(0);
        $evidence['crash_boundary_observations']['after_publication_ack'] = ['before_kill' => [
            'incoming_status' => $incomingAfterAck['overall_status'], 'callback_rows' => 1,
            'intent_status' => $intentAfterAck['status'], 'intent_publication_attempts' => $intentAfterAck['publication_attempts'],
            'accepted_jobs' => $db->table('jobs')->count(), 'metrics' => $metricsAfterAck]];
        $kill('post-publication-ack-crash', $producer, 'hook');
        $recovered = $run('post-publication-ack-recover', 'recover', ['message_id' => 'post-publication-ack-crash']);
        expect($recovered['exceptions'])->toBe([])->and($recovered['command_exit'])->toBe(0)
            ->and($recovered['jobs'])->toHaveCount(1)->and($recovered['callbacks'])->toHaveCount(1)
            ->and($recovered['intents'])->toHaveCount(1)->and($recovered['intents'][0]['status'])->toBe('published')
            ->and($recovered['metrics']['handler_calls'])->toBe(1)->and($recovered['metrics']['callback_publications'])->toBe(1)
            ->and((array) $db->table('talkto_dispatch_intents')->sole())->toBe($intentAfterAck);
        $delivered = $run('post-publication-ack-consumer', 'consume', ['message_id' => 'post-publication-ack-crash']);
        $repeated = $run('post-publication-ack-repeat', 'recover', ['message_id' => 'post-publication-ack-crash']);
        expect($delivered['exceptions'])->toBe([])->and($delivered['metrics']['deliveries'])->toBe(1)
            ->and($delivered['metrics']['acknowledgements'])->toBe(1)->and($delivered['source_messages'][0]['overall_status'])->toBe('completed')
            ->and($repeated['jobs'])->toBe([])->and($repeated['callbacks'])->toHaveCount(1)
            ->and($repeated['callbacks'][0]['overall_status'])->toBe('completed')->and($repeated['intents'])->toHaveCount(1)
            ->and($repeated['metrics']['handler_calls'])->toBe(1)->and($repeated['metrics']['callback_publications'])->toBe(1)
            ->and((array) $db->table('talkto_messages')->where('message_id', 'post-publication-ack-crash')->sole())->toBe($incomingAfterAck)
            ->and((array) $db->table('talkto_dispatch_intents')->sole())->toBe($intentAfterAck);

        // A reliable callback transport worker dies during HTTP; only outgoing transport is recovered.
        $reset();
        $seed('reliable-http-crash');
        $published = $run('reliable-http-crash', 'process');
        expect($published['exceptions'])->toBe([])->and($published['intents'][0]['status'])->toBe('published')
            ->and($published['jobs'])->toHaveCount(1)->and($published['metrics']['handler_calls'])->toBe(1);
        $originalIncoming = (array) $db->table('talkto_messages')->where('message_id', 'reliable-http-crash')->sole();
        $originalIntent = (array) $db->table('talkto_dispatch_intents')->sole();
        $consumer = $start('reliable-http-crash-interrupted', 'consume', ['message_id' => 'reliable-http-crash', 'pause_http' => true]);
        $await('reliable-http-crash-interrupted', 'http-started', $consumer);
        $callbackDuringHttp = (array) $db->table('talkto_messages')->where('direction', 'outgoing')->sole();
        $reserved = (array) $db->table('jobs')->sole();
        $metricsDuringHttp = $db->table('callback_probe_metrics')->pluck('value', 'name')->all();
        expect($originalIncoming['overall_status'])->toBe('succeeded')
            ->and($callbackDuringHttp['overall_status'])->toBe('sending')->and($callbackDuringHttp['transport_status'])->toBe('sending')
            ->and($reserved['reserved_at'])->not->toBeNull()->and($reserved['attempts'])->toBe(1)
            ->and($metricsDuringHttp['handler_calls'])->toBe(1)->and($metricsDuringHttp['executions'])->toBe(1)
            ->and($metricsDuringHttp['http_calls'])->toBe(1)->and($metricsDuringHttp['deliveries'])->toBe(0)
            ->and($metricsDuringHttp['acknowledgements'])->toBe(0);
        $evidence['crash_boundary_observations']['reliable_http_transport'] = ['before_kill' => [
            'incoming_status' => $originalIncoming['overall_status'], 'callback_rows' => 1,
            'callback_status' => $callbackDuringHttp['overall_status'], 'callback_transport_status' => $callbackDuringHttp['transport_status'],
            'callback_message_id' => $callbackDuringHttp['message_id'], 'callback_payload_hash' => $callbackDuringHttp['payload_hash'],
            'intent_status' => $originalIntent['status'], 'intent_publication_attempts' => $originalIntent['publication_attempts'],
            'reserved_jobs' => 1, 'job_attempts' => $reserved['attempts'], 'metrics' => $metricsDuringHttp]];
        $kill('reliable-http-crash-interrupted', $consumer, 'http-started');
        $initialRecovery = $run('reliable-http-crash-initial-recover', 'recover', ['message_id' => 'reliable-http-crash']);
        expect($initialRecovery['exceptions'])->toBe([])->and($initialRecovery['jobs'])->toHaveCount(1)
            ->and($initialRecovery['callbacks'][0]['overall_status'])->toBe('sending')
            ->and($initialRecovery['metrics']['callback_publications'])->toBe(1)->and($initialRecovery['metrics']['handler_calls'])->toBe(1)
            ->and((array) $db->table('talkto_dispatch_intents')->sole())->toBe($originalIntent);
        $db->table('talkto_messages')->where('direction', 'outgoing')->update(['locked_at' => now()->subHour()]);
        $db->table('jobs')->whereNotNull('reserved_at')->update(['reserved_at' => 0]);
        $transportRecovered = $run('reliable-http-crash-stale', 'stale', ['message_id' => 'reliable-http-crash']);
        expect($transportRecovered['exceptions'])->toBe([])->and($transportRecovered['command_exit'])->toBe(0)
            ->and($transportRecovered['jobs'])->toHaveCount(2)->and($transportRecovered['callbacks'])->toHaveCount(1)
            ->and($transportRecovered['metrics']['callback_publications'])->toBe(2)->and($transportRecovered['metrics']['handler_calls'])->toBe(1)
            ->and((array) $db->table('talkto_messages')->where('message_id', 'reliable-http-crash')->sole())->toBe($originalIncoming)
            ->and((array) $db->table('talkto_dispatch_intents')->sole())->toBe($originalIntent);
        $first = $run('reliable-http-crash-consumer-first', 'consume', ['message_id' => 'reliable-http-crash']);
        $second = $run('reliable-http-crash-consumer-second', 'consume', ['message_id' => 'reliable-http-crash']);
        $repeated = $run('reliable-http-crash-repeat', 'recover', ['message_id' => 'reliable-http-crash']);
        expect($first['exceptions'])->toBe([])->and($second['exceptions'])->toBe([])
            ->and($first['metrics']['deliveries'])->toBe(1)->and($second['metrics']['deliveries'])->toBe(1)
            ->and($second['metrics']['acknowledgements'])->toBe(1)->and($second['metrics']['executions'])->toBe(3)
            ->and($second['metrics']['http_calls'])->toBe(2)->and($second['source_messages'][0]['overall_status'])->toBe('completed')
            ->and($repeated['jobs'])->toBe([])->and($repeated['callbacks'])->toHaveCount(1)
            ->and($repeated['callbacks'][0]['overall_status'])->toBe('completed')
            ->and($repeated['callbacks'][0]['message_id'])->toBe($callbackDuringHttp['message_id'])
            ->and($repeated['callbacks'][0]['payload_hash'])->toBe($callbackDuringHttp['payload_hash'])
            ->and($repeated['metrics']['handler_calls'])->toBe(1)->and($repeated['metrics']['callback_publications'])->toBe(2)
            ->and((array) $db->table('talkto_messages')->where('message_id', 'reliable-http-crash')->sole())->toBe($originalIncoming)
            ->and((array) $db->table('talkto_dispatch_intents')->sole())->toBe($originalIntent);

        // Independent recovery opt-in and legacy manual APIs cannot bypass durable callback evidence.
        $reset();
        $seed('recovery-gates');
        $run('recovery-gates', 'process', ['publish_after_commit' => false]);
        $disabled = $run('recovery-gates-disabled', 'recover', ['message_id' => 'recovery-gates', 'callback_recovery_enabled' => false]);
        expect($disabled['jobs'])->toBe([])->and($disabled['metrics']['handler_calls'])->toBe(1)
            ->and($disabled['intents'][0]['status'])->toBe('ready');
        $manual = $run('recovery-gates-legacy-manual', 'manual-callback', ['message_id' => 'recovery-gates', 'reliable_enabled' => false, 'durable_enabled' => false]);
        expect($manual['exceptions'])->toBe([])->and($manual['jobs'])->toBe([])->and($manual['callbacks'])->toHaveCount(1)
            ->and($manual['metrics']['handler_calls'])->toBe(1)->and($manual['intents'][0]['status'])->toBe('ready');
        $published = $run('recovery-gates-enabled', 'recover', ['message_id' => 'recovery-gates']);
        expect($published['jobs'])->toHaveCount(1)->and($published['intents'][0]['status'])->toBe('published');

        // Two actual incoming queue actors compete; one owns processing and one callback outcome.
        $reset();
        $seed('processor-competition');
        $first = $start('processor-owner', 'process', ['message_id' => 'processor-competition', 'hook' => 'reliable_callback.before_handler']);
        $await('processor-owner', 'hook', $first);
        $second = $run('processor-contender', 'process', ['message_id' => 'processor-competition']);
        expect($second['metrics']['handler_calls'])->toBe(0)->and($second['callbacks'])->toBe([]);
        file_put_contents($directory.'/processor-owner.release', 'release');
        $firstResult = $finish('processor-owner', $first);
        expect($firstResult['connection_id'])->not->toBe($second['connection_id'])->and($firstResult['metrics']['handler_calls'])->toBe(1)
            ->and($firstResult['callbacks'])->toHaveCount(1)->and($firstResult['intents'])->toHaveCount(1)
            ->and($firstResult['metrics']['callback_publications'])->toBe(1);

        // Default sender calls serialize on the incoming row and preserve one immutable callback identity.
        $reset();
        $seed('identity-contention');
        $run('identity-contention', 'process', ['publish_after_commit' => false]);
        $creator = $start('callback-identity-owner', 'manual-callback', ['message_id' => 'identity-contention', 'hook' => 'reliable_callback.callback_persisted']);
        $creatorConnection = $await('callback-identity-owner', 'connected', $creator);
        $await('callback-identity-owner', 'hook', $creator);
        $contender = $start('callback-identity-contender', 'manual-callback', ['message_id' => 'identity-contention']);
        $contenderConnection = $await('callback-identity-contender', 'connected', $contender);
        $evidence['callback_identity_wait'] = reliableCallbackObservedWait($admin, (int) $contenderConnection['connection_id'], (int) $creatorConnection['connection_id'], $contender);
        file_put_contents($directory.'/callback-identity-owner.release', 'release');
        $created = $finish('callback-identity-owner', $creator);
        $contended = $finish('callback-identity-contender', $contender);
        expect($created['exceptions'])->toBe([])->and($contended['exceptions'])->toBe([])
            ->and($contended['callbacks'])->toHaveCount(1)->and($contended['intents'])->toHaveCount(1)
            ->and($contended['jobs'])->toBe([])->and($contended['metrics']['handler_calls'])->toBe(1);

        // Concurrent recoverers and a stale lease owner cannot create a second callback publication.
        $reset();
        $seed('two-recoverers');
        $run('two-recoverers', 'process', ['publish_after_commit' => false]);
        $a = $start('recovery-a', 'recover', ['message_id' => 'two-recoverers']);
        $b = $start('recovery-b', 'recover', ['message_id' => 'two-recoverers']);
        $ar = $finish('recovery-a', $a);
        $br = $finish('recovery-b', $b);
        expect($ar['connection_id'])->not->toBe($br['connection_id'])->and($db->table('jobs')->count())->toBe(1)
            ->and($db->table('talkto_messages')->where('direction', 'outgoing')->count())->toBe(1)
            ->and($db->table('callback_probe_metrics')->where('name', 'handler_calls')->value('value'))->toBe(1);

        $reset();
        $seed('lease-fencing');
        $run('lease-fencing', 'process', ['publish_after_commit' => false]);
        $old = $start('old-publisher', 'recover', ['message_id' => 'lease-fencing', 'hook' => 'dispatch_intent.claimed']);
        $await('old-publisher', 'hook', $old);
        $oldToken = $db->table('talkto_dispatch_intents')->value('claim_token');
        $db->table('talkto_dispatch_intents')->update(['claim_expires_at' => now()->subMinute()]);
        $new = $run('new-publisher', 'recover', ['message_id' => 'lease-fencing']);
        file_put_contents($directory.'/old-publisher.release', 'release');
        $resumed = $finish('old-publisher', $old);
        expect($new['jobs'])->toHaveCount(1)->and($resumed['jobs'])->toHaveCount(1)
            ->and($db->table('talkto_dispatch_intents')->value('claim_token'))->not->toBe($oldToken)
            ->and($resumed['metrics']['callback_publications'])->toBe(1)->and($resumed['metrics']['handler_calls'])->toBe(1);

        // External/separate queue acceptance followed by loss is permanently ambiguous for initial recovery.
        $reset();
        $seed('publication-lost-ack');
        $unknown = $run('publication-lost-ack', 'process', ['same_connection' => false, 'lost_publication_ack' => true]);
        expect($unknown['intents'][0]['status'])->toBe('unknown')->and($unknown['jobs'])->toHaveCount(1)
            ->and($unknown['metrics']['handler_calls'])->toBe(1);
        $repeat = $run('publication-lost-ack-repeat', 'recover', ['message_id' => 'publication-lost-ack', 'same_connection' => false]);
        expect($repeat['jobs'])->toHaveCount(1)->and($repeat['metrics']['callback_publications'])->toBe(1);
        $delivered = $run('publication-lost-ack-consumer', 'consume', ['message_id' => 'publication-lost-ack', 'same_connection' => false]);
        expect($delivered['metrics']['deliveries'])->toBe(1)->and($delivered['metrics']['handler_calls'])->toBe(1);

        $reset();
        $seed('queue-accepted-crash');
        $producer = $start('queue-accepted-crash', 'process', ['same_connection' => false, 'hook' => 'dispatch_intent.after_queue']);
        $kill('queue-accepted-crash', $producer, 'hook');
        expect($db->table('talkto_dispatch_intents')->value('status'))->toBe('publishing')->and($queueDb->table('jobs')->count())->toBe(1);
        $db->table('talkto_dispatch_intents')->update(['claim_expires_at' => now()->subHour()]);
        $repeat = $run('queue-accepted-repeat', 'recover', ['message_id' => 'queue-accepted-crash', 'same_connection' => false]);
        expect($repeat['jobs'])->toHaveCount(1)->and($repeat['intents'][0]['status'])->toBe('publishing');

        // Actual queue outage in the native atomic path is recoverable after a definitive rollback.
        $reset();
        $seed('atomic-queue-outage');
        $outage = $run('atomic-queue-outage', 'process', ['lost_publication_ack' => true]);
        expect($outage['intents'][0]['status'])->toBe('claimed')->and($outage['jobs'])->toBe([])
            ->and($db->table('talkto_messages')->where('message_id', 'atomic-queue-outage')->value('overall_status'))->toBe('succeeded');
        $db->table('talkto_dispatch_intents')->update(['claim_expires_at' => now()->subMinute()]);
        $fixed = $run('atomic-queue-outage-fixed', 'recover', ['message_id' => 'atomic-queue-outage']);
        expect($fixed['jobs'])->toHaveCount(1)->and($fixed['intents'][0]['status'])->toBe('published')->and($fixed['metrics']['handler_calls'])->toBe(1);

        // A real InnoDB cycle inside finalization never retries the business Handler.
        $reset();
        $seed('real-deadlock');
        $deadlocksBefore = (int) $admin->query("SELECT COUNT FROM information_schema.innodb_metrics WHERE NAME='lock_deadlocks'")->fetchColumn();
        $publisher = $start('deadlock-result-writer', 'process', ['message_id' => 'real-deadlock', 'publish_after_commit' => false,
            'hook' => 'reliable_callback.before_commit', 'deadlock_metric_at_hook' => true]);
        $writerConnection = $await('deadlock-result-writer', 'connected', $publisher);
        $await('deadlock-result-writer', 'hook', $publisher);
        $opponent = $start('deadlock-opponent', 'deadlock-opponent', ['message_id' => 'real-deadlock']);
        $opponentConnection = $await('deadlock-opponent', 'metric-locked', $opponent);
        $wait = reliableCallbackObservedWait($admin, (int) $opponentConnection['connection_id'], (int) $writerConnection['connection_id'], $opponent);
        file_put_contents($directory.'/deadlock-result-writer.release', 'release');
        $writerResult = $finish('deadlock-result-writer', $publisher);
        $opponentResult = $finish('deadlock-opponent', $opponent);
        $deadlocksAfter = (int) $admin->query("SELECT COUNT FROM information_schema.innodb_metrics WHERE NAME='lock_deadlocks'")->fetchColumn();
        expect($deadlocksAfter)->toBeGreaterThan($deadlocksBefore)
            ->and($writerResult['metrics']['handler_calls'])->toBe(1)
            ->and($db->table('talkto_messages')->where('direction', 'outgoing')->count())->toBeLessThanOrEqual(1);
        $evidence['deadlock'] = ['lock_wait' => $wait, 'engine_counter_before' => $deadlocksBefore, 'engine_counter_after' => $deadlocksAfter,
            'writer_exceptions' => $writerResult['exceptions'], 'opponent_exceptions' => $opponentResult['exceptions']];
        $repeat = $run('deadlock-repeat-process', 'process', ['message_id' => 'real-deadlock']);
        expect($repeat['metrics']['handler_calls'])->toBe(1);
        if ($db->table('talkto_messages')->where('message_id', 'real-deadlock')->value('overall_status') === 'succeeded') {
            $recovered = $run('deadlock-recover', 'recover', ['message_id' => 'real-deadlock']);
            expect($recovered['jobs'])->toHaveCount(1)->and($recovered['metrics']['handler_calls'])->toBe(1);
        } else {
            $assertProtected('real-deadlock');
        }

        // HTTP delivery acknowledgement loss uses existing transport retries, never the incoming Handler.
        $reset();
        $seed('http-ack-lost');
        $run('http-ack-lost', 'process');
        $lost = $run('http-ack-lost-consumer', 'consume', ['message_id' => 'http-ack-lost', 'lost_http_ack' => true]);
        expect($lost['metrics']['deliveries'])->toBe(1)->and($lost['metrics']['acknowledgements'])->toBe(0)
            ->and($lost['source_messages'][0]['overall_status'])->toBe('completed')
            ->and($db->table('talkto_messages')->where('direction', 'outgoing')->value('overall_status'))->toBe('failed_retryable');
        $retry = $run('http-ack-lost-retry', 'retry', ['message_id' => 'http-ack-lost']);
        expect($retry['jobs'])->toHaveCount(1);
        $ack = $run('http-ack-lost-retry-consumer', 'consume', ['message_id' => 'http-ack-lost']);
        expect($ack['metrics']['handler_calls'])->toBe(1)->and($ack['metrics']['deliveries'])->toBe(2)
            ->and($ack['metrics']['acknowledgements'])->toBe(1)
            ->and($db->table('talkto_messages')->where('direction', 'outgoing')->value('overall_status'))->toBe('completed');

        // An actual delayed transport failure cannot downgrade a callback already completed by another actor.
        $reset();
        $seed('late-http-failure');
        $run('late-http-failure', 'process');
        $consumer = $start('late-http-failure-consumer', 'consume', ['message_id' => 'late-http-failure', 'pause_http' => true, 'http_outage' => true]);
        $await('late-http-failure-consumer', 'http-started', $consumer);
        $db->table('talkto_messages')->where('direction', 'outgoing')->update(['overall_status' => 'completed', 'transport_status' => 'sent', 'completed_at' => now(), 'locked_at' => null, 'locked_by' => null]);
        $terminal = (array) $db->table('talkto_messages')->where('direction', 'outgoing')->sole();
        file_put_contents($directory.'/late-http-failure-consumer.release', 'release');
        $late = $finish('late-http-failure-consumer', $consumer);
        expect((array) $db->table('talkto_messages')->where('direction', 'outgoing')->sole())->toBe($terminal)
            ->and($late['metrics']['handler_calls'])->toBe(1);

        // Payload measurements retain bodies only in ordinary message/attempt/event storage, not the ledger.
        foreach ([1024, 65536, 262144, 1048576, 2516582] as $bytes) {
            $reset();
            $name = 'payload-'.$bytes;
            $seed($name);
            $producer = $run($name, 'process', ['payload_bytes' => $bytes]);
            $consumer = $run($name.'-consumer', 'consume', ['message_id' => $name]);
            expect($producer['exceptions'])->toBe([])->and($consumer['exceptions'])->toBe([])
                ->and($consumer['metrics']['handler_calls'])->toBe(1)->and($consumer['metrics']['deliveries'])->toBe(1)
                ->and($producer['intents'])->toHaveCount(1)->and($producer['intents'][0])->not->toHaveKey('payload');
            $size = $db->selectOne('SELECT OCTET_LENGTH(CAST(payload AS CHAR)) AS callback_bytes FROM talkto_messages WHERE direction = ? LIMIT 1', ['outgoing']);
            $evidence['payload_measurements'][(string) $bytes] = ['producer' => array_intersect_key($producer, array_flip(['elapsed_ms', 'sql_query_count', 'sql_total_ms', 'operation_sql_query_count', 'peak_allocated_bytes', 'statement_rows_examined'])),
                'consumer' => array_intersect_key($consumer, array_flip(['elapsed_ms', 'sql_query_count', 'sql_total_ms', 'operation_sql_query_count', 'peak_allocated_bytes', 'statement_rows_examined'])), 'callback_json_bytes' => (int) $size->callback_bytes];
        }
        $evidence['recovery_explain'] = array_map(fn ($row) => (array) $row, $db->select('EXPLAIN SELECT id,message_id,status,created_at FROM talkto_dispatch_intents WHERE owner = ? AND purpose = ? AND status = ? ORDER BY created_at,id LIMIT 20', ['destination', 'result_callback', 'ready']));
        $evidence['owned_schema'] = array_map(fn ($row) => (array) $row, $db->select('SHOW INDEX FROM talkto_dispatch_intents'));
        $evidence['result'] = 'PASS';
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        }
        foreach (['callback_parent', 'callback_source_parent', 'callback_queue_parent'] as $name) {
            DB::purge($name);
        }
        foreach ([$database, $sourceDatabase, $queueDatabase] as $name) {
            $admin->exec("DROP DATABASE `{$name}`");
        }
        if (is_dir($directory)) {
            foreach (glob($directory.'/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }
});

function reliableCallbackObservedWait(PDO $admin, int $waiting, int $blocking, Process $process): array
{
    $deadline = microtime(true) + 15;
    $query = $admin->prepare('SELECT request.PROCESSLIST_ID AS waiting_connection_id, blocker.PROCESSLIST_ID AS blocking_connection_id FROM performance_schema.data_lock_waits AS waits JOIN performance_schema.threads AS request ON request.THREAD_ID = waits.REQUESTING_THREAD_ID JOIN performance_schema.threads AS blocker ON blocker.THREAD_ID = waits.BLOCKING_THREAD_ID WHERE request.PROCESSLIST_ID = ? AND blocker.PROCESSLIST_ID = ?');
    while (microtime(true) < $deadline) {
        $query->execute([$waiting, $blocking]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return ['waiting_connection_id' => (int) $row['waiting_connection_id'], 'blocking_connection_id' => (int) $row['blocking_connection_id'],
                'source' => 'Actual performance_schema.data_lock_waits thread IDs joined to performance_schema.threads', 'observed_at' => microtime(true)];
        }
        if (! $process->isRunning()) {
            throw new RuntimeException('Callback worker exited before the required actual InnoDB wait.');
        }
        usleep(10000);
    }
    $states = $admin->query('SELECT ID,STATE,COMMAND FROM information_schema.PROCESSLIST WHERE ID IN ('.$waiting.','.$blocking.')')->fetchAll(PDO::FETCH_ASSOC);
    $waits = $admin->query('SELECT REQUESTING_ENGINE_TRANSACTION_ID,BLOCKING_ENGINE_TRANSACTION_ID FROM performance_schema.data_lock_waits')->fetchAll(PDO::FETCH_ASSOC);
    throw new RuntimeException('Expected real callback InnoDB lock wait was not observed: '.json_encode(['expected_waiter' => $waiting, 'expected_blocker' => $blocking, 'states' => $states, 'waits' => $waits], JSON_THROW_ON_ERROR));
}
