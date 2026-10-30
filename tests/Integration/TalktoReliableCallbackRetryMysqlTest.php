<?php

use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

test('reliable callback retries preserve each immutable attempt on concurrent mysql database queues', function (): void {
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
    $path = getenv('TALKTO_RELIABLE_CALLBACK_RETRY_MYSQL_EVIDENCE_PATH');
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
            $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/ReliableCallbackRetryMysqlWorker.php'], dirname(__DIR__, 2));
            $process->setInput(json_encode(array_merge(['worker' => $name, 'operation' => $operation, 'message_id' => $name,
                'custom_jobs' => true, 'connection' => $connection, 'source_connection' => $sourceConnection, 'queue_connection' => $queueConnection, 'directory' => $directory], $extra), JSON_THROW_ON_ERROR));
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
        $incoming = fn (string $id) => (array) $db->table('talkto_messages')->where('message_id', $id)->sole();
        $callbacks = fn (string $id) => $db->table('talkto_messages')->where('parent_message_id', $id)->where('direction', 'outgoing')->orderBy('id')->pluck('id')
            ->map(fn ($key) => (array) $db->table('talkto_messages')->where('id', $key)->sole())->all();
        $binding = fn (array $row): string => hash('sha256', json_encode(array_intersect_key($row, array_flip([
            'message_id', 'parent_message_id', 'correlation_id', 'idempotency_key', 'command', 'source_service', 'target_service', 'business_key', 'payload', 'payload_hash', 'schema_version',
        ])), JSON_THROW_ON_ERROR));
        $next = function (string $id, string $label, array $extra = []) use ($run): array {
            $claimed = $run($label.'-retry-command', 'retry', ['message_id' => $id] + $extra);
            expect($claimed['command_exit'])->toBe(0)->and($claimed['command_output'])->toContain('dispatched=1');
            $processed = $run($label, 'consume', ['message_id' => $id, 'queue_name' => 'callback-custom-in'] + $extra);
            expect($processed['exceptions'])->toBe([])->and($processed['popped'])->toBeTrue()
                ->and($processed['job_class'])->toBe('ReliableCallbackRetryMysqlIncomingJob');

            return $processed;
        };
        $deliver = function (string $id, string $label, string $callbackId, array $extra = []) use ($run, $db, $queueDb): array {
            $owner = ($extra['same_connection'] ?? true) ? $db : $queueDb;
            $callbackDbId = (int) $db->table('talkto_messages')->where('message_id', $callbackId)->value('id');
            $found = false;
            foreach ($owner->table('jobs')->where('queue', 'callback-custom-out')->get() as $job) {
                $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
                $command = (array) unserialize($payload['data']['command'], ['allowed_classes' => false]);
                $selected = ($command['talktoMessageId'] ?? null) === $callbackDbId;
                $owner->table('jobs')->where('id', $job->id)->update(['available_at' => $selected ? 0 : time() + 3600]);
                $found = $found || $selected;
            }
            expect($found)->toBeTrue('Expected accepted queue envelope for '.$callbackId);
            $delivered = $run($label, 'consume', ['message_id' => $id, 'queue_name' => 'callback-custom-out'] + $extra);
            expect($delivered['exceptions'])->toBe([])->and($delivered['popped'])->toBeTrue()
                ->and($delivered['job_class'])->toBe('ReliableCallbackRetryMysqlSendJob');

            return $delivered;
        };
        $assertRetry = function (string $id, int $attemptNo, int $retryCount) use ($incoming, $db): void {
            $row = $incoming($id);
            expect($row['overall_status'])->toBe('failed_retryable')->and($row['destination_action_status'])->toBe('failed')
                ->and((int) $row['attempts'])->toBe($attemptNo)->and((int) $row['retry_count'])->toBe($retryCount)
                ->and($row['next_retry_at'])->not->toBeNull()->and($row['next_attempt_at'])->toBe($row['next_retry_at'])
                ->and($row['locked_by'])->toBeNull()->and($row['locked_at'])->toBeNull()
                ->and($db->table('talkto_attempts')->where('message_id', $id)->where('stage', 'destination_processor')->orderBy('attempt_no')->pluck('attempt_no')->map(fn ($n) => (int) $n)->all())->toBe(range(1, $attemptNo));
        };
        $assertProtected = function (string $id, int $existingCallbacks) use ($incoming, $callbacks, $db): void {
            $row = $incoming($id);
            expect($row['overall_status'])->toBe('processing')->and($row['destination_action_status'])->toBe('processing')
                ->and($row['locked_by'])->toStartWith('reliable-callback:')->and($callbacks($id))->toHaveCount($existingCallbacks)
                ->and($db->table('talkto_dispatch_intents')->count())->toBe($existingCallbacks);
        };

        // Each real retry command creates an incoming job while earlier callbacks remain independently queued.
        $outcomes = [
            'different-errors' => ['result_type' => 'failed_retryable', 'result_error' => 'Network timeout B'],
            'different-metadata' => ['result_type' => 'failed_retryable', 'result_error' => 'Network timeout A', 'result_meta' => ['revision' => 'B', 'nested' => ['unicode' => 'پاسخ B', 'empty' => []]]],
            'identical-results' => ['result_type' => 'failed_retryable', 'result_error' => 'Network timeout A'],
            'later-success' => ['result_type' => 'succeeded'],
            'later-final' => ['result_type' => 'failed_final', 'result_error' => 'Permanent failure B'],
            'later-skipped' => ['result_type' => 'skipped'],
            'later-exception' => ['result_type' => 'unexpected'],
        ];
        foreach ($outcomes as $id => $secondOptions) {
            $reset();
            $seed($id);
            $first = $run($id.'-first', 'process', ['message_id' => $id, 'result_type' => 'failed_retryable', 'result_error' => 'Network timeout A']);
            expect($first['exceptions'])->toBe([])->and($first['callbacks'])->toHaveCount(1);
            $assertRetry($id, 1, 1);
            $old = $callbacks($id)[0];
            expect($old['message_id'])->toBe('cb-'.sha1($id.'|failed_retryable'))
                ->and($old['idempotency_key'])->toBe('talkto:callback:'.$id.':failed_retryable');
            $second = $next($id, $id.'-second', $secondOptions);
            $stored = $callbacks($id);
            expect($stored)->toHaveCount(2)->and($second['intents'])->toHaveCount(2)
                ->and($stored[1]['message_id'])->toStartWith('cb-a-')->not->toBe($old['message_id'])
                ->and($stored[1]['idempotency_key'])->toStartWith('talkto:callback:attempt:')
                ->and($binding($stored[0]))->toBe($binding($old))->and($second['metrics']['handler_calls'])->toBe(2);
            if ($secondOptions['result_type'] === 'failed_retryable' || $secondOptions['result_type'] === 'unexpected') {
                $assertRetry($id, 2, 2);
            } else {
                $row = $incoming($id);
                expect($row['overall_status'])->toBe(match ($secondOptions['result_type']) {
                    'succeeded' => 'succeeded', 'skipped' => 'skipped', default => 'failed_final',
                })->and($row['locked_by'])->toBeNull()->and((int) $row['attempts'])->toBe(2);
            }
            if ($id === 'identical-results') {
                expect($stored[1]['payload_hash'])->toBe($old['payload_hash']);
            }
            $firstDelivery = $deliver($id, $id.'-deliver-a', $stored[0]['message_id']);
            $secondDelivery = $deliver($id, $id.'-deliver-b', $stored[1]['message_id']);
            expect($secondDelivery['metrics']['handler_calls'])->toBe(2)->and($secondDelivery['metrics']['deliveries'])->toBe(2)
                ->and($secondDelivery['metrics']['acknowledgements'])->toBe(2)->and($secondDelivery['metrics']['callback_publications'])->toBe(2);
            if ($secondOptions['result_type'] === 'failed_retryable' || $secondOptions['result_type'] === 'unexpected') {
                expect($firstDelivery['receipts'][0]['receiver_response']['status'])->toBe('applied')
                    ->and($secondDelivery['receipts'][0]['receiver_response']['status'])->toBe('duplicate')
                    ->and($secondDelivery['source_messages'][0]['last_error'])->toBe('Network timeout A');
            }
            $again = $run($id.'-redeliver-a', 'signed-redelivery', ['message_id' => $id, 'callback_id' => $stored[0]['message_id']]);
            expect($again['exceptions'])->toBe([])->and($again['redelivery_http_status'])->toBe(200)
                ->and($again['source_messages'])->toBe($secondDelivery['source_messages'])
                ->and($again['metrics']['handler_calls'])->toBe(2)->and($binding($callbacks($id)[0]))->toBe($binding($old));
        }

        // Already delivered callback history remains immutable across both reliable and legacy-to-reliable retries.
        foreach ([true, false] as $initialReliable) {
            $reset();
            $id = $initialReliable ? 'previous-delivered' : 'legacy-previous-delivered';
            $seed($id);
            $oldMode = ['reliable_enabled' => $initialReliable, 'durable_enabled' => $initialReliable];
            $run($id.'-first', 'process', ['message_id' => $id, 'result_type' => 'failed_retryable', 'result_error' => 'Delivered A'] + $oldMode);
            $old = $callbacks($id)[0];
            $deliver($id, $id.'-deliver-a', $old['message_id'], $oldMode);
            $completedOld = $callbacks($id)[0];
            expect($completedOld['overall_status'])->toBe('completed');
            $second = $next($id, $id.'-second', ['result_type' => 'failed_retryable', 'result_error' => 'Delivered B']);
            expect($callbacks($id)[0])->toBe($completedOld)->and($second['callbacks'])->toHaveCount(2)
                ->and($second['intents'])->toHaveCount($initialReliable ? 2 : 1);
            $after = $deliver($id, $id.'-deliver-b', $callbacks($id)[1]['message_id']);
            expect($after['receipts'][0]['receiver_response']['status'])->toBe('duplicate')
                ->and($after['source_messages'][0]['last_error'])->toBe('Delivered A')
                ->and($after['metrics']['handler_calls'])->toBe(2)->and($callbacks($id)[0])->toBe($completedOld);
            if (! $initialReliable) {
                expect($db->table('talkto_dispatch_intents')->where('message_id', $old['message_id'])->count())->toBe(0);
            }
        }

        // A later retry can finish while the previous signed callback is in flight.
        $reset();
        $seed('concurrent-delivery');
        $first = $run('concurrent-delivery-first', 'process', ['message_id' => 'concurrent-delivery', 'result_type' => 'failed_retryable', 'result_error' => 'Timeout A']);
        $old = $callbacks('concurrent-delivery')[0];
        $consumer = $start('concurrent-delivery-http', 'consume', ['message_id' => 'concurrent-delivery', 'queue_name' => 'callback-custom-out', 'pause_http' => true]);
        $await('concurrent-delivery-http', 'http-started', $consumer);
        $second = $next('concurrent-delivery', 'concurrent-delivery-second', ['result_type' => 'failed_retryable', 'result_error' => 'Timeout B']);
        expect($second['callbacks'])->toHaveCount(2)->and($second['metrics']['handler_calls'])->toBe(2);
        file_put_contents($directory.'/concurrent-delivery-http.release', 'release');
        $accepted = $finish('concurrent-delivery-http', $consumer);
        expect($accepted['metrics']['deliveries'])->toBe(1)->and($binding($callbacks('concurrent-delivery')[0]))->toBe($binding($old));
        $third = $next('concurrent-delivery', 'concurrent-delivery-third');
        expect($third['callbacks'])->toHaveCount(3)->and($third['metrics']['handler_calls'])->toBe(3);
        $all = $callbacks('concurrent-delivery');
        $completed = $deliver('concurrent-delivery', 'concurrent-delivery-success-first', $all[2]['message_id']);
        $delayed = $deliver('concurrent-delivery', 'concurrent-delivery-old-failure', $all[1]['message_id']);
        expect($completed['source_messages'][0]['overall_status'])->toBe('completed')
            ->and($delayed['source_messages'])->toBe($completed['source_messages'])
            ->and($delayed['receipts'][0]['receiver_response']['status'])->toBe('stale_ignored');

        // Identical wall-clock timestamps and identical results still identify different committed attempts.
        $reset();
        $seed('same-timestamp');
        $frozen = ['freeze_time' => '2026-10-30T00:00:00Z', 'result_type' => 'failed_retryable', 'result_error' => 'Same immutable error'];
        $run('same-timestamp-first', 'process', ['message_id' => 'same-timestamp'] + $frozen);
        $next('same-timestamp', 'same-timestamp-second', $frozen);
        $rows = $callbacks('same-timestamp');
        expect($rows)->toHaveCount(2)->and($rows[0]['payload_hash'])->toBe($rows[1]['payload_hash'])
            ->and($rows[0]['message_id'])->not->toBe($rows[1]['message_id'])
            ->and($db->table('talkto_attempts')->where('stage', 'destination_processor')->pluck('created_at')->unique()->count())->toBe(1);

        // Existing exhaustion/Dead Letter semantics remain distinct from the Handler's reported retryability.
        $reset();
        $seed('retry-exhaustion', 3);
        $run('retry-exhaustion-first', 'process', ['message_id' => 'retry-exhaustion', 'result_type' => 'failed_retryable', 'result_error' => 'A']);
        $next('retry-exhaustion', 'retry-exhaustion-second', ['result_type' => 'failed_retryable', 'result_error' => 'B']);
        $exhausted = $next('retry-exhaustion', 'retry-exhaustion-third', ['result_type' => 'failed_retryable', 'result_error' => 'C']);
        expect($incoming('retry-exhaustion')['overall_status'])->toBe('failed_final')
            ->and((int) $incoming('retry-exhaustion')['retry_count'])->toBe(2)
            ->and($incoming('retry-exhaustion')['next_retry_at'])->toBeNull()
            ->and($db->table('talkto_dead_letters')->count())->toBe(1)
            ->and($exhausted['callbacks'])->toHaveCount(3)->and($exhausted['intents'])->toHaveCount(3)
            ->and($exhausted['metrics']['handler_calls'])->toBe(3);
        $notRetried = $run('retry-exhaustion-repeat', 'retry', ['message_id' => 'retry-exhaustion']);
        expect($notRetried['command_output'])->toContain('dispatched=0')->and($notRetried['metrics']['handler_calls'])->toBe(3);

        // Two processors cannot finalize competing results for the same owned attempt.
        $reset();
        $seed('two-finalizers');
        $writer = $start('finalizer-owner', 'process', ['message_id' => 'two-finalizers', 'result_type' => 'failed_retryable', 'result_error' => 'Owner A', 'hook' => 'reliable_callback.after_handler']);
        $await('finalizer-owner', 'hook', $writer);
        $contender = $run('finalizer-contender', 'process', ['message_id' => 'two-finalizers', 'result_type' => 'failed_retryable', 'result_error' => 'Contender B']);
        expect($contender['metrics']['handler_calls'])->toBe(1)->and($contender['callbacks'])->toBe([]);
        file_put_contents($directory.'/finalizer-owner.release', 'release');
        $finished = $finish('finalizer-owner', $writer);
        expect($finished['exceptions'])->toBe([])->and($finished['metrics']['handler_calls'])->toBe(1)
            ->and($finished['callbacks'])->toHaveCount(1)->and($finished['callbacks'][0]['error_message'])->toBe('Owner A');

        // Same logical attempt manual replay serializes on the incoming row and preserves a single callback/intent.
        $reset();
        $seed('manual-contention');
        $run('manual-contention-first', 'process', ['message_id' => 'manual-contention', 'result_type' => 'failed_retryable']);
        $next('manual-contention', 'manual-contention-second', ['publish_after_commit' => false]);
        $old = $callbacks('manual-contention')[1];
        $creator = $start('manual-owner', 'manual-callback', ['message_id' => 'manual-contention', 'publish_after_commit' => false, 'hook' => 'reliable_callback.callback_persisted']);
        $creatorConnection = $await('manual-owner', 'connected', $creator);
        $await('manual-owner', 'hook', $creator);
        $contender = $start('manual-contender', 'manual-callback', ['message_id' => 'manual-contention', 'publish_after_commit' => false]);
        $contenderConnection = $await('manual-contender', 'connected', $contender);
        $evidence['same_attempt_identity_wait'] = reliableCallbackRetryObservedWait($admin, (int) $contenderConnection['connection_id'], (int) $creatorConnection['connection_id'], $contender);
        file_put_contents($directory.'/manual-owner.release', 'release');
        $created = $finish('manual-owner', $creator);
        $contended = $finish('manual-contender', $contender);
        expect($created['exceptions'])->toBe([])->and($contended['exceptions'])->toBe([])
            ->and($contended['callbacks'])->toHaveCount(2)->and($contended['intents'])->toHaveCount(2)
            ->and($contended['manual_summary']['message_id'])->toBe($old['message_id'])
            ->and($binding($callbacks('manual-contention')[1]))->toBe($binding($old));
        $conflict = $run('same-attempt-conflict', 'manual-callback', ['message_id' => 'manual-contention', 'result_meta' => ['changed' => true], 'publish_after_commit' => false]);
        expect($conflict['exceptions'])->toHaveCount(1)->and($conflict['callbacks'])->toHaveCount(2)
            ->and($binding($callbacks('manual-contention')[1]))->toBe($binding($old));

        // Independent publication/recovery processes race on two distinct retry callback intents.
        $reset();
        $seed('two-attempt-recovery');
        $run('two-attempt-recovery-first', 'process', ['message_id' => 'two-attempt-recovery', 'result_type' => 'failed_retryable', 'publish_after_commit' => false]);
        $next('two-attempt-recovery', 'two-attempt-recovery-second', ['result_type' => 'failed_retryable', 'result_error' => 'Different B', 'publish_after_commit' => false]);
        $a = $start('retry-recover-a', 'recover', ['message_id' => 'two-attempt-recovery']);
        $b = $start('retry-recover-b', 'recover', ['message_id' => 'two-attempt-recovery']);
        $ar = $finish('retry-recover-a', $a);
        $br = $finish('retry-recover-b', $b);
        expect($ar['connection_id'])->not->toBe($br['connection_id'])->and($db->table('jobs')->count())->toBe(2)
            ->and($db->table('callback_probe_metrics')->where('name', 'callback_publications')->value('value'))->toBe(2)
            ->and($db->table('callback_probe_metrics')->where('name', 'handler_calls')->value('value'))->toBe(2)
            ->and($db->table('talkto_dispatch_intents')->where('status', 'published')->count())->toBe(2);

        // A previous ambiguous queue acceptance is immutable and does not prevent the next incoming attempt.
        $reset();
        $seed('previous-unknown');
        $unknown = $run('previous-unknown-first', 'process', ['message_id' => 'previous-unknown', 'same_connection' => false, 'result_type' => 'failed_retryable', 'result_error' => 'Unknown A', 'lost_publication_ack' => true]);
        expect($unknown['intents'][0]['status'])->toBe('unknown');
        $old = $callbacks('previous-unknown')[0];
        $oldIntent = (array) $db->table('talkto_dispatch_intents')->where('message_id', $old['message_id'])->sole();
        $second = $next('previous-unknown', 'previous-unknown-second', ['same_connection' => false, 'result_type' => 'failed_retryable', 'result_error' => 'New B']);
        expect($second['callbacks'])->toHaveCount(2)->and($second['intents'])->toHaveCount(2)
            ->and((array) $db->table('talkto_dispatch_intents')->where('message_id', $old['message_id'])->sole())->toBe($oldIntent)
            ->and($binding($callbacks('previous-unknown')[0]))->toBe($binding($old));
        $recovered = $run('previous-unknown-recovery', 'recover', ['message_id' => 'previous-unknown', 'same_connection' => false]);
        expect($recovered['metrics']['callback_publications'])->toBe(2)->and($recovered['jobs'])->toHaveCount(2)
            ->and((array) $db->table('talkto_dispatch_intents')->where('message_id', $old['message_id'])->sole())->toBe($oldIntent);

        // Crashes during a later result transaction never corrupt the earlier committed callback.
        foreach (['reliable_callback.result_persisted', 'reliable_callback.before_commit'] as $index => $hook) {
            $reset();
            $id = 'second-rollback-'.$index;
            $seed($id);
            $run($id.'-first', 'process', ['message_id' => $id, 'result_type' => 'failed_retryable', 'result_error' => 'Committed A', 'publish_after_commit' => false]);
            $old = $callbacks($id)[0];
            $run($id.'-retry', 'retry', ['message_id' => $id]);
            $writer = $start($id.'-crash', 'consume', ['message_id' => $id, 'queue_name' => 'callback-custom-in', 'result_type' => 'failed_retryable', 'result_error' => 'Uncommitted B', 'publish_after_commit' => false, 'hook' => $hook]);
            $kill($id.'-crash', $writer, 'hook');
            $assertProtected($id, 1);
            expect($binding($callbacks($id)[0]))->toBe($binding($old));
            $recover = $run($id.'-recover', 'recover', ['message_id' => $id]);
            expect($recover['metrics']['handler_calls'])->toBe(2)->and($recover['callbacks'])->toHaveCount(1)
                ->and($recover['intents'])->toHaveCount(1)->and($recover['metrics']['callback_publications'])->toBe(1);
            $repeat = $run($id.'-repeat-handler', 'process', ['message_id' => $id]);
            expect($repeat['metrics']['handler_calls'])->toBe(2);
        }

        // A later committed result survives producer death and recovers its existing callback without another Handler.
        $reset();
        $seed('second-committed-crash');
        $run('second-committed-first', 'process', ['message_id' => 'second-committed-crash', 'result_type' => 'failed_retryable', 'publish_after_commit' => false]);
        $run('second-committed-retry', 'retry', ['message_id' => 'second-committed-crash']);
        $writer = $start('second-committed-kill', 'consume', ['message_id' => 'second-committed-crash', 'queue_name' => 'callback-custom-in', 'publish_after_commit' => false, 'hook' => 'reliable_callback.committed']);
        $kill('second-committed-kill', $writer, 'hook');
        expect($incoming('second-committed-crash')['overall_status'])->toBe('succeeded')->and($callbacks('second-committed-crash'))->toHaveCount(2);
        $recover = $run('second-committed-recover', 'recover', ['message_id' => 'second-committed-crash']);
        expect($recover['metrics']['handler_calls'])->toBe(2)->and($recover['metrics']['callback_publications'])->toBe(2)
            ->and($recover['intents'])->toHaveCount(2);
        $all = $callbacks('second-committed-crash');
        $delivered = $deliver('second-committed-crash', 'second-committed-delivery', $all[1]['message_id']);
        expect($delivered['source_messages'][0]['overall_status'])->toBe('completed')->and($delivered['metrics']['handler_calls'])->toBe(2);

        // A stale result owner cannot finalize after another protected owner replaces its token.
        $reset();
        $seed('stale-finalizer');
        $writer = $start('stale-result-owner', 'process', ['message_id' => 'stale-finalizer', 'hook' => 'reliable_callback.after_handler']);
        $await('stale-result-owner', 'hook', $writer);
        $newToken = 'reliable-callback:'.bin2hex(random_bytes(16));
        $db->table('talkto_messages')->where('message_id', 'stale-finalizer')->update(['locked_by' => $newToken]);
        file_put_contents($directory.'/stale-result-owner.release', 'release');
        $stale = $finish('stale-result-owner', $writer);
        expect($stale['exceptions'])->toHaveCount(1)->and($stale['metrics']['handler_calls'])->toBe(1)
            ->and($stale['callbacks'])->toBe([])->and($stale['intents'])->toBe([])
            ->and($incoming('stale-finalizer')['locked_by'])->toBe($newToken);

        // The later attempt's callback identity is unaffected by an actual InnoDB deadlock cycle.
        $reset();
        $seed('second-deadlock');
        $run('second-deadlock-first', 'process', ['message_id' => 'second-deadlock', 'result_type' => 'failed_retryable', 'publish_after_commit' => false]);
        $run('second-deadlock-retry', 'retry', ['message_id' => 'second-deadlock']);
        $old = $callbacks('second-deadlock')[0];
        $deadlocksBefore = (int) $admin->query("SELECT COUNT FROM information_schema.innodb_metrics WHERE NAME='lock_deadlocks'")->fetchColumn();
        $writer = $start('second-deadlock-writer', 'consume', ['message_id' => 'second-deadlock', 'queue_name' => 'callback-custom-in', 'publish_after_commit' => false, 'hook' => 'reliable_callback.before_commit', 'deadlock_metric_at_hook' => true]);
        $writerConnection = $await('second-deadlock-writer', 'connected', $writer);
        $await('second-deadlock-writer', 'hook', $writer);
        $opponent = $start('second-deadlock-opponent', 'deadlock-opponent', ['message_id' => 'second-deadlock']);
        $opponentConnection = $await('second-deadlock-opponent', 'metric-locked', $opponent);
        $wait = reliableCallbackRetryObservedWait($admin, (int) $opponentConnection['connection_id'], (int) $writerConnection['connection_id'], $opponent);
        file_put_contents($directory.'/second-deadlock-writer.release', 'release');
        $writerResult = $finish('second-deadlock-writer', $writer);
        $opponentResult = $finish('second-deadlock-opponent', $opponent);
        $deadlocksAfter = (int) $admin->query("SELECT COUNT FROM information_schema.innodb_metrics WHERE NAME='lock_deadlocks'")->fetchColumn();
        expect($deadlocksAfter)->toBeGreaterThan($deadlocksBefore)->and($writerResult['metrics']['handler_calls'])->toBe(2)
            ->and($binding($callbacks('second-deadlock')[0]))->toBe($binding($old))
            ->and(count($callbacks('second-deadlock')))->toBeLessThanOrEqual(2);
        $evidence['later_attempt_deadlock'] = ['actual_wait' => $wait, 'engine_before' => $deadlocksBefore, 'engine_after' => $deadlocksAfter, 'writer_exceptions' => $writerResult['exceptions'], 'opponent_exceptions' => $opponentResult['exceptions']];
        $repeat = $run('second-deadlock-repeat', 'process', ['message_id' => 'second-deadlock']);
        expect($repeat['metrics']['handler_calls'])->toBe(2);

        // Transport retry and duplicate jobs operate on the same immutable later-attempt callback.
        $reset();
        $seed('second-transport-retry');
        $run('second-transport-first', 'process', ['message_id' => 'second-transport-retry', 'result_type' => 'failed_retryable']);
        $next('second-transport-retry', 'second-transport-second');
        $old = $callbacks('second-transport-retry')[1];
        $lost = $deliver('second-transport-retry', 'second-transport-lost-ack', $old['message_id'], ['lost_http_ack' => true]);
        expect($lost['source_messages'][0]['overall_status'])->toBe('completed')->and($lost['metrics']['acknowledgements'])->toBe(0);
        $retried = $run('second-transport-retry-command', 'retry', ['message_id' => 'second-transport-retry', 'retry_direction' => 'outgoing']);
        expect($retried['command_output'])->toContain('dispatched=1');
        $duplicateEnvelope = (array) $db->table('jobs')->where('queue', 'callback-custom-out')->orderByDesc('id')->first();
        $delivered = $deliver('second-transport-retry', 'second-transport-delivered', $old['message_id']);
        expect($delivered['metrics']['handler_calls'])->toBe(2)->and($delivered['metrics']['deliveries'])->toBe(2)
            ->and($delivered['metrics']['acknowledgements'])->toBe(1)->and($binding($callbacks('second-transport-retry')[1]))->toBe($binding($old));
        $terminalCallback = $callbacks('second-transport-retry')[1];
        unset($duplicateEnvelope['id']);
        $db->table('jobs')->insert(array_merge($duplicateEnvelope, ['attempts' => 0, 'reserved_at' => null, 'available_at' => 0]));
        $duplicate = $run('second-transport-duplicate-job', 'consume', ['message_id' => 'second-transport-retry', 'queue_name' => 'callback-custom-out']);
        expect($duplicate['exceptions'])->toBe([])->and($duplicate['popped'])->toBeTrue()
            ->and($duplicate['metrics']['executions'])->toBe($delivered['metrics']['executions'] + 1)
            ->and($duplicate['metrics']['deliveries'])->toBe(2)->and($duplicate['metrics']['handler_calls'])->toBe(2)
            ->and($callbacks('second-transport-retry')[1])->toBe($terminalCallback);

        // Disabling reliable/durable flags cannot recreate a later attempt's committed callback under a legacy identity.
        foreach (['published', 'unknown', 'delivered', 'without-intents', 'payload-mismatch', 'binding-mismatch', 'missing-callback'] as $state) {
            $reset();
            $id = 'disabled-later-'.$state;
            $seed($id);
            $sameConnection = $state !== 'unknown';
            $base = ['message_id' => $id, 'same_connection' => $sameConnection];
            $run($id.'-first', 'process', $base + ['result_type' => 'failed_retryable', 'result_error' => 'Earlier immutable failure']);
            $claimed = $run($id.'-retry-command', 'retry', $base);
            expect($claimed['command_exit'])->toBe(0)->and($claimed['command_output'])->toContain('dispatched=1');
            $second = $run($id.'-second', 'consume', $base + ['queue_name' => 'callback-custom-in', 'lost_publication_ack' => $state === 'unknown']);
            expect($second['exceptions'])->toBe([])->and($second['callbacks'])->toHaveCount(2)
                ->and($second['callbacks'][1]['message_id'])->toStartWith('cb-a-')
                ->and($second['intents'][1]['status'])->toBe($state === 'unknown' ? 'unknown' : 'published');
            $later = $callbacks($id)[1];
            if ($state === 'delivered') {
                $delivered = $deliver($id, $id.'-already-delivered', $later['message_id'], ['same_connection' => $sameConnection]);
                expect($delivered['source_messages'][0]['overall_status'])->toBe('completed')->and($delivered['metrics']['deliveries'])->toBe(1);
            }
            if ($state === 'without-intents') {
                $db->getSchemaBuilder()->drop('talkto_dispatch_intents');
            }
            if ($state === 'binding-mismatch') {
                $db->table('talkto_messages')->where('id', $later['id'])->update(['target_service' => 'wrong-fixture-peer']);
            }
            if ($state === 'missing-callback') {
                $db->table('talkto_messages')->where('id', $later['id'])->delete();
            }
            $callbackRowsBefore = $callbacks($id);
            $intentRowsBefore = $state === 'without-intents' ? [] : $db->table('talkto_dispatch_intents')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $incomingBefore = $incoming($id);
            $queueOwner = $sameConnection ? $db : $queueDb;
            $jobsBefore = $queueOwner->table('jobs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $eventsBefore = $db->table('talkto_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $off = ['reliable_enabled' => false, 'durable_enabled' => false, 'same_connection' => $sameConnection];
            $manual = $run($id.'-manual-off', 'manual-callback', ['message_id' => $id] + $off + ($state === 'payload-mismatch' ? ['result_meta' => ['different' => true]] : []));
            expect($manual['exceptions'])->toBe([])->and($manual['manual_summary']['queued'])->toBeFalse()
                ->and($manual['operation_intent_sql_count'])->toBe(0)->and($manual['metrics']['handler_calls'])->toBe(2)
                ->and($manual['metrics']['callback_publications'])->toBe(2)->and($callbacks($id))->toBe($callbackRowsBefore)
                ->and($incoming($id))->toBe($incomingBefore)
                ->and($queueOwner->table('jobs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($jobsBefore);
            if ($state !== 'without-intents') {
                expect($db->table('talkto_dispatch_intents')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($intentRowsBefore);
            }
            $invalid = in_array($state, ['payload-mismatch', 'binding-mismatch', 'missing-callback'], true);
            if ($invalid) {
                expect($manual['manual_summary']['status'])->toBe('failed')->and($manual['manual_summary']['error'])->toBe('queue_failed');
            } else {
                expect($manual['manual_summary']['duplicate'])->toBeTrue()->and($manual['manual_summary']['message_id'])->toBe($later['message_id'])
                    ->and($db->table('talkto_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($eventsBefore);
                if ($state !== 'delivered') {
                    $accepted = $deliver($id, $id.'-existing-job-delivery', $later['message_id'], $off);
                    expect($accepted['source_messages'][0]['overall_status'])->toBe('completed')->and($accepted['metrics']['handler_calls'])->toBe(2)
                        ->and($accepted['metrics']['deliveries'])->toBe(1)->and($accepted['metrics']['callback_publications'])->toBe(2);
                    if ($state === 'unknown') {
                        expect($db->table('talkto_dispatch_intents')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($intentRowsBefore);
                    }
                }
            }
        }
        // Five independent retry fixtures measure actual callback size, queue envelopes, SQL and allocated memory.
        foreach ([1024, 65536, 262144, 1048576, 2516582] as $bytes) {
            $reset();
            $id = 'retry-payload-'.$bytes;
            $seed($id);
            $run($id.'-first', 'process', ['message_id' => $id, 'result_type' => 'failed_retryable']);
            $producer = $next($id, $id.'-second', ['payload_bytes' => $bytes]);
            $last = $callbacks($id)[1];
            $consumer = $deliver($id, $id.'-consumer', $last['message_id'], ['payload_bytes' => $bytes]);
            expect($consumer['metrics']['handler_calls'])->toBe(2)->and($consumer['source_messages'][0]['overall_status'])->toBe('completed')
                ->and($producer['callbacks'])->toHaveCount(2)->and($producer['intents'])->toHaveCount(2);
            $evidence['payload_measurements'][(string) $bytes] = ['producer' => array_intersect_key($producer, array_flip(['elapsed_ms', 'sql_query_count', 'sql_total_ms', 'operation_sql_query_count', 'peak_allocated_bytes', 'statement_rows_examined'])),
                'consumer' => array_intersect_key($consumer, array_flip(['elapsed_ms', 'sql_query_count', 'sql_total_ms', 'operation_sql_query_count', 'peak_allocated_bytes', 'statement_rows_examined'])),
                'callback_json_bytes' => strlen($last['payload']), 'callback_message_id' => $last['message_id'], 'callback_payload_hash' => $last['payload_hash'],
                'accepted_queue_payload_bytes' => array_column($producer['jobs'], 'payload_bytes')];
        }
        $evidence['recovery_explain'] = array_map(fn ($row) => (array) $row, $db->select('EXPLAIN SELECT id,message_id,status,created_at FROM talkto_dispatch_intents WHERE owner = ? AND purpose = ? AND status = ? ORDER BY created_at,id LIMIT 20', ['destination', 'result_callback', 'ready']));
        $evidence['intent_indexes'] = array_map(fn ($row) => (array) $row, $db->select('SHOW INDEX FROM talkto_dispatch_intents'));
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

function reliableCallbackRetryObservedWait(PDO $admin, int $waiting, int $blocking, Process $process): array
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
            throw new RuntimeException('Retry fixture worker exited before the required actual InnoDB wait.');
        }
        usleep(10000);
    }
    throw new RuntimeException('Expected real retry fixture InnoDB lock wait was not observed.');
}
