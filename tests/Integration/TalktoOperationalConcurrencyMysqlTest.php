<?php

use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

test('independent mysql workers preserve operational claims under controlled races', function (): void {
    if (getenv('TALKTO_MYSQL_ENABLED') !== '1') {
        throw new RuntimeException('Set TALKTO_MYSQL_ENABLED=1 for a disposable MySQL 8.x server with CREATE DATABASE permission.');
    }

    $host = getenv('TALKTO_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('TALKTO_MYSQL_PORT') ?: 3306);
    $username = getenv('TALKTO_MYSQL_USER') ?: 'root';
    $password = getenv('TALKTO_MYSQL_PASSWORD') ?: '';
    $database = 'talkto_operational_concurrency_'.bin2hex(random_bytes(12));
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $version = (string) $admin->query('SELECT VERSION()')->fetchColumn();
    expect($version)->toMatch('/^8\./')->not->toContain('MariaDB');
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $directory = sys_get_temp_dir().'/talkto-operational-barriers-'.bin2hex(random_bytes(12));
    $processes = [];
    $evidence = [
        'mysql_version' => $version,
        'php_version' => PHP_VERSION,
        'laravel_version' => app()->version(),
        'synchronization' => 'Separate PHP application processes and MySQL sessions; file barriers after candidate SQL and inside FOR UPDATE transactions; database lock-wait observation before releasing contested locks.',
        'dispatch' => 'Bus fake records dispatch requests in each worker; no transport, handler or production recovery is executed.',
        'scenarios' => [],
    ];

    try {
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create the test-owned barrier directory.');
        }

        config([
            'database.connections.operational_concurrency' => [
                'driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $database,
                'username' => $username, 'password' => $password,
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00',
            ],
            'talkto.database.connection' => 'operational_concurrency',
        ]);
        $migrations = glob(__DIR__.'/../../database/migrations/*.php');
        sort($migrations);

        foreach ($migrations as $migration) {
            (require $migration)->up();
        }

        $db = DB::connection('operational_concurrency');
        $environment = [
            'TALKTO_MYSQL_ENABLED' => '1', 'TALKTO_MYSQL_HOST' => $host, 'TALKTO_MYSQL_PORT' => (string) $port,
            'TALKTO_MYSQL_USER' => $username, 'TALKTO_MYSQL_PASSWORD' => $password, 'TALKTO_OPERATIONAL_DATABASE' => $database,
        ];
        $start = function (string $worker, string $operation, array $options = []) use ($directory, $environment, &$processes): Process {
            $process = new Process(
                [PHP_BINARY, __DIR__.'/../Fixtures/OperationalMysqlWorker.php'],
                dirname(__DIR__, 2),
                [...$environment, 'TALKTO_OPERATIONAL_WORKER_OPTIONS' => json_encode(['worker' => $worker, 'operation' => $operation, 'barrier_directory' => $directory, ...$options], JSON_THROW_ON_ERROR)],
            );
            $process->setTimeout(35);
            $process->start();
            $processes[$worker] = $process;

            return $process;
        };
        $seed = function (string $kind) use ($db): int {
            foreach (['talkto_events', 'talkto_attempts', 'talkto_dead_letters', 'talkto_messages'] as $table) {
                $db->table($table)->delete();
            }

            return (int) $db->table('talkto_messages')->insertGetId([
                'message_id' => 'concurrency-'.$kind,
                'direction' => 'outgoing', 'source_service' => 'base', 'target_service' => 'website', 'command' => 'domain.sync',
                'overall_status' => $kind === 'retry' ? 'failed_retryable' : ($kind === 'orphan' ? 'waiting_to_send' : 'sending'),
                'transport_status' => $kind === 'retry' ? 'failed' : ($kind === 'orphan' ? 'pending' : 'sending'),
                'retry_count' => 2, 'attempts' => $kind === 'exhausted' ? 5 : 2, 'max_attempts' => 5,
                'next_retry_at' => $kind === 'retry' ? '2026-10-28 11:00:00' : null,
                'next_attempt_at' => $kind === 'retry' ? '2026-10-28 11:00:00' : null,
                'locked_at' => $kind === 'retry' ? null : '2026-10-28 10:00:00',
                'locked_by' => $kind === 'retry' ? null : ($kind === 'orphan' ? 'dispatch-claim:old-worker:orphan' : 'sender:old-worker'),
                'payload' => json_encode(['synthetic' => str_repeat('x', 256 * 1024)], JSON_THROW_ON_ERROR),
                'created_at' => '2026-10-28 09:00:00', 'updated_at' => '2026-10-28 11:00:00',
            ]);
        };
        $state = fn (int $id): array => (array) $db->table('talkto_messages')->where('id', $id)
            ->first(['id', 'overall_status', 'transport_status', 'retry_count', 'attempts', 'next_retry_at', 'next_attempt_at', 'locked_at', 'locked_by']);
        $eventCount = fn (int $id, string $type): int => $db->table('talkto_events')->where('talkto_message_id', $id)->where('event_type', $type)->count();

        // Scenarios 1, 4 and 8: both processes select the same key before either
        // claims it. A then holds the actual row lock while B waits in MySQL.
        foreach ([
            ['retry-contention', 'retry', 'retry'],
            ['recovery-contention', 'processing', 'recover'],
            ['exhausted-recovery-contention', 'exhausted', 'recover'],
        ] as [$scenario, $kind, $operation]) {
            $id = $seed($kind);
            $a = $start($scenario.'-a', $operation, ['pause_after_selection' => true, 'pause_after_lock' => true]);
            $b = $start($scenario.'-b', $operation, ['pause_after_selection' => true]);
            $selectedA = operationalConcurrencyAwait($directory, $scenario.'-a', 'selected', $a);
            $selectedB = operationalConcurrencyAwait($directory, $scenario.'-b', 'selected', $b);
            operationalConcurrencyAssertNarrow($selectedA, $operation);
            operationalConcurrencyAssertNarrow($selectedB, $operation);
            touch($directory.'/'.$scenario.'-a.release-selection');
            $lockedA = operationalConcurrencyAwait($directory, $scenario.'-a', 'locked', $a);
            touch($directory.'/'.$scenario.'-b.release-selection');
            $attemptB = operationalConcurrencyAwait($directory, $scenario.'-b', 'lock-attempt', $b);
            expect($lockedA['connection_id'])->not->toBe($attemptB['connection_id']);
            $waitProof = operationalConcurrencyLockWait($admin, $attemptB['connection_id'], $lockedA['connection_id'], $b);
            expect($waitProof['waiting_connection_id'])->toBe($attemptB['connection_id'])
                ->and($waitProof['blocking_connection_id'])->toBe($lockedA['connection_id']);
            touch($directory.'/'.$scenario.'-a.release-lock');
            $resultA = operationalConcurrencyFinish($directory, $scenario.'-a', $a);
            $resultB = operationalConcurrencyFinish($directory, $scenario.'-b', $b);
            $dispatches = count($resultA['outgoing_dispatch_ids']) + count($resultB['outgoing_dispatch_ids']);

            if ($kind === 'exhausted') {
                expect($dispatches)->toBe(0)
                    ->and($state($id)['overall_status'])->toBe('failed_final')
                    ->and($db->table('talkto_dead_letters')->where('talkto_message_id', $id)->count())->toBe(1)
                    ->and($eventCount($id, 'stale_lock_recovery_exhausted'))->toBe(1)
                    ->and($eventCount($id, 'dead_lettered'))->toBe(1);
            } else {
                expect($dispatches)->toBe(1)
                    ->and($state($id)['overall_status'])->toBe('waiting_to_send')
                    ->and($state($id)['retry_count'])->toBe(2)
                    ->and($eventCount($id, $kind === 'retry' ? 'retry_dispatched' : 'stale_lock_recovered'))->toBe(1);
            }

            $evidence['scenarios'][$scenario] = ['selected' => [$selectedA, $selectedB], 'lock_wait' => $waitProof, 'workers' => [$resultA, $resultB], 'final_state' => $state($id), 'dispatch_count' => $dispatches];
        }

        // Scenarios 2, 3, 5 and 6: A is still executing, paused after its
        // selector. An independent application/session changes the row first.
        foreach ([
            ['completed-before-retry-claim', 'retry', 'retry', 'complete'],
            ['another-retry-worker-claims-first', 'retry', 'retry', 'retry'],
            ['refreshed-processing-lock', 'processing', 'recover', 'refresh'],
            ['changed-orphan-claim', 'orphan', 'recover', 'replace-claim'],
        ] as [$scenario, $kind, $operation, $mutation]) {
            $id = $seed($kind);
            $a = $start($scenario.'-a', $operation, ['pause_after_selection' => true]);
            $selectedA = operationalConcurrencyAwait($directory, $scenario.'-a', 'selected', $a);
            operationalConcurrencyAssertNarrow($selectedA, $operation);
            $b = $start($scenario.'-b', $mutation, ['message_id' => $id]);
            $resultB = operationalConcurrencyFinish($directory, $scenario.'-b', $b);
            $afterOtherWorker = $state($id);
            touch($directory.'/'.$scenario.'-a.release-selection');
            $resultA = operationalConcurrencyFinish($directory, $scenario.'-a', $a);
            expect($selectedA['connection_id'])->not->toBe($resultB['connection_id'])
                ->and($resultA['outgoing_dispatch_ids'])->toBe([])
                ->and($state($id))->toBe($afterOtherWorker);

            if ($mutation === 'retry') {
                expect($resultB['outgoing_dispatch_ids'])->toBe([$id])
                    ->and($eventCount($id, 'retry_dispatched'))->toBe(1);
            } elseif ($mutation === 'complete') {
                expect($state($id)['overall_status'])->toBe('completed')
                    ->and($eventCount($id, 'retry_dispatched'))->toBe(0);
            } else {
                expect($resultA['summary']['recovered'])->toBe(0)
                    ->and($resultA['summary']['skipped'])->toBe(1)
                    ->and($resultA['summary']['messages'][0]['reason'])->toBe('not_stale')
                    ->and($eventCount($id, 'stale_lock_recovered'))->toBe(0)
                    ->and($eventCount($id, 'orphaned_dispatch_claim_recovered'))->toBe(0);
            }

            $evidence['scenarios'][$scenario] = ['selected' => $selectedA, 'workers' => [$resultA, $resultB], 'state_before_release' => $afterOtherWorker, 'final_state' => $state($id)];
        }

        // Scenario 7: pause after the successful claim has committed but before
        // queue dispatch. Verify compensation both with and without a new owner.
        foreach ([
            ['retry-dispatch-failure-restores-claim', 'retry', 'retry', false],
            ['retry-dispatch-failure-keeps-new-owner', 'retry', 'retry', true],
            ['recovery-dispatch-failure-restores-claim', 'processing', 'recover', false],
            ['recovery-dispatch-failure-keeps-new-owner', 'processing', 'recover', true],
        ] as [$scenario, $kind, $operation, $completedByOtherWorker]) {
            $id = $seed($kind);
            $before = $state($id);
            $a = $start($scenario.'-a', $operation, ['fail_dispatch' => true]);
            $claimed = operationalConcurrencyAwait($directory, $scenario.'-a', 'claimed', $a);
            $claimedState = $state($id);
            expect($claimedState['overall_status'])->toBe('waiting_to_send')
                ->and($claimedState['locked_by'])->toStartWith('dispatch-claim:');
            $resultB = null;

            if ($completedByOtherWorker) {
                $b = $start($scenario.'-b', 'complete', ['message_id' => $id]);
                $resultB = operationalConcurrencyFinish($directory, $scenario.'-b', $b);
                $expected = $state($id);
            } else {
                $expected = $before;
            }

            touch($directory.'/'.$scenario.'-a.release-dispatch');
            $resultA = operationalConcurrencyFinish($directory, $scenario.'-a', $a);
            expect($resultA['outgoing_dispatch_ids'])->toBe([])
                ->and($state($id))->toBe($expected)
                ->and($eventCount($id, $operation === 'retry' ? 'retry_dispatch_failed' : 'stale_lock_recovery_dispatch_failed'))->toBe(1);

            if ($operation === 'retry') {
                expect($eventCount($id, 'retry_dispatched'))->toBe(0);
            } else {
                // Recovery records the successful claim before queue dispatch,
                // then records its existing failure event on compensation.
                expect($eventCount($id, 'stale_lock_recovered'))->toBe(1)
                    ->and($resultA['summary']['recovered'])->toBe(0)
                    ->and($resultA['summary']['dispatched'])->toBe(0)
                    ->and($resultA['summary']['failed'])->toBe(1);
            }

            $evidence['scenarios'][$scenario] = ['claimed' => $claimed, 'claimed_state' => $claimedState, 'workers' => array_values(array_filter([$resultA, $resultB])), 'final_state' => $state($id)];
        }

        expect($evidence['scenarios'])->toHaveCount(11);
        $evidencePath = getenv('TALKTO_OPERATIONAL_CONCURRENCY_EVIDENCE_PATH');

        if (is_string($evidencePath) && $evidencePath !== '') {
            file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        }
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        DB::purge('operational_concurrency');
        $admin->exec("DROP DATABASE `{$database}`");

        // Only known files directly inside the random test-owned directory are
        // removed. No recursive cleanup or application path is involved.
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

function operationalConcurrencyAwait(string $directory, string $worker, string $marker, Process $process): array
{
    $deadline = microtime(true) + 15;
    $path = $directory.'/'.$worker.'.'.$marker.'.json';

    while (! is_file($path)) {
        if (! $process->isRunning()) {
            throw new RuntimeException('Worker stopped before '.$marker.': '.$process->getErrorOutput());
        }

        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out awaiting '.$worker.'.'.$marker);
        }

        usleep(10000);
        clearstatcache();
    }

    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function operationalConcurrencyFinish(string $directory, string $worker, Process $process): array
{
    $process->wait();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    $result = json_decode(file_get_contents($directory.'/'.$worker.'.result.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($result['error'] ?? null)->toBeNull();

    return $result;
}

function operationalConcurrencyLockWait(PDO $admin, int $waiting, int $blocking, Process $process): array
{
    $sql = 'SELECT r.trx_mysql_thread_id AS waiting_connection_id, b.trx_mysql_thread_id AS blocking_connection_id FROM performance_schema.data_lock_waits w JOIN information_schema.innodb_trx r ON r.trx_id = w.REQUESTING_ENGINE_TRANSACTION_ID JOIN information_schema.innodb_trx b ON b.trx_id = w.BLOCKING_ENGINE_TRANSACTION_ID WHERE r.trx_mysql_thread_id = ? AND b.trx_mysql_thread_id = ?';
    $statement = $admin->prepare($sql);
    $deadline = microtime(true) + 8;

    do {
        $statement->execute([$waiting, $blocking]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            return ['waiting_connection_id' => (int) $row['waiting_connection_id'], 'blocking_connection_id' => (int) $row['blocking_connection_id'], 'source' => 'performance_schema.data_lock_waits joined to information_schema.innodb_trx', 'observed_at' => microtime(true)];
        }

        if (! $process->isRunning()) {
            throw new RuntimeException('Contending worker exited before a database lock wait was observed: '.$process->getErrorOutput());
        }

        usleep(20000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('No actual MySQL row-lock wait observed before the bounded timeout.');
}

function operationalConcurrencyAssertNarrow(array $selection, string $operation): void
{
    $sql = strtolower($selection['sql']);
    expect($sql)->toStartWith('select `talkto_messages`.`id` from `talkto_messages`')
        ->not->toContain('select *', 'payload', 'last_response', 'last_error')
        ->toContain($operation === 'retry' ? 'order by `next_retry_at` asc limit 10' : 'order by `locked_at` asc limit 10');
}
