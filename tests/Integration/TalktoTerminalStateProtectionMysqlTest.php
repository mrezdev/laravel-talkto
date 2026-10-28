<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Pipelines\SendOutgoingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

test('real mysql callback and transport races preserve terminal state and attempt history', function (): void {
    if (getenv('TALKTO_MYSQL_ENABLED') !== '1') {
        throw new RuntimeException('Set TALKTO_MYSQL_ENABLED=1 for an isolated MySQL 8.x server with CREATE DATABASE permission.');
    }
    $host = getenv('TALKTO_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('TALKTO_MYSQL_PORT') ?: 3306);
    $username = getenv('TALKTO_MYSQL_USER') ?: 'root';
    $password = getenv('TALKTO_MYSQL_PASSWORD') ?: '';
    $database = 'talkto_terminal_race_'.bin2hex(random_bytes(12));
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $version = (string) $admin->query('SELECT VERSION()')->fetchColumn();
    expect($version)->toMatch('/^8\./')->not->toContain('MariaDB');
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $directory = sys_get_temp_dir().'/talkto-terminal-barriers-'.bin2hex(random_bytes(12));
    $processes = [];
    $evidence = ['mysql_version' => $version, 'php_version' => PHP_VERSION, 'laravel_version' => app()->version(),
        'pipeline_sha256' => hash_file('sha256', (new ReflectionClass(SendOutgoingTalktoMessagePipeline::class))->getFileName()),
        'method' => 'Actual SendTalktoMessage/pipeline and signed v2 callback receiver in independent PHP application processes; controlled HTTP client returns synthetic errors. Existing FOR UPDATE locks overlap and are observed in performance_schema, with no production test hooks.',
        'scenarios' => []];
    $evidencePath = getenv('TALKTO_TERMINAL_MYSQL_EVIDENCE_PATH');
    try {
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create owned terminal-state barriers.');
        }
        $connection = ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $database,
            'username' => $username, 'password' => $password, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true, 'timezone' => '+00:00'];
        config(['database.connections.terminal_parent' => $connection, 'talkto.database.connection' => 'terminal_parent']);
        $migrations = glob(__DIR__.'/../../database/migrations/*.php');
        sort($migrations);
        foreach ($migrations as $migration) {
            (require $migration)->up();
        }
        $db = DB::connection('terminal_parent');
        $start = function (string $worker, string $operation, int $id, string $failure, bool $pause = false) use ($directory, $connection, &$processes): Process {
            $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/TerminalStateMysqlWorker.php'], dirname(__DIR__, 2));
            $process->setInput(json_encode(['worker' => $worker, 'operation' => $operation, 'connection' => $connection,
                'barrier_directory' => $directory, 'message_id' => $id, 'failure' => $failure, 'pause_after_lock' => $pause], JSON_THROW_ON_ERROR));
            $process->setTimeout(40);
            $process->start();
            $processes[$worker] = $process;

            return $process;
        };
        $seed = function (string $name) use ($db): int {
            foreach (['talkto_events', 'talkto_attempts', 'talkto_dead_letters', 'talkto_nonces', 'talkto_messages'] as $table) {
                $db->table($table)->delete();
            }
            $payload = ['fixture' => 'terminal-state-race', 'value' => $name];

            return (int) $db->table('talkto_messages')->insertGetId([
                'message_id' => 'original-'.$name, 'direction' => 'outgoing', 'source_service' => 'source-service',
                'target_service' => 'target-service', 'command' => 'domain.command', 'overall_status' => 'waiting_to_send',
                'transport_status' => 'pending', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload), 'retry_count' => 0, 'attempts' => 0,
                'max_attempts' => 5, 'last_http_status' => 202, 'last_response' => 'Earlier safe response.',
                'created_at' => '2026-10-28 11:00:00', 'updated_at' => '2026-10-28 11:00:00',
            ]);
        };
        $state = fn (int $id): array => (array) $db->table('talkto_messages')->where('id', $id)->first([
            'id', 'overall_status', 'destination_action_status', 'transport_status', 'completed_at', 'failed_at',
            'last_error', 'last_http_status', 'last_response', 'next_retry_at', 'next_attempt_at', 'retry_count',
            'locked_at', 'locked_by', 'attempts', 'updated_at', 'destination_receive_status', 'sent_at',
        ]);
        $records = function (int $id) use ($db): array {
            return ['attempts' => $db->table('talkto_attempts')->where('talkto_message_id', $id)->get()->map(fn ($row) => (array) $row)->all(),
                'events' => $db->table('talkto_events')->where('talkto_message_id', $id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
                'dead_letters' => $db->table('talkto_dead_letters')->where('talkto_message_id', $id)->count()];
        };

        // Both workers really contend on the same row. The waiter cannot read
        // its authoritative state until the holder's existing transaction ends.
        foreach (['500', '503', 'timeout'] as $failure) {
            foreach (['callback-first', 'failure-first'] as $order) {
                foreach (range(1, 3) as $iteration) {
                    $name = $order.'-'.$failure.'-'.$iteration;
                    $id = $seed($name);
                    $senderName = $name.'-sender';
                    $callbackName = $name.'-callback';
                    $sender = $start($senderName, 'send', $id, $failure, $order === 'failure-first');
                    $http = terminalRaceAwait($directory, $senderName, 'http-ready', $sender);

                    if ($order === 'callback-first') {
                        $callback = $start($callbackName, 'callback', $id, $failure, true);
                        $holder = terminalRaceAwait($directory, $callbackName, 'locked', $callback);
                        touch($directory.'/'.$senderName.'.release-http');
                        $waiter = terminalRaceAwait($directory, $senderName, 'lock-attempt', $sender);
                        $proof = terminalRaceLockWait($admin, $waiter['connection_id'], $holder['connection_id'], $sender);
                        touch($directory.'/'.$callbackName.'.release-lock');
                    } else {
                        touch($directory.'/'.$senderName.'.release-http');
                        $holder = terminalRaceAwait($directory, $senderName, 'locked', $sender);
                        $callback = $start($callbackName, 'callback', $id, $failure);
                        $waiter = terminalRaceAwait($directory, $callbackName, 'lock-attempt', $callback);
                        $proof = terminalRaceLockWait($admin, $waiter['connection_id'], $holder['connection_id'], $callback);
                        touch($directory.'/'.$senderName.'.release-lock');
                    }
                    $callbackResult = terminalRaceFinish($directory, $callbackName, $callback);
                    $senderResult = terminalRaceFinish($directory, $senderName, $sender);
                    $history = $records($id);
                    $final = $state($id);
                    $evidence['scenarios'][$name] = ['order' => $order, 'failure' => $failure, 'iteration' => $iteration,
                        'lock_wait' => $proof, 'workers' => [$senderResult, $callbackResult], 'final_state' => $final, 'history' => $history];
                    if (is_string($evidencePath) && $evidencePath !== '') {
                        file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
                    }
                    expect($http['connection_id'])->not->toBe($callbackResult['connection_id'])
                        ->and($callbackResult['callback_result'])->toMatchArray(['accepted' => true, 'status' => 'applied'])
                        ->and($senderResult['http_calls'])->toBe(1)
                        ->and($final)->toBe($callbackResult['terminal_state_inside_transaction'])
                        ->and($final['overall_status'])->toBe('completed')
                        ->and($final['destination_action_status'])->toBe('succeeded')
                        ->and($history['attempts'])->toHaveCount(1)
                        ->and($history['dead_letters'])->toBe(0);
                    $types = array_column($history['events'], 'event_type');
                    expect(array_count_values($types)['message_sending_started'] ?? 0)->toBe(1)
                        ->and(array_count_values($types)['result_callback_applied'] ?? 0)->toBe(1);
                    $attempt = $history['attempts'][0];
                    expect($attempt['http_status'])->toBe($failure === 'timeout' ? null : (int) $failure)
                        ->and($attempt['error_class'])->toBe($failure === 'timeout' ? ConnectionException::class : 'http_error')
                        ->and($attempt['error_message'])->not->toBeEmpty();

                    if ($order === 'callback-first') {
                        expect($attempt['status'])->toBe('failed')
                            ->and(json_decode($attempt['meta'], true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['response_ignored' => true, 'reason' => 'message_already_terminal', 'current_status' => 'completed'])
                            ->and($final['retry_count'])->toBe(0)
                            ->and($final['next_retry_at'])->toBeNull()
                            ->and($final['next_attempt_at'])->toBeNull()
                            ->and(array_count_values($types)['message_send_response_ignored'] ?? 0)->toBe(1)
                            ->and($types)->not->toContain('message_send_failed', 'retry_scheduled', 'retry_exhausted', 'retry_not_scheduled', 'dead_lettered');
                    } else {
                        // Existing callback semantics retain the prior schedule
                        // and transport diagnostics while completing the message.
                        expect($attempt['status'])->toBe('failed_retryable')
                            ->and($final['retry_count'])->toBe(1)
                            ->and($final['next_retry_at'])->toBe('2026-10-28 12:00:10')
                            ->and($final['next_attempt_at'])->toBe('2026-10-28 12:00:10')
                            ->and(array_count_values($types)['retry_scheduled'] ?? 0)->toBe(1)
                            ->and(array_count_values($types)['message_send_failed'] ?? 0)->toBe(1)
                            ->and($types)->not->toContain('message_send_response_ignored', 'dead_lettered');
                    }
                }
            }
        }

        // The second queue job executes after callback completion while the
        // first job is still inside HTTP. It must neither send nor add attempts.
        foreach (['500', '503', 'timeout'] as $failure) {
            $name = 'duplicate-job-'.$failure;
            $id = $seed($name);
            $sender = $start($name.'-sender', 'send', $id, $failure);
            terminalRaceAwait($directory, $name.'-sender', 'http-ready', $sender);
            $callback = $start($name.'-callback', 'callback', $id, $failure);
            $callbackResult = terminalRaceFinish($directory, $name.'-callback', $callback);
            $completed = $state($id);
            $duplicate = $start($name.'-duplicate', 'duplicate', $id, $failure);
            $duplicateResult = terminalRaceFinish($directory, $name.'-duplicate', $duplicate);
            touch($directory.'/'.$name.'-sender.release-http');
            $senderResult = terminalRaceFinish($directory, $name.'-sender', $sender);
            $history = $records($id);
            $evidence['scenarios'][$name] = ['order' => 'callback-duplicate-delayed-failure', 'failure' => $failure,
                'workers' => [$senderResult, $callbackResult, $duplicateResult], 'completed_before_duplicate' => $completed,
                'final_state' => $state($id), 'history' => $history];
            expect($state($id))->toBe($completed)
                ->and($duplicateResult['http_calls'])->toBe(0)
                ->and($history['attempts'])->toHaveCount(1)
                ->and($history['dead_letters'])->toBe(0)
                ->and(array_column($history['events'], 'event_type'))->not->toContain('retry_scheduled', 'message_send_failed');
        }
        expect($evidence['scenarios'])->toHaveCount(21);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        if (is_string($evidencePath) && $evidencePath !== '') {
            file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        }
        DB::purge('terminal_parent');
        $admin->exec("DROP DATABASE `{$database}`");
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

function terminalRaceAwait(string $directory, string $worker, string $marker, Process $process): array
{
    $path = $directory.'/'.$worker.'.'.$marker.'.json';
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (! $process->isRunning()) {
            throw new RuntimeException('Worker stopped before '.$marker.': '.$process->getErrorOutput().' '.$process->getOutput());
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out awaiting '.$worker.'.'.$marker);
        }
        usleep(10000);
        clearstatcache();
    }

    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function terminalRaceFinish(string $directory, string $worker, Process $process): array
{
    $process->wait();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    $result = json_decode(file_get_contents($directory.'/'.$worker.'.result.json'), true, flags: JSON_THROW_ON_ERROR);
    $result['process_exit_code'] = $process->getExitCode();
    $result['process_stderr'] = $process->getErrorOutput();
    expect($result['error'] ?? null)->toBeNull();

    return $result;
}

function terminalRaceLockWait(PDO $admin, int $waiting, int $blocking, Process $process): array
{
    $statement = $admin->prepare('SELECT r.trx_mysql_thread_id AS waiting_connection_id, b.trx_mysql_thread_id AS blocking_connection_id FROM performance_schema.data_lock_waits w JOIN information_schema.innodb_trx r ON r.trx_id = w.REQUESTING_ENGINE_TRANSACTION_ID JOIN information_schema.innodb_trx b ON b.trx_id = w.BLOCKING_ENGINE_TRANSACTION_ID WHERE r.trx_mysql_thread_id = ? AND b.trx_mysql_thread_id = ?');
    $deadline = microtime(true) + 8;
    do {
        $statement->execute([$waiting, $blocking]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return ['waiting_connection_id' => (int) $row['waiting_connection_id'], 'blocking_connection_id' => (int) $row['blocking_connection_id'],
                'source' => 'performance_schema.data_lock_waits joined to information_schema.innodb_trx', 'observed_at' => microtime(true)];
        }
        if (! $process->isRunning()) {
            throw new RuntimeException('Waiter exited before an actual row lock wait: '.$process->getErrorOutput());
        }
        usleep(20000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('No actual MySQL row-lock wait observed within eight seconds.');
}
