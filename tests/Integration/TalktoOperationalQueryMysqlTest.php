<?php

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoStaleMessageRecoveryService;
use Mrezdev\LaravelTalkto\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

test('real mysql operational selectors avoid wide sorting and bound materialization memory', function (): void {
    if (getenv('TALKTO_MYSQL_ENABLED') !== '1') {
        throw new RuntimeException('Set TALKTO_MYSQL_ENABLED=1 for a disposable MySQL 8.x server with CREATE DATABASE permission.');
    }

    $host = getenv('TALKTO_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('TALKTO_MYSQL_PORT') ?: 3306);
    $username = getenv('TALKTO_MYSQL_USER') ?: 'root';
    $password = getenv('TALKTO_MYSQL_PASSWORD') ?: '';
    $database = 'talkto_operational_'.bin2hex(random_bytes(12));
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $version = (string) $admin->query('SELECT VERSION()')->fetchColumn();
    expect($version)->toMatch('/^8\./')->not->toContain('MariaDB');
    // Only a database successfully created here is ever migrated or dropped.
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    try {
        $connection = ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $database,
            'username' => $username, 'password' => $password, 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00'];
        config([
            'database.connections.operational_mysql' => $connection,
            'talkto.database.connection' => 'operational_mysql',
            'talkto.service' => 'base',
            'talkto.retry.retryable_statuses' => ['failed_retryable', 'retry_custom'],
            'talkto.retry.jitter_seconds' => 0,
        ]);
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00', 'UTC'));
        $migrations = glob(__DIR__.'/../../database/migrations/*.php');
        sort($migrations);

        foreach ($migrations as $migration) {
            (require $migration)->up();
        }

        $db = DB::connection('operational_mysql');
        Queue::fake();
        $sizes = [1024, 65536, 262144, 1048576, (int) (2.4 * 1024 * 1024)];
        $payloads = array_map(fn (int $bytes): string => json_encode(['body' => str_repeat('x', $bytes)], JSON_THROW_ON_ERROR), $sizes);

        for ($id = 1; $id <= 1050; $id++) {
            $direction = $id % 2 === 0 ? 'outgoing' : 'incoming';
            $status = $id <= 25 ? 'failed_retryable' : ['completed', 'succeeded', 'failed_retryable', 'failed_final', 'pending'][$id % 5];
            $retryAt = $status === 'failed_retryable' ? Carbon::parse('2026-10-28 01:00:00')->addSeconds($id)->format('Y-m-d H:i:s') : null;
            $transport = $direction === 'outgoing' ? 'failed' : null;
            $destination = $direction === 'incoming' ? 'failed_retryable' : null;
            $lockedAt = null;
            $lockedBy = null;

            if ($id >= 26 && $id <= 50) {
                $orphan = $id % 4 < 2;
                $status = $direction === 'outgoing' ? ($orphan ? 'waiting_to_send' : 'sending') : ($orphan ? 'queued' : 'processing');
                $transport = $direction === 'outgoing' ? ($orphan ? 'pending' : 'sending') : null;
                $destination = $direction === 'incoming' ? ($orphan ? 'queued' : 'processing') : null;
                $lockedAt = Carbon::parse('2026-10-28 01:00:00')->addSeconds($id)->format('Y-m-d H:i:s');
                $lockedBy = $orphan ? 'dispatch-claim:fixture-'.$id : 'processing-fixture-'.$id;
                $retryAt = null;
            }

            if ($id === 5) {
                $status = 'retry_custom';
            }

            if ($id === 9) {
                $direction = 'unsupported';
            }

            if ($id === 10) {
                $retryAt = '2026-10-29 12:00:00';
            }

            if ($id === 11) {
                $retryAt = null;
            }

            if ($id === 32) {
                $lockedAt = '2026-10-28 11:59:00';
            }

            if ($id === 33) {
                $lockedAt = null;
            }

            if ($id === 34) {
                $status = 'completed';
            }

            $foreign = $id % 7 === 0;
            $db->table('talkto_messages')->insert([
                'id' => $id, 'message_id' => 'operational-'.$id,
                'direction' => $direction,
                'source_service' => $direction === 'incoming' ? 'peer' : ($foreign ? 'other' : 'base'),
                'target_service' => $direction === 'incoming' ? ($foreign ? 'other' : 'base') : 'peer',
                'command' => 'domain.sync', 'overall_status' => $status,
                'transport_status' => $transport, 'destination_action_status' => $destination,
                'payload' => $payloads[$id <= 50 ? 4 : ($id <= 54 ? $id - 51 : 0)],
                'last_response' => $id <= 50 ? str_repeat('r', 60 * 1024) : null,
                'max_attempts' => 5, 'retry_count' => in_array($id, [8, 36], true) ? 5 : 1,
                'next_retry_at' => $retryAt, 'next_attempt_at' => $retryAt,
                'locked_at' => $lockedAt, 'locked_by' => $lockedBy,
                'created_at' => $id % 17 === 0 ? null : '2026-10-27 10:00:00',
                'updated_at' => '2026-10-27 10:00:00',
            ]);
        }

        // Equal timestamps intentionally have no secondary order contract.
        foreach ([1100, 1101] as $id) {
            $db->table('talkto_messages')->insert([
                'id' => $id, 'message_id' => 'operational-tie-'.$id, 'direction' => 'outgoing',
                'source_service' => 'base', 'target_service' => 'peer', 'command' => 'domain.sync',
                'overall_status' => 'failed_retryable', 'transport_status' => 'failed',
                'payload' => $payloads[0], 'max_attempts' => 5, 'retry_count' => 1,
                'next_retry_at' => '2026-10-28 00:00:00', 'next_attempt_at' => '2026-10-28 00:00:00',
                'created_at' => '2026-10-27 10:00:00', 'updated_at' => '2026-10-27 10:00:00',
            ]);
        }

        $evidence = ['mysql_version' => $version, 'php_version' => PHP_VERSION, 'laravel_version' => app()->version(),
            'fixture_rows' => 1052, 'large_2_4_mib_rows' => 50, 'payload_body_sizes_bytes' => $sizes,
            'notes' => [
                'All fixtures are synthetic; existing databases are never selected. Body sizes exclude JSON syntax.',
                'EXPLAIN ANALYZE executes SELECT only. Plans and single timing samples are fixture-specific.',
                'Sort diagnostics are optimizer_trace values; missing failure-path summaries remain unavailable.',
                'Fresh child processes measure PHP allocator usage, not RSS. Fixture generation occurs only in the parent.',
                'Normal-operation baselines reverse only the new selection call in test memory; claim/dispatch/business loops are the actual current source.',
                'Queue transport is faked in synthetic normal-operation workers; durable MySQL claims, writes and events are real.',
                'Equal timestamps preserve only the original single-column ordering contract; no stable tie order is asserted.',
            ], 'service_cases' => [], 'memory_benchmarks' => [], 'normal_operations' => []];
        $hydrated = [];
        TalktoMessage::retrieved(function (TalktoMessage $message) use (&$hydrated): void {
            $hydrated[] = ['id' => $message->getKey(), 'direction' => $message->direction,
                'next_retry_at' => $message->getRawOriginal('next_retry_at'), 'locked_at' => $message->getRawOriginal('locked_at'),
                'payload_json_bytes' => strlen((string) $message->getRawOriginal('payload'))];
        });

        foreach (['retry', 'recovery'] as $operation) {
            foreach (['all', 'incoming', 'outgoing'] as $direction) {
                foreach ([32768, 1048576] as $buffer) {
                    $db->statement('SET SESSION sort_buffer_size = '.$buffer);
                    $db->flushQueryLog();
                    $db->enableQueryLog();
                    $hydrated = [];

                    try {
                        if ($operation === 'retry') {
                            expect(Artisan::call('talkto:retry-failed', ['--direction' => $direction, '--limit' => 13, '--dry-run' => true]))->toBe(0);
                            $summary = ['output' => trim(Artisan::output())];
                            preg_match('/scanned=(\d+)/', $summary['output'], $matches);
                            $count = (int) $matches[1];
                        } else {
                            $summary = app(TalktoStaleMessageRecoveryService::class)->recover($direction === 'all' ? null : $direction, 30, 13, true);
                            $count = $summary['candidates'];
                        }

                        $queries = $db->getQueryLog();
                    } finally {
                        $db->disableQueryLog();
                    }

                    expect($count)->toBeGreaterThan(0)->toBeLessThanOrEqual(13);
                    expect($queries)->toHaveCount(1 + (int) ceil($count / 10));
                    expect($hydrated)->toHaveCount($count);
                    $selector = strtolower(str_replace('`', '', $queries[0]['query']));
                    expect(explode(' from ', $selector)[0])->toBe('select talkto_messages.id');
                    $order = $operation === 'retry' ? 'next_retry_at' : 'locked_at';
                    expect($selector)->toContain('order by '.$order.' asc limit 13')
                        ->not->toContain('payload', 'last_response', 'last_error', 'order by id', $order.' asc,');

                    foreach (array_slice($queries, 1) as $query) {
                        expect($query['query'])->toStartWith('select * ')->not->toContain('order by');
                    }

                    foreach ($hydrated as $row) {
                        expect($row['payload_json_bytes'])->toBeGreaterThan(1024);

                        if ($direction !== 'all') {
                            expect($row['direction'])->toBe($direction);
                        }
                    }

                    $oldSql = preg_replace('/^select .+? from /i', 'select * from ', $queries[0]['query'], 1);
                    $before = operationalMysqlMeasure($db, $oldSql, $queries[0]['bindings']);
                    $after = array_map(fn (array $query): array => operationalMysqlMeasure($db, $query['query'], $query['bindings']), $queries);
                    expect(array_column($after, 'error'))->each->toBeNull();
                    $db->statement('SET SESSION sort_buffer_size = 67108864');
                    $control = operationalMysqlMeasure($db, $oldSql, $queries[0]['bindings']);
                    expect($control['error'])->toBeNull();
                    expect($control['returned_rows'])->toBe($count);
                    $orderedValues = $control['ordering_values'][$order];
                    $sorted = $orderedValues;
                    sort($sorted);
                    expect($orderedValues)->toBe($sorted);
                    $evidence['service_cases'][] = ['operation' => $operation, 'direction' => $direction, 'sort_buffer_size' => $buffer,
                        'candidate_count' => $count, 'hydration_query_count' => count($queries) - 1,
                        'full_payload_json_bytes_hydrated' => array_sum(array_column($hydrated, 'payload_json_bytes')),
                        'summary' => $summary, 'before' => $before, 'after' => $after, 'successful_original_control_64_mib' => $control];
                }
            }
        }

        Queue::assertNothingPushed();

        foreach ([10, 50, 200] as $limit) {
            $strategies = [];

            foreach (['upfront_full_get', 'one_row', 'batch_ten'] as $strategy) {
                $run = operationalMysqlWorker(['connection' => $connection, 'strategy' => $strategy, 'operation' => 'materialization', 'limit' => $limit]);
                expect($run['candidate_ids_consumed'])->toBe(range(1, $limit));
                expect($run['full_models_hydrated'])->toBe($limit);
                $expectedQueries = match ($strategy) {
                    'upfront_full_get' => 1, 'one_row' => 1 + $limit, default => 1 + (int) ceil($limit / 10)
                };
                expect($run['statement_count_excluding_transaction_boundaries'])->toBe($expectedQueries);
                $strategies[$strategy] = $run;
            }

            expect($strategies['batch_ten']['raw_payload_json_bytes_consumed'])->toBe($strategies['upfront_full_get']['raw_payload_json_bytes_consumed']);
            expect($strategies['one_row']['cast_payload_body_bytes_consumed'])->toBe($strategies['batch_ten']['cast_payload_body_bytes_consumed']);
            $evidence['memory_benchmarks'][] = ['limit' => $limit, 'strategies' => $strategies];
        }

        $stateColumns = ['id', 'overall_status', 'transport_status', 'destination_action_status', 'retry_count',
            'next_retry_at', 'next_attempt_at', 'locked_at', 'locked_by', 'updated_at'];
        $initialStates = $db->table('talkto_messages')->where('id', '<=', 50)->orWhere('id', '>=', 1100)->get($stateColumns)->all();

        foreach (['retry', 'recovery'] as $operation) {
            $runs = [];

            foreach (['original_operation', 'optimized_operation'] as $strategy) {
                foreach ($initialStates as $state) {
                    $attributes = (array) $state;
                    unset($attributes['id']);
                    $db->table('talkto_messages')->where('id', $state->id)->update($attributes);
                }

                $db->table('talkto_events')->delete();
                $run = operationalMysqlWorker(['connection' => $connection, 'strategy' => $strategy, 'operation' => $operation, 'limit' => 5]);
                expect($run['baseline_source_call_replacements'])->toBe($strategy === 'original_operation' ? 1 : 0);
                $runs[$strategy] = $run;
            }

            foreach (['summary', 'jobs', 'states', 'events', 'claim_count', 'claims_created', 'event_count'] as $field) {
                expect($runs['optimized_operation'][$field])->toBe($runs['original_operation'][$field]);
            }

            expect($runs['optimized_operation']['jobs'])->not->toBeEmpty();
            expect($runs['optimized_operation']['claims_created'])->toBe(count($runs['optimized_operation']['jobs']));
            $evidence['normal_operations'][] = ['operation' => $operation, 'runs' => $runs];
        }

        $path = getenv('TALKTO_OPERATIONAL_MYSQL_EVIDENCE_PATH');

        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        }
    } finally {
        $this->travelBack();

        try {
            DB::purge('operational_mysql');
        } finally {
            $admin->exec("DROP DATABASE `{$database}`");
        }
    }
});

function operationalMysqlWorker(array $settings): array
{
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', __DIR__.'/../Fixtures/OperationalMemoryBenchmark.php'], dirname(__DIR__, 2));
    $process->setInput(json_encode($settings, JSON_THROW_ON_ERROR));
    $process->setTimeout(90);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException('Memory/operation worker failed: '.$process->getErrorOutput());
    }

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function operationalMysqlMeasure(Connection $db, string $sql, array $bindings): array
{
    if (! str_starts_with(strtolower(ltrim($sql)), 'select ')) {
        throw new InvalidArgumentException('Only read-only SELECT statements may be measured.');
    }

    $result = ['sql' => $sql, 'bindings' => $bindings, 'error' => null, 'returned_rows' => 0, 'selected_columns' => null,
        'returned_ids' => [], 'raw_payload_json_bytes' => 0, 'ordering_values' => ['next_retry_at' => [], 'locked_at' => []]];
    $result['explain'] = array_map(fn (object $row): array => (array) $row, $db->select('EXPLAIN '.$sql, $bindings));
    $json = (array) $db->selectOne('EXPLAIN FORMAT=JSON '.$sql, $bindings);
    $result['explain_json'] = json_decode((string) reset($json), true, flags: JSON_THROW_ON_ERROR);
    $db->statement('SET SESSION optimizer_trace_max_mem_size = 1048576');
    $db->statement("SET SESSION optimizer_trace = 'enabled=on'");
    $started = hrtime(true);

    try {
        $rows = $db->select($sql, $bindings);
        $result['elapsed_ms'] = (hrtime(true) - $started) / 1000000;
        $result['returned_rows'] = count($rows);
        $result['selected_columns'] = $rows === [] ? null : count((array) $rows[0]);

        foreach ($rows as $row) {
            $result['returned_ids'][] = $row->id;
            $result['raw_payload_json_bytes'] += isset($row->payload) ? strlen($row->payload) : 0;

            foreach (['next_retry_at', 'locked_at'] as $column) {
                $result['ordering_values'][$column][] = $row->{$column} ?? null;
            }
        }
        unset($rows);
    } catch (QueryException $exception) {
        $result['elapsed_ms'] = (hrtime(true) - $started) / 1000000;
        $result['error'] = ['sqlstate' => $exception->errorInfo[0] ?? null, 'driver_code' => $exception->errorInfo[1] ?? null,
            'message' => $exception->errorInfo[2] ?? 'Query failed'];
    }

    try {
        $trace = $db->selectOne('SELECT TRACE, MISSING_BYTES_BEYOND_MAX_MEM_SIZE FROM INFORMATION_SCHEMA.OPTIMIZER_TRACE');
        $result['optimizer_trace'] = $trace === null ? null : json_decode($trace->TRACE, true, flags: JSON_THROW_ON_ERROR);
        $result['trace_missing_bytes'] = $trace?->MISSING_BYTES_BEYOND_MAX_MEM_SIZE;
        $result['filesort_summaries'] = operationalMysqlSortSummaries($result['optimizer_trace'] ?? []);
    } finally {
        $db->statement("SET SESSION optimizer_trace = 'enabled=off'");
    }

    if ($result['error'] === null) {
        try {
            $result['explain_analyze'] = array_map(fn (object $row): array => (array) $row, $db->select('EXPLAIN ANALYZE '.$sql, $bindings));
        } catch (QueryException $exception) {
            $result['explain_analyze_error'] = $exception->errorInfo[2] ?? 'Unavailable';
        }
    }

    return $result;
}

function operationalMysqlSortSummaries(array $trace): array
{
    $summaries = [];

    foreach ($trace as $key => $value) {
        if ($key === 'filesort_summary' && is_array($value)) {
            $summaries[] = $value;
        } elseif (is_array($value)) {
            array_push($summaries, ...operationalMysqlSortSummaries($value));
        }
    }

    return $summaries;
}
