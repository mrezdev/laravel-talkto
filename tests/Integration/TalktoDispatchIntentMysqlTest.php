<?php

use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

test('durable intents recover committed never attempted jobs with real mysql queues and fenced publishers', function (): void {
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
    $database = 'talkto_intent_'.$suffix;
    $queueDatabase = 'talkto_intent_queue_'.$suffix;
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->exec("CREATE DATABASE `{$queueDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $directory = sys_get_temp_dir().'/talkto-intent-barriers-'.bin2hex(random_bytes(12));
    $processes = [];
    $path = getenv('TALKTO_DISPATCH_INTENT_MYSQL_EVIDENCE_PATH');
    $evidence = ['mysql_version' => $version, 'php_version' => PHP_VERSION, 'laravel_version' => app()->version(),
        'queue_backend' => 'Actual Laravel DatabaseQueue; no Queue/Bus fake, controlled HTTP only.', 'cases' => []];
    try {
        mkdir($directory, 0700);
        $connection = ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $database, 'username' => $user, 'password' => $password,
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00'];
        $queueConnection = array_merge($connection, ['database' => $queueDatabase]);
        config(['database.connections.intent_parent' => $connection, 'database.connections.intent_queue_parent' => $queueConnection,
            'talkto.database.connection' => 'intent_parent', 'talkto.dispatch_intents.enabled' => true]);
        $migrations = glob(__DIR__.'/../../database/migrations/*.php');
        sort($migrations);
        foreach ($migrations as $migration) {
            (require $migration)->up();
        }
        foreach (glob(__DIR__.'/../../database/dispatch-intents/*.php') as $migration) {
            (require $migration)->up();
        }
        $db = DB::connection('intent_parent');
        $queueDb = DB::connection('intent_queue_parent');
        foreach ([$db, $queueDb] as $owner) {
            $owner->statement('CREATE TABLE jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload LONGTEXT NOT NULL, attempts TINYINT UNSIGNED NOT NULL, reserved_at INT UNSIGNED NULL, available_at INT UNSIGNED NOT NULL, created_at INT UNSIGNED NOT NULL, INDEX jobs_queue_index(queue)) ENGINE=InnoDB');
        }
        $db->statement('CREATE TABLE intent_probe_metrics (name VARCHAR(50) PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');
        foreach (['publications', 'executions', 'handler_calls', 'http_calls'] as $metric) {
            $db->table('intent_probe_metrics')->insert(['name' => $metric, 'value' => 0]);
        }
        $reset = function () use ($db, $queueDb): void {
            foreach (['talkto_dispatch_intents', 'talkto_events', 'talkto_attempts', 'talkto_dead_letters', 'talkto_nonces', 'talkto_messages', 'jobs'] as $table) {
                $db->table($table)->delete();
            }
            $queueDb->table('jobs')->delete();
            $db->table('intent_probe_metrics')->update(['value' => 0]);
        };
        $start = function (string $name, string $operation, array $extra = []) use ($connection, $queueConnection, $directory, &$processes): Process {
            $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/DispatchIntentMysqlWorker.php'], dirname(__DIR__, 2));
            $process->setInput(json_encode(array_merge(['worker' => $name, 'operation' => $operation, 'message_id' => $name, 'connection' => $connection,
                'queue_connection' => $queueConnection, 'directory' => $directory], $extra), JSON_THROW_ON_ERROR));
            $process->setTimeout(45);
            $process->start();
            $processes[] = $process;

            return $process;
        };
        $finish = function (string $name, Process $process) use (&$evidence): array {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $value = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $value['process_exit_code'] = $process->getExitCode();
            $value['process_stderr'] = $process->getErrorOutput();
            $evidence['cases'][$name] = $value;

            return $value;
        };
        $run = fn (string $name, string $operation, array $extra = []) => $finish($name, $start($name, $operation, $extra));
        $await = function (string $name, string $marker, Process $process) use ($directory): array {
            $deadline = microtime(true) + 20;
            $file = $directory.'/'.$name.'.'.$marker.'.json';
            while (! is_file($file)) {
                if (! $process->isRunning() || microtime(true) > $deadline) {
                    throw new RuntimeException('Intent fixture milestone absent: '.$name.'.'.$marker.' '.$process->getErrorOutput());
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

        // Disabled behavior and intentionally persisted factory rows acquire no intent.
        foreach (['factory', 'send', 'run', 'receive'] as $operation) {
            $reset();
            $result = $run('disabled-'.$operation, $operation, ['enabled' => false]);
            expect($result['exceptions'])->toBe([])->and($result['messages'])->toHaveCount(1)->and($result['intents'])->toBe([])
                ->and($result['jobs'])->toHaveCount($operation === 'factory' ? 0 : 1);
        }
        $reset();
        $factory = $run('factory-enabled', 'factory');
        expect($factory['intents'])->toBe([])->and($factory['jobs'])->toBe([]);

        foreach (['dispatch_intent.before_record', 'dispatch_intent.recorded'] as $index => $hook) {
            $reset();
            $name = 'rollback-boundary-'.$index;
            $producer = $start($name, 'send', ['hook' => $hook]);
            $kill($name, $producer, 'hook');
            expect($db->table('talkto_messages')->count())->toBe(0)
                ->and($db->table('talkto_dispatch_intents')->count())->toBe(0)
                ->and($db->table('jobs')->count())->toBe(0);
        }

        foreach (['send', 'run', 'receive'] as $operation) {
            foreach ([true, false] as $sameConnection) {
                $reset();
                $name = $operation.($sameConnection ? '-atomic' : '-separate');
                $sent = $run($name, $operation, ['same_connection' => $sameConnection]);
                expect($sent['exceptions'])->toBe([])->and($sent['messages'])->toHaveCount(1)->and($sent['intents'])->toHaveCount(1)
                    ->and($sent['intents'][0]['status'])->toBe('published')->and($sent['jobs'])->toHaveCount(1)->and($sent['metrics']['publications'])->toBe(1);
                $consumed = $run($name.'-consumer', 'consume', ['same_connection' => $sameConnection]);
                expect($consumed['exceptions'])->toBe([])->and($consumed['jobs'])->toBe([])->and($consumed['metrics']['executions'])->toBe(1)
                    ->and($consumed['metrics'][$operation === 'receive' ? 'handler_calls' : 'http_calls'])->toBe(1);
            }
        }

        // Commit succeeds, producer is terminated before publication can begin.
        foreach (['send', 'receive'] as $operation) {
            $reset();
            $name = 'committed-'.$operation;
            $producer = $start($name, $operation, ['hook' => 'dispatch_intent.before_claim']);
            $kill($name, $producer, 'hook');
            expect($db->table('talkto_messages')->count())->toBe(1)->and($db->table('talkto_dispatch_intents')->count())->toBe(1)
                ->and($db->table('talkto_dispatch_intents')->value('status'))->toBe('ready')->and($db->table('jobs')->count())->toBe(0);
            $recovered = $run($name.'-recovery', 'recover', ['message_id' => $name]);
            expect($recovered['exceptions'])->toBe([])->and($recovered['command_exit'])->toBe(0)->and($recovered['jobs'])->toHaveCount(1)
                ->and($recovered['intents'][0]['status'])->toBe('published');
            $executed = $run($name.'-consumer', 'consume');
            expect($executed['metrics']['executions'])->toBe(1)
                ->and($executed['metrics'][$operation === 'receive' ? 'handler_calls' : 'http_calls'])->toBe(1);
            $repeat = $run($name.'-repeat', 'recover', ['message_id' => $name]);
            expect($repeat['jobs'])->toBe([])->and($repeat['metrics']['publications'])->toBe(1);
        }

        // Lost acknowledgement on another connection must never become automatic retry.
        $reset();
        $unknown = $run('lost-ack', 'send', ['same_connection' => false, 'lost_ack' => true]);
        expect($unknown['messages'])->toHaveCount(1)->and($unknown['intents'])->toHaveCount(1)->and($unknown['intents'][0]['status'])->toBe('unknown')
            ->and($unknown['jobs'])->toHaveCount(1);
        $retry = $run('lost-ack-recovery', 'recover', ['same_connection' => false, 'message_id' => 'lost-ack']);
        expect($retry['jobs'])->toHaveCount(1)->and($retry['metrics']['publications'])->toBe(1);

        // Same-connection failed enqueue rolls back the job and publication
        // transition; its committed claim can safely expire before recovery.
        $reset();
        $failed = $run('atomic-failed', 'send', ['missing_queue_table' => true]);
        expect($failed['messages'])->toHaveCount(1)->and($failed['intents'])->toHaveCount(1)->and($failed['jobs'])->toBe([])
            ->and($failed['intents'][0]['status'])->toBe('claimed')
            ->and($failed['intents'][0]['publication_started_at'])->toBeNull();
        $db->table('talkto_dispatch_intents')->update(['claim_expires_at' => now()->subMinute()]);
        $retry = $run('atomic-failed-recovery', 'recover', ['message_id' => 'atomic-failed']);
        expect($retry['jobs'])->toHaveCount(1)->and($retry['intents'][0]['status'])->toBe('published');

        // Independent recovery operators race on the same persisted never-attempted intent.
        $reset();
        $producer = $start('two-operators', 'send', ['hook' => 'dispatch_intent.before_claim']);
        $kill('two-operators', $producer, 'hook');
        $a = $start('operator-a', 'recover', ['message_id' => 'two-operators']);
        $b = $start('operator-b', 'recover', ['message_id' => 'two-operators']);
        $ar = $finish('operator-a', $a);
        $br = $finish('operator-b', $b);
        expect($ar['connection_id'])->not->toBe($br['connection_id'])->and($db->table('jobs')->count())->toBe(1)
            ->and($db->table('intent_probe_metrics')->where('name', 'publications')->value('value'))->toBe(1);

        // Expiry is not publication evidence: only a never-started CLAIMED
        // lease can be reclaimed, and the old holder must lose its fence.
        $reset();
        $run('lease-expiry', 'send', ['publish_after_commit' => false]);
        $old = $start('old-publisher', 'recover', ['message_id' => 'lease-expiry', 'hook' => 'dispatch_intent.claimed']);
        $await('old-publisher', 'hook', $old);
        $oldTokenHash = hash('sha256', (string) $db->table('talkto_dispatch_intents')->value('claim_token'));
        $db->table('talkto_dispatch_intents')->update(['claim_expires_at' => now()->subMinute()]);
        $new = $run('new-publisher', 'recover', ['message_id' => 'lease-expiry']);
        expect($new['jobs'])->toHaveCount(1)->and($new['intents'][0]['status'])->toBe('published');
        touch($directory.'/old-publisher.release');
        $resumed = $finish('old-publisher', $old);
        expect($resumed['jobs'])->toHaveCount(1)->and($resumed['metrics']['publications'])->toBe(1);
        $evidence['cases']['lease-fence'] = ['old_token_sha256' => $oldTokenHash, 'expired_claim_injected' => true, 'publication_count' => 1];

        // A crash after the external queue accepted a job is deliberately not
        // reclaimed, even when the publishing claim's expiry is in the past.
        $reset();
        $external = $start('accepted-before-crash', 'send', ['same_connection' => false, 'hook' => 'dispatch_intent.after_queue']);
        $kill('accepted-before-crash', $external, 'hook');
        expect($queueDb->table('jobs')->count())->toBe(1)
            ->and($db->table('talkto_dispatch_intents')->value('status'))->toBe('publishing');
        $db->table('talkto_dispatch_intents')->update(['claim_expires_at' => now()->subMinute()]);
        $ambiguous = $run('accepted-ambiguous-recovery', 'recover', ['message_id' => 'accepted-before-crash', 'same_connection' => false]);
        expect($ambiguous['jobs'])->toHaveCount(1)->and($ambiguous['metrics']['publications'])->toBe(1)
            ->and($ambiguous['intents'][0]['status'])->not->toBe('ready');

        // Old acknowledged history must not consume the bounded recovery
        // selector and permanently hide a newer never-attempted intent.
        $reset();
        $run('ready-after-history', 'send', ['publish_after_commit' => false]);
        $history = (array) $db->table('talkto_dispatch_intents')->first();
        unset($history['id']);
        foreach (range(1, 3) as $number) {
            $db->table('talkto_dispatch_intents')->insert(array_merge($history, [
                'intent_key' => hash('sha256', json_encode([$history['message_table'], $history['message_key_name'], (string) (100000 + $number), 'old-published-'.$number, 'initial'], JSON_THROW_ON_ERROR)),
                'message_key' => (string) (100000 + $number),
                'message_id' => 'old-published-'.$number,
                'status' => 'published', 'published_at' => now()->subDay(),
                'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
            ]));
        }
        $unstarved = $run('bounded-recovery-after-history', 'recover', ['message_id' => null, 'limit' => 1]);
        expect($unstarved['jobs'])->toHaveCount(1)->and($unstarved['metrics']['publications'])->toBe(1)
            ->and($db->table('talkto_dispatch_intents')->where('message_id', 'ready-after-history')->value('status'))->toBe('published');

        // A valid callback or a new owner wins while the publisher holds only
        // its durable lease, before the final locked publication revalidation.
        foreach (['callback', 'owner'] as $advance) {
            $reset();
            $name = 'advanced-'.$advance;
            $run($name, 'send', ['publish_after_commit' => false]);
            $publisher = $start($name.'-publisher', 'recover', ['message_id' => $name, 'hook' => 'dispatch_intent.before_publish']);
            $await($name.'-publisher', 'hook', $publisher);
            if ($advance === 'callback') {
                $completed = $run($name.'-callback', 'callback', ['message_id' => $name]);
                expect($completed['callback_result'])->toMatchArray(['accepted' => true, 'status' => 'applied']);
            } else {
                $db->table('talkto_messages')->where('message_id', $name)->update(['source_service' => 'another-service']);
            }
            $authoritative = (array) $db->table('talkto_messages')->where('message_id', $name)->first();
            touch($directory.'/'.$name.'-publisher.release');
            $finished = $finish($name.'-publisher', $publisher);
            expect($finished['jobs'])->toBe([])->and($finished['intents'][0]['status'])->toBe('cancelled')
                ->and((array) $db->table('talkto_messages')->where('message_id', $name)->first())->toBe($authoritative);
        }

        foreach (['retry', 'stale'] as $operation) {
            $reset();
            $name = 'existing-'.$operation;
            $run($name, 'send', ['publish_after_commit' => false]);
            $publisher = $start($name.'-publisher', 'recover', ['message_id' => $name, 'hook' => 'dispatch_intent.before_publish']);
            $await($name.'-publisher', 'hook', $publisher);
            $db->table('talkto_messages')->where('message_id', $name)->update($operation === 'retry'
                ? ['overall_status' => 'failed_retryable', 'transport_status' => 'failed', 'next_retry_at' => now()->subMinute(), 'retry_count' => 1]
                : ['overall_status' => 'sending', 'transport_status' => 'sending', 'locked_at' => now()->subHour(), 'locked_by' => 'worker:synthetic']);
            $existing = $run($name.'-operator', $operation, ['message_id' => $name]);
            expect($existing['command_exit'])->toBe(0)->and($existing['jobs'])->toHaveCount(1);
            $current = (array) $db->table('talkto_messages')->where('message_id', $name)->first();
            touch($directory.'/'.$name.'-publisher.release');
            $finished = $finish($name.'-publisher', $publisher);
            expect($finished['jobs'])->toHaveCount(1)->and($finished['metrics']['publications'])->toBe(1)
                ->and((array) $db->table('talkto_messages')->where('message_id', $name)->first())->toBe($current);
        }

        // Concurrent duplicate creates actually block on the unique message or
        // fingerprint key, rather than merely being two sequential API calls.
        foreach (['send', 'receive'] as $operation) {
            $reset();
            $name = 'unique-'.$operation;
            $first = $start($name.'-first', $operation, ['message_id' => $name, 'idempotency_key' => 'unique-key', 'hook' => 'dispatch_intent.before_record']);
            $await($name.'-first', 'hook', $first);
            $holder = $await($name.'-first', 'connected', $first);
            $second = $start($name.'-second', $operation, ['message_id' => $operation === 'send' ? $name.'-different-id' : $name, 'idempotency_key' => 'unique-key', 'observe_message_insert' => true]);
            $waiter = $await($name.'-second', 'message-insert-attempt', $second);
            $proof = dispatchIntentObservedWait($admin, $waiter['connection_id'], $holder['connection_id'], $second);
            $proof['wait_observed_after_ms'] = round((microtime(true) - $waiter['observed_at']) * 1000, 3);
            touch($directory.'/'.$name.'-first.release');
            $one = $finish($name.'-first', $first);
            $two = $finish($name.'-second', $second);
            expect($one['exceptions'])->toBe([])->and($two['exceptions'])->toBe([])->and($db->table('talkto_messages')->count())->toBe(1)
                ->and($db->table('talkto_dispatch_intents')->count())->toBe(1)->and($db->table('jobs')->count())->toBe(1);
            $evidence['cases'][$name.'-lock-proof'] = $proof;
        }

        // A real MySQL deadlock victim must not strand or double-publish the
        // recoverable atomic database operation. No server setting is changed.
        $reset();
        $run('deadlock', 'send', ['publish_after_commit' => false]);
        $deadlocksBefore = (int) $admin->query("SELECT COUNT FROM information_schema.innodb_metrics WHERE NAME = 'lock_deadlocks'")->fetchColumn();
        $publisher = $start('deadlock-publisher', 'recover', ['message_id' => 'deadlock', 'pause_atomic_publishing' => true]);
        $holder = $await('deadlock-publisher', 'atomic-publishing-held', $publisher);
        $opponent = $start('deadlock-opponent', 'deadlock-opponent', ['message_id' => 'deadlock']);
        $waiter = $await('deadlock-opponent', 'message-lock-attempt', $opponent);
        $proof = dispatchIntentObservedWait($admin, $waiter['connection_id'], $holder['connection_id'], $opponent);
        $proof['wait_observed_after_ms'] = round((microtime(true) - $waiter['observed_at']) * 1000, 3);
        touch($directory.'/deadlock-publisher.release');
        $pubResult = $finish('deadlock-publisher', $publisher);
        $opponentResult = $finish('deadlock-opponent', $opponent);
        $deadlocksAfter = (int) $admin->query("SELECT COUNT FROM information_schema.innodb_metrics WHERE NAME = 'lock_deadlocks'")->fetchColumn();
        expect($deadlocksAfter)->toBeGreaterThan($deadlocksBefore)->and($db->table('jobs')->count())->toBeLessThanOrEqual(1);
        $db->table('talkto_dispatch_intents')->where('status', 'claimed')->update(['claim_expires_at' => now()->subMinute()]);
        $recovered = $run('deadlock-recovery', 'recover', ['message_id' => 'deadlock']);
        expect($recovered['jobs'])->toHaveCount(1)->and($recovered['metrics']['publications'])->toBe(1);
        $evidence['cases']['deadlock-proof'] = $proof + ['engine_deadlocks_before' => $deadlocksBefore, 'engine_deadlocks_after' => $deadlocksAfter];

        foreach ([1024, 256 * 1024, 1024 * 1024, (int) (2.4 * 1024 * 1024)] as $bytes) {
            $reset();
            $large = $run('payload-'.$bytes, 'send', ['payload_bytes' => $bytes]);
            expect($large['exceptions'])->toBe([])->and($large['jobs'])->toHaveCount(1)->and($large['intents'])->toHaveCount(1);
            $executed = $run('payload-'.$bytes.'-consumer', 'consume');
            expect($executed['metrics']['http_calls'])->toBe(1);
        }

        $reset();
        $foreign = $run('foreign-source', 'foreign-source-run');
        expect($foreign['exceptions'])->not->toBeEmpty()->and($foreign['source_action_called'])->toBeFalse()
            ->and($foreign['messages'])->toBe([])->and($foreign['intents'])->toBe([])->and($foreign['jobs'])->toBe([]);

        foreach (['send', 'receive'] as $operation) {
            $reset();
            $name = 'custom-'.$operation;
            $custom = $run($name, $operation, ['custom_jobs' => true]);
            $queue = $operation === 'send' ? 'custom-out' : 'custom-in';
            expect($custom['exceptions'])->toBe([])->and($custom['jobs'])->toHaveCount(1)->and($custom['jobs'][0]['queue'])->toBe($queue);
            $consumed = $run($name.'-consumer', 'consume', ['custom_jobs' => true, 'queue_name' => $queue]);
            expect($consumed['metrics']['executions'])->toBe(1)
                ->and($consumed['job_class'])->toBe($operation === 'send' ? 'DispatchIntentCustomSendJob' : 'DispatchIntentCustomIncomingJob')
                ->and($consumed['metrics'][$operation === 'receive' ? 'handler_calls' : 'http_calls'])->toBe(1);
        }

        foreach (['nested-commit', 'nested-rollback'] as $operation) {
            $reset();
            $result = $run($operation, $operation);
            expect($result['exceptions'])->toBe([])
                ->and($result['jobs_before_inner_commit'])->toBe(0)->and($result['jobs_before_outer_resolution'])->toBe(0)
                ->and($result['messages'])->toHaveCount($operation === 'nested-commit' ? 1 : 0)
                ->and($result['intents'])->toHaveCount($operation === 'nested-commit' ? 1 : 0)->and($result['jobs'])->toHaveCount($operation === 'nested-commit' ? 1 : 0);
        }

        // A delayed queue entry is already published; recovery must not confuse
        // its intentionally future availability or its reservation with loss.
        $reset();
        $delayed = $run('delayed-job', 'send', ['delayed_job' => true]);
        expect($delayed['exceptions'])->toBe([])->and($delayed['jobs'])->toHaveCount(1)
            ->and($delayed['intents'][0]['status'])->toBe('published')
            ->and($delayed['jobs'][0]['available_at'])->toBeGreaterThan(time());
        $notDue = $run('delayed-before-due', 'consume', ['delayed_job' => true]);
        expect($notDue['popped'])->toBeFalse()->and($notDue['metrics']['executions'])->toBe(0);
        $db->table('jobs')->update(['available_at' => time() - 1]);
        $reserved = $run('reserved-job', 'reserve', ['delayed_job' => true]);
        expect($reserved['popped'])->toBeTrue()->and($reserved['jobs'][0]['reserved_at'])->not->toBeNull();
        $notDuplicated = $run('reserved-recovery', 'recover', ['message_id' => 'delayed-job', 'delayed_job' => true]);
        expect($notDuplicated['jobs'])->toHaveCount(1)->and($notDuplicated['metrics']['publications'])->toBe(1);
        // Simulate expiry of the actual queue reservation, not intent expiry.
        $db->table('jobs')->update(['reserved_at' => time() - 121]);
        $delayedConsumer = $run('reserved-expired-consumer', 'consume', ['delayed_job' => true]);
        expect($delayedConsumer['metrics']['executions'])->toBe(1)->and($delayedConsumer['metrics']['http_calls'])->toBe(1);

        // Deliberately duplicate the actual broker envelope. The second worker
        // sees the existing Processing state and cannot invoke the handler.
        $reset();
        $run('duplicate-envelope', 'receive');
        $envelope = (array) $db->table('jobs')->sole();
        unset($envelope['id']);
        $db->table('jobs')->insert($envelope);
        $processing = $start('duplicate-first-consumer', 'consume', ['pause_handler' => true]);
        $await('duplicate-first-consumer', 'handler-started', $processing);
        expect($db->table('talkto_messages')->value('overall_status'))->toBe('processing');
        $duplicate = $run('duplicate-second-consumer', 'consume');
        expect($duplicate['metrics']['executions'])->toBe(2)->and($duplicate['metrics']['handler_calls'])->toBe(1);
        $whileProcessing = $run('processing-recovery', 'recover', ['message_id' => 'duplicate-envelope']);
        expect($whileProcessing['metrics']['publications'])->toBe(1)->and($whileProcessing['jobs'])->toHaveCount(1);
        touch($directory.'/duplicate-first-consumer.release');
        $completed = $finish('duplicate-first-consumer', $processing);
        expect($completed['messages'][0]['overall_status'])->toBe('succeeded')->and($completed['metrics']['handler_calls'])->toBe(1);

        $reset();
        $run('terminated-worker', 'receive');
        $processing = $start('terminated-consumer', 'consume', ['pause_handler' => true]);
        $kill('terminated-consumer', $processing, 'handler-started');
        $terminated = $run('terminated-worker-recovery', 'recover', ['message_id' => 'terminated-worker']);
        expect($terminated['messages'][0]['overall_status'])->toBe('processing')
            ->and($terminated['metrics']['handler_calls'])->toBe(1)->and($terminated['metrics']['publications'])->toBe(1)
            ->and($terminated['jobs'])->toHaveCount(1)->and($terminated['intents'][0]['status'])->toBe('published');

        // A small isolated concurrency measurement, not a production capacity
        // estimate: eight distinct initial intents, eight independent PHP apps.
        $reset();
        foreach (range(1, 8) as $number) {
            $run('burst-'.$number, 'send', ['publish_after_commit' => false]);
        }
        $burst = [];
        $burstStarted = microtime(true);
        foreach (range(1, 8) as $number) {
            $burst[$number] = $start('burst-publisher-'.$number, 'recover', ['message_id' => 'burst-'.$number]);
        }
        foreach ($burst as $number => $process) {
            $result = $finish('burst-publisher-'.$number, $process);
            expect($result['exceptions'])->toBe([])->and($result['command_exit'])->toBe(0);
        }
        $burstSeconds = microtime(true) - $burstStarted;
        expect($db->table('talkto_dispatch_intents')->count())->toBe(8)
            ->and($db->table('talkto_dispatch_intents')->where('status', 'published')->count())->toBe(8)
            ->and($db->table('jobs')->count())->toBe(8)
            ->and((int) $db->table('intent_probe_metrics')->where('name', 'publications')->value('value'))->toBe(8)
            ->and((int) $db->table('intent_probe_metrics')->where('name', 'handler_calls')->value('value'))->toBe(0);
        $evidence['burst_measurement'] = ['independent_publishers' => 8, 'published_intents' => 8,
            'elapsed_seconds_including_process_startup' => round($burstSeconds, 6),
            'publications_per_second_including_process_startup' => round(8 / $burstSeconds, 3),
            'intent_table_bytes' => (array) $db->selectOne("SELECT DATA_LENGTH,INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='talkto_dispatch_intents'")];

        $evidence['intent_recovery_explain'] = json_decode($db->selectOne("EXPLAIN FORMAT=JSON SELECT id,message_id,direction,owner,status,claim_expires_at,publication_attempts,created_at,last_error_code FROM talkto_dispatch_intents WHERE owner='base' AND (status='ready' OR (status='claimed' AND claim_expires_at <= CURRENT_TIMESTAMP)) AND created_at <= CURRENT_TIMESTAMP ORDER BY created_at,id LIMIT 100")->EXPLAIN, true, flags: JSON_THROW_ON_ERROR);
        $evidence['intent_schema'] = (array) $db->selectOne('SHOW CREATE TABLE talkto_dispatch_intents');

        $reset();
        $unsupported = $run('sync-unsupported', 'send', ['sync_queue' => true]);
        expect($unsupported['exceptions'])->not->toBeEmpty()->and($unsupported['jobs'])->toBe([])->and($unsupported['intents'])->toBe([]);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        }
        DB::purge('intent_parent');
        DB::purge('intent_queue_parent');
        $admin->exec("DROP DATABASE `{$database}`");
        $admin->exec("DROP DATABASE `{$queueDatabase}`");
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

function dispatchIntentObservedWait(PDO $admin, int $waiting, int $blocking, Process $worker): array
{
    $deadline = microtime(true) + 15;
    $query = $admin->prepare('SELECT request.trx_mysql_thread_id AS waiting_connection_id, blocker.trx_mysql_thread_id AS blocking_connection_id FROM performance_schema.data_lock_waits AS waits JOIN information_schema.innodb_trx AS request ON request.trx_id = waits.REQUESTING_ENGINE_TRANSACTION_ID JOIN information_schema.innodb_trx AS blocker ON blocker.trx_id = waits.BLOCKING_ENGINE_TRANSACTION_ID WHERE request.trx_mysql_thread_id = ? AND blocker.trx_mysql_thread_id = ?');
    while (microtime(true) < $deadline) {
        $query->execute([$waiting, $blocking]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return ['waiting_connection_id' => (int) $row['waiting_connection_id'], 'blocking_connection_id' => (int) $row['blocking_connection_id'], 'source' => 'Actual performance_schema.data_lock_waits joined to information_schema.innodb_trx', 'observed_at' => microtime(true)];
        }
        if (! $worker->isRunning()) {
            throw new RuntimeException('Worker ended before required InnoDB lock wait was observed: '.$worker->getErrorOutput());
        }
        usleep(20000);
    }
    throw new RuntimeException('Expected real durable-intent InnoDB lock wait was not observed within its bounded deadline.');
}
