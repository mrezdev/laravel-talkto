<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

test('real database queue outcomes remain ambiguous and diagnostics never publish jobs', function (): void {
    if (getenv('TALKTO_MYSQL_ENABLED') !== '1') {
        throw new RuntimeException('Requires a disposable MySQL 8.x server with CREATE DATABASE permission.');
    }
    $host = getenv('TALKTO_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('TALKTO_MYSQL_PORT') ?: 3306);
    $user = getenv('TALKTO_MYSQL_USER') ?: 'root';
    $password = getenv('TALKTO_MYSQL_PASSWORD') ?: '';
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $version = (string) $admin->query('SELECT VERSION()')->fetchColumn();
    expect($version)->toMatch('/^8\./')->not->toContain('MariaDB');
    $suffix = bin2hex(random_bytes(12));
    $database = 'talkto_undispatched_'.$suffix;
    $queueDatabase = 'talkto_undispatched_queue_'.$suffix;
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->exec("CREATE DATABASE `{$queueDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $directory = sys_get_temp_dir().'/talkto-undispatched-'.bin2hex(random_bytes(12));
    $processes = [];
    $evidence = ['mysql_version' => $version, 'php_version' => PHP_VERSION, 'laravel_version' => app()->version(),
        'design' => 'Read-only diagnostics, no intent ledger or recovery claim. Missing publication evidence is unknown, never a proof of nonpublication.',
        'queue_backend' => 'Actual Laravel DatabaseQueue and SyncQueue; no Queue/Bus fake. Controlled HTTP response only.',
        'cases' => [], 'blocked' => ['Automatic/authorized recovery and concurrent eligible recovery claims: not implemented because no durable proof of publication absence exists.', 'Patch B versus retry/stale recovery claim contention: BLOCKED, Patch B has no claim operation. Existing unchanged concurrency suites cover existing claims.'],
        'redis' => ['result' => 'NOT RUN / BLOCKED locally', 'reason' => 'Windows Redis5.0.14.1 binaries exist, but no PHP redis extension or installed Predis client. No dependency installed. CI availability is not inferred.'],
        'other_backends' => 'SQS and other brokers NOT RUN. Their publication acknowledgement is not atomic with the Talkto database.'];
    $evidencePath = getenv('TALKTO_UNDISPATCHED_QUEUE_EVIDENCE_PATH');
    try {
        mkdir($directory, 0700);
        $connection = ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $database, 'username' => $user, 'password' => $password,
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00'];
        $queueConnection = array_merge($connection, ['database' => $queueDatabase]);
        config(['database.connections.undispatched_parent' => $connection, 'database.connections.undispatched_queue_parent' => $queueConnection,
            'talkto.database.connection' => 'undispatched_parent']);
        $migrations = glob(__DIR__.'/../../database/migrations/*.php');
        sort($migrations);
        foreach ($migrations as $migration) {
            (require $migration)->up();
        }
        $db = DB::connection('undispatched_parent');
        $queueDb = DB::connection('undispatched_queue_parent');
        foreach ([$db, $queueDb] as $queueOwner) {
            $queueOwner->statement('CREATE TABLE jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload LONGTEXT NOT NULL, attempts TINYINT UNSIGNED NOT NULL, reserved_at INT UNSIGNED NULL, available_at INT UNSIGNED NOT NULL, created_at INT UNSIGNED NOT NULL, INDEX jobs_queue_index(queue)) ENGINE=InnoDB');
        }
        $db->statement('CREATE TABLE dispatch_probe_metrics (name VARCHAR(50) PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');
        foreach (['handler_calls', 'http_calls', 'job_executions'] as $metric) {
            $db->table('dispatch_probe_metrics')->insert(['name' => $metric, 'value' => 0]);
        }
        $reset = function () use ($db, $queueDb): void {
            foreach (['talkto_events', 'talkto_attempts', 'talkto_dead_letters', 'talkto_nonces', 'talkto_messages', 'jobs'] as $table) {
                $db->table($table)->delete();
            }
            $queueDb->table('jobs')->delete();
            $db->table('dispatch_probe_metrics')->update(['value' => 0]);
        };
        $start = function (string $name, string $operation, array $extra = []) use ($connection, $queueConnection, $directory, &$processes): Process {
            $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/UndispatchedMysqlWorker.php'], dirname(__DIR__, 2));
            $process->setInput(json_encode(array_merge(['worker' => $name, 'operation' => $operation, 'message_id' => $name,
                'connection' => $connection, 'queue_connection' => $queueConnection, 'directory' => $directory], $extra), JSON_THROW_ON_ERROR));
            $process->setTimeout(40);
            $process->start();
            $processes[] = $process;

            return $process;
        };
        $run = function (string $name, string $operation, array $extra = []) use ($start, &$evidence): array {
            $process = $start($name, $operation, $extra);
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $result['process_exit_code'] = $process->getExitCode();
            $result['process_stderr'] = $process->getErrorOutput();
            $evidence['cases'][$name] = $result;

            return $result;
        };
        $await = function (string $name, string $marker, Process $process) use ($directory): array {
            $deadline = microtime(true) + 20;
            $file = $directory.'/'.$name.'.'.$marker.'.json';
            while (! is_file($file)) {
                if (! $process->isRunning() || microtime(true) > $deadline) {
                    throw new RuntimeException('Fixture milestone absent: '.$name.'.'.$marker.' '.$process->getErrorOutput());
                }
                usleep(10000);
                clearstatcache();
            }

            return json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        };
        $snapshot = fn (): array => ['messages' => $db->table('talkto_messages')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'events' => $db->table('talkto_events')->count(), 'jobs' => $queueDb->table('jobs')->count(),
            'local_jobs' => $db->table('jobs')->count(), 'metrics' => $db->table('dispatch_probe_metrics')->pluck('value', 'name')->all()];
        $checkReadOnly = function (string $name, ?string $id = null) use ($run, $snapshot): array {
            $before = $snapshot();
            $diagnostic = $run($name, 'inspect', ['message_id' => $id]);
            expect($diagnostic['exceptions'])->toBe([])->and($diagnostic['report']['recovery_enabled'])->toBeFalse()
                ->and($snapshot())->toBe($before);
            foreach ($diagnostic['inspect_queries'] as $query) {
                expect(strtolower(ltrim($query['sql'])))->toStartWith('select ');
            }

            return $diagnostic;
        };

        // Same persisted initial state can represent intentional persistence,
        // a missing publication, or a job already accepted by the real queue.
        foreach (['factory', 'send'] as $operation) {
            foreach ([true, false] as $sameConnection) {
                $reset();
                $name = $operation.($sameConnection ? '-same' : '-separate');
                $result = $run($name, $operation, ['same_connection' => $sameConnection]);
                expect($result['exceptions'])->toBe([])->and($result['messages'])->toHaveCount(1)
                    ->and($result['jobs'])->toHaveCount($operation === 'send' ? 1 : 0);
                $checkReadOnly($name.'-diagnostic', $name);
                if ($operation === 'send') {
                    $consumed = $run($name.'-consume', 'consume', ['same_connection' => $sameConnection]);
                    expect($consumed['exceptions'])->toBe([])->and($consumed['jobs'])->toBe([])
                        ->and($consumed['metrics']['http_calls'])->toBe(1)->and($consumed['metrics']['job_executions'])->toBe(1);
                }
            }
        }
        foreach (['unavailable', 'lost-ack', 'missing-table'] as $failure) {
            $reset();
            $result = $run('failure-'.$failure, 'send', ['queue_mode' => $failure]);
            expect($result['exceptions'])->toHaveCount(1)->and($result['messages'])->toHaveCount(1)
                ->and($result['jobs'])->toHaveCount($failure === 'lost-ack' ? 1 : 0);
            expect($result['exceptions'][0]['class'])->toBe($failure === 'missing-table' ? QueryException::class : RuntimeException::class);
            $checkReadOnly('failure-'.$failure.'-diagnostic');
        }
        $reset();
        $afterCommitFailure = $run('after-commit-failure', 'nested-commit', ['queue_mode' => 'missing-table']);
        expect($afterCommitFailure['exceptions'][0]['class'])->toBe(QueryException::class)
            ->and($afterCommitFailure['messages'])->toHaveCount(1)->and($afterCommitFailure['jobs'])->toBe([]);
        $checkReadOnly('after-commit-failure-diagnostic');
        foreach (['nested-commit', 'nested-rollback'] as $operation) {
            foreach ([true, false] as $sameConnection) {
                $reset();
                $result = $run($operation.($sameConnection ? '-same' : '-separate'), $operation, ['same_connection' => $sameConnection]);
                expect($result['exceptions'])->toBe([])->and($result['jobs_before_inner_commit'])->toBe(0)
                    ->and($result['jobs_before_outer_resolution'])->toBe(0)
                    ->and($result['jobs'])->toHaveCount($operation === 'nested-commit' ? 1 : 0)
                    ->and($result['messages'])->toHaveCount($operation === 'nested-commit' ? 1 : 0);
            }
        }
        $reset();
        $gap = $run('source-rollback-gap', 'source-rollback-gap');
        expect($gap['exceptions'])->toBe([])->and($gap['messages'])->toHaveCount(1)->and($gap['jobs'])->toBe([]);
        $checkReadOnly('source-rollback-gap-diagnostic');
        $reset();
        $sync = $run('sync-after-commit', 'sync-send');
        expect($sync['exceptions'])->toBe([])->and($sync['http_before_commit'])->toBe(0)
            ->and($sync['metrics']['http_calls'])->toBe(1)->and($sync['jobs'])->toBe([]);
        $reset();
        $custom = $run('custom-job', 'send', ['custom_job' => true]);
        $customConsumed = $run('custom-job-consumed', 'consume');
        expect($custom['exceptions'])->toBe([])->and($customConsumed['exceptions'])->toBe([])
            ->and($customConsumed['metrics']['http_calls'])->toBe(1)
            ->and($customConsumed['consumed_job_name'])->toBe('UndispatchedCustomSendJob');

        // Signed receiver still has its original response/auth/idempotency rules.
        $reset();
        $security = $run('incoming-security', 'receive-security');
        expect($security['exceptions'])->toBe([])->and(array_column($security['responses'], 'http_status'))->toBe([202, 200, 409, 401, 403, 403, 202, 200])
            ->and($security['messages'])->toHaveCount(2)->and($security['jobs'])->toHaveCount(2);
        $checkReadOnly('incoming-security-diagnostic');
        $reset();
        $run('incoming-duplicates', 'receive-duplicate-jobs');
        $run('incoming-first-consumption', 'consume');
        $duplicate = $run('incoming-second-consumption', 'consume');
        expect($duplicate['exceptions'])->toBe([])->and($duplicate['metrics']['job_executions'])->toBe(2)
            ->and($duplicate['metrics']['handler_calls'])->toBe(1)->and($duplicate['jobs'])->toBe([])
            ->and($duplicate['messages'][0]['overall_status'])->toBe('succeeded');

        // Process termination occurs at actual before/after publication barriers.
        foreach (['before', 'after'] as $phase) {
            $reset();
            $name = 'crash-'.$phase;
            $producer = $start($name, 'receive', ['queue_mode' => $phase]);
            $await($name, $phase.'-publication', $producer);
            expect($db->table('talkto_messages')->count())->toBe(1)->and($queueDb->table('jobs')->count())->toBe($phase === 'after' ? 1 : 0);
            $producer->stop(0.1);
            $evidence['cases'][$name] = ['terminated_by_parent_at' => $phase.'-publication', 'queue_rows' => $queueDb->table('jobs')->count(), 'message_rows' => $db->table('talkto_messages')->count()];
            $checkReadOnly($name.'-diagnostic');
            $duplicateRequest = $run($name.'-duplicate', 'receive', ['message_id' => $name]);
            expect($duplicateRequest['responses'][0])->toMatchArray(['http_status' => 200, 'body' => ['received' => true, 'duplicate' => true, 'status' => 'already_received', 'message_id' => $name]])
                ->and($duplicateRequest['jobs'])->toHaveCount($phase === 'after' ? 1 : 0);
        }
        $reset();
        $run('delayed-message', 'send-delayed');
        $delayed = $run('delayed-pop', 'consume');
        expect($delayed['popped_job'])->toBeFalse()->and($delayed['jobs'])->toHaveCount(1);
        $checkReadOnly('delayed-diagnostic');
        $reset();
        $run('reserved-message', 'receive');
        $reserved = $start('reserved-worker', 'reserve');
        $await('reserved-worker', 'reserved', $reserved);
        expect($queueDb->table('jobs')->whereNotNull('reserved_at')->count())->toBe(1);
        $checkReadOnly('reserved-diagnostic');
        $reserved->stop(0.1);
        $evidence['cases']['reserved-worker-killed'] = ['reserved_jobs_remaining' => $queueDb->table('jobs')->whereNotNull('reserved_at')->count(), 'handler_calls' => $db->table('dispatch_probe_metrics')->where('name', 'handler_calls')->value('value')];

        // Two diagnostic operators overlap a real worker paused inside a handler.
        $reset();
        $run('processing-message', 'receive');
        $worker = $start('processing-worker', 'process-paused');
        $await('processing-worker', 'processing', $worker);
        expect($db->table('talkto_messages')->value('overall_status'))->toBe('processing');
        $one = $start('operator-one', 'inspect', ['pause' => true, 'message_id' => null]);
        $two = $start('operator-two', 'inspect', ['pause' => true, 'message_id' => null]);
        $await('operator-one', 'ready', $one);
        $await('operator-two', 'ready', $two);
        touch($directory.'/operator-one.release');
        touch($directory.'/operator-two.release');
        foreach ([$one, $two] as $operator) {
            $operator->wait();
            expect($operator->getExitCode())->toBe(0);
            $result = json_decode(trim($operator->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            expect($result['exceptions'])->toBe([])->and($result['report']['recovery_enabled'])->toBeFalse();
            $evidence['cases'][$result['worker']] = $result;
        }
        expect($db->table('dispatch_probe_metrics')->where('name', 'handler_calls')->value('value'))->toBe(1)
            ->and($queueDb->table('jobs')->count())->toBe(1);
        $worker->stop(0.1);
        $evidence['cases']['processing-worker-killed'] = ['state' => $db->table('talkto_messages')->value('overall_status'), 'queue_reservation_exists' => $queueDb->table('jobs')->whereNotNull('reserved_at')->exists()];
        $checkReadOnly('killed-processing-diagnostic');
        $reset();
        $run('completion-message', 'receive');
        $inspector = $start('before-completion', 'inspect', ['pause' => true, 'message_id' => 'completion-message']);
        $await('before-completion', 'ready', $inspector);
        $completed = $run('completion-worker', 'consume');
        touch($directory.'/before-completion.release');
        $inspector->wait();
        $after = json_decode(trim($inspector->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        expect($completed['messages'][0]['overall_status'])->toBe('succeeded')->and($after['exceptions'])->toBe([])
            ->and($after['report']['recovery_enabled'])->toBeFalse()->and($queueDb->table('jobs')->count())->toBe(0);
        $evidence['cases']['completion-during-inspection'] = $after;
        $reset();
        $run('callback-message', 'factory');
        $inspector = $start('before-callback', 'inspect', ['pause' => true, 'message_id' => 'callback-message']);
        $await('before-callback', 'ready', $inspector);
        $callback = $run('valid-callback', 'callback', ['message_id' => 'callback-message']);
        touch($directory.'/before-callback.release');
        $inspector->wait();
        $afterCallback = json_decode(trim($inspector->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        expect($callback['exceptions'])->toBe([])->and($callback['callback_result'])->toMatchArray(['accepted' => true, 'status' => 'applied'])
            ->and($afterCallback['messages'][0]['overall_status'])->toBe('completed')->and($afterCallback['report']['recovery_enabled'])->toBeFalse();
        $evidence['cases']['callback-during-inspection'] = $afterCallback;
        $reset();
        $producer = $start('advancing-before-lost-ack', 'send', ['queue_mode' => 'after-lost-ack']);
        $await('advancing-before-lost-ack', 'after-publication', $producer);
        expect($queueDb->table('jobs')->count())->toBe(1);
        $callback = $run('advancing-callback', 'callback', ['message_id' => 'advancing-before-lost-ack']);
        expect($callback['callback_result'])->toMatchArray(['accepted' => true, 'status' => 'applied']);
        touch($directory.'/advancing-before-lost-ack.release');
        $producer->wait();
        $lost = json_decode(trim($producer->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        expect($producer->getExitCode())->toBe(0)->and($lost['exceptions'][0]['class'])->toBe(RuntimeException::class)
            ->and($lost['messages'][0]['overall_status'])->toBe('completed')->and($lost['jobs'])->toHaveCount(1);
        $evidence['cases']['advance-during-lost-ack'] = $lost;
        $checkReadOnly('advanced-lost-ack-diagnostic');
        $lateJob = $run('late-job-after-callback', 'consume');
        expect($lateJob['exceptions'])->toBe([])->and($lateJob['metrics']['job_executions'])->toBe(1)
            ->and($lateJob['metrics']['http_calls'])->toBe(0)->and($lateJob['messages'][0]['overall_status'])->toBe('completed');
        $reset();
        $run('scope-message', 'factory');
        $inspector = $start('before-owner-change', 'inspect', ['pause' => true, 'message_id' => 'scope-message']);
        $await('before-owner-change', 'ready', $inspector);
        $db->table('talkto_messages')->where('message_id', 'scope-message')->update(['source_service' => 'other-service']);
        touch($directory.'/before-owner-change.release');
        $inspector->wait();
        $scope = json_decode(trim($inspector->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        expect($scope['exceptions'])->toBe([])->and($scope['report']['candidates'])->toBe([])
            ->and($scope['messages'][0]['source_service'])->toBe('other-service');
        $evidence['cases']['owner-change-during-inspection'] = $scope;
        $reset();
        foreach (range(1, 22) as $index) {
            $db->table('talkto_messages')->insert(['message_id' => 'large-diagnostic-'.$index, 'direction' => 'outgoing',
                'source_service' => 'base', 'target_service' => 'peer', 'command' => 'domain.command',
                'overall_status' => 'waiting_to_send', 'transport_status' => 'pending',
                'payload' => json_encode(['synthetic' => str_repeat('x', $index > 20 ? (int) (2.4 * 1024 * 1024) : 256 * 1024)], JSON_THROW_ON_ERROR),
                'last_response' => str_repeat('r', 60 * 1024), 'last_error' => str_repeat('e', 16 * 1024),
                'created_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-01 12:00:00']);
        }
        $large = $checkReadOnly('large-diagnostic');
        expect($large['report']['count'])->toBe(22)->and($large['inspect_queries'])->toHaveCount(1);
        $select = strtolower($large['inspect_queries'][0]['sql']);
        expect($select)->not->toContain('select *', 'payload', 'last_response', 'last_error')->toContain('order by', 'limit 100');
        $beforeExecute = $snapshot();
        $blocked = $run('execute-blocked', 'execute-attempt');
        expect($snapshot())->toBe($beforeExecute)->and($blocked['report']['recovery_enabled'])->toBeFalse()
            ->and($blocked['command_exit'])->toBe(1)->and($blocked['exceptions'])->toBe([]);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.1);
            }
        }
        if (is_string($evidencePath) && $evidencePath !== '') {
            file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        }
        DB::purge('undispatched_parent');
        DB::purge('undispatched_queue_parent');
        $admin->exec("DROP DATABASE `{$database}`");
        $admin->exec("DROP DATABASE `{$queueDatabase}`");
        foreach (glob($directory.'/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});
