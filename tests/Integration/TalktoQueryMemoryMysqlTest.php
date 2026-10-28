<?php

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Services\TalktoCallbackStatusInspector;
use Mrezdev\LaravelTalkto\Services\TalktoStaleMessageRecoveryService;
use Mrezdev\LaravelTalkto\Services\TalktoTraceReporter;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelMessageFilters;
use Mrezdev\LaravelTalkto\Tests\TestCase;

uses(TestCase::class);

test('mysql query audit preserves dead letter winners and measures large row query plans', function (): void {
    if (getenv('TALKTO_MYSQL_ENABLED') !== '1') {
        throw new RuntimeException('Set TALKTO_MYSQL_ENABLED=1 and TALKTO_MYSQL_HOST/PORT/USER/PASSWORD for a disposable MySQL 8.x server with CREATE DATABASE permission.');
    }

    $host = getenv('TALKTO_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('TALKTO_MYSQL_PORT') ?: 3306);
    $username = getenv('TALKTO_MYSQL_USER') ?: 'root';
    $password = getenv('TALKTO_MYSQL_PASSWORD') ?: '';
    // No configurable database name: CREATE must succeed before any migration
    // or cleanup runs. Existing application databases are never selected.
    $database = 'talkto_query_audit_'.bin2hex(random_bytes(12));
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $version = (string) $admin->query('SELECT VERSION()')->fetchColumn();
    expect($version)->toMatch('/^8\./')->not->toContain('MariaDB');
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    try {
        config([
            'database.connections.talkto_query_audit' => [
                'driver' => 'mysql',
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'timezone' => '+00:00',
            ],
            'talkto.database.connection' => 'talkto_query_audit',
            'talkto.service' => 'base',
        ]);
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00', 'UTC'));
        $migrations = glob(__DIR__.'/../../database/migrations/*.php');
        sort($migrations);

        foreach ($migrations as $migration) {
            (require $migration)->up();
        }

        $db = DB::connection('talkto_query_audit');
        $sizes = [1024, 64 * 1024, 256 * 1024, 1024 * 1024, (int) (2.4 * 1024 * 1024)];
        $payloads = array_map(fn (int $size): string => json_encode([
            'visible' => str_repeat('x', $size), 'token' => 'synthetic-private-value',
        ], JSON_THROW_ON_ERROR), $sizes);
        $db->table('talkto_messages')->insert([
            'id' => 1,
            'message_id' => 'audit-anchor',
            'correlation_id' => 'audit-correlation',
            'direction' => 'outgoing',
            'source_service' => 'base',
            'target_service' => 'website',
            'command' => 'domain.sync',
            'overall_status' => 'destination_received',
            'destination_receive_status' => 'received',
            'payload' => $payloads[4],
            'last_response' => str_repeat('r', 60 * 1024),
            'created_at' => '2026-10-28 11:00:00',
            'updated_at' => '2026-10-28 11:00:00',
        ]);
        $message = TalktoMessage::query()->whereKey(1)->firstOrFail();
        $panel = app(TalktoPanelMessageQuery::class);
        $inspector = app(TalktoCallbackStatusInspector::class);
        $originalSql = 'select * from `talkto_dead_letters` where (`talkto_message_id` = ? or `message_id` = ?) order by `created_at` desc, `id` desc limit 1';
        $originalInspectorSql = 'select * from `talkto_dead_letters` where (`talkto_message_id` = ? or `message_id` = ?) order by `id` desc limit 1';
        $evidence = [
            'mysql_version' => $version,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'fixture_payload_value_sizes_bytes' => $sizes,
            'dead_letter_identifiers_unique' => array_map(fn (object $row): array => (array) $row, $db->select('SHOW INDEX FROM talkto_dead_letters')),
            'notes' => [
                'Synthetic fixtures only. The two stock nonnull unique identifiers permit at most two matching dead letters.',
                'Result JSON bytes approximate serialized returned data; they are not exact protocol/network bytes.',
                'Session Bytes_sent deltas include measurement traffic; session counters are indicators, not exact per-query wire size.',
                'PHP allocator usage/peak is process-wide and includes earlier fixtures, casts and JSON measurement; EXPLAIN does not measure memory.',
                'Unchanged risky queries are measured, including failures, without requiring them to succeed under a constrained sort buffer.',
            ],
            'stages' => [],
            'representative_reads' => [],
        ];
        $inserted = 0;

        foreach ([0, 2, 100, 1000] as $totalRows) {
            for ($index = $inserted; $index < $totalRows; $index++) {
                $db->table('talkto_dead_letters')->insert([
                    'talkto_message_id' => $index === 0 ? 1 : null,
                    'message_id' => $index === 1 ? 'audit-anchor' : 'audit-dead-letter-'.$index,
                    'direction' => $index % 2 === 0 ? 'outgoing' : 'incoming',
                    'source' => $index % 3 === 0 ? 'base' : 'other',
                    'target' => 'website',
                    'command' => 'domain.sync',
                    'payload' => $index < 2 ? $payloads[4] : $payloads[$index < 7 ? $index - 2 : 0],
                    'headers' => json_encode(['X-Test' => 'synthetic'], JSON_THROW_ON_ERROR),
                    'failure_reason' => str_repeat('e', $index < 2 ? 60 * 1024 : 128),
                    'status' => $index % 2 === 0 ? 'open' : 'failed_reprocess',
                    'failed_status' => 'failed_final',
                    // The two matching rows deliberately tie; id DESC must win.
                    'created_at' => $index < 2 ? '2026-10-28 11:10:00' : ($index % 7 === 0 ? null : '2026-10-27 10:00:00'),
                    'updated_at' => '2026-10-28 11:10:00',
                ]);
            }

            $inserted = $totalRows;

            foreach ([32768, 1048576] as $sortBuffer) {
                $db->statement('SET SESSION sort_buffer_size = '.$sortBuffer);
                $db->flushQueryLog();
                $db->enableQueryLog();

                try {
                    $selected = $panel->deadLetterFor($message);
                    $queries = array_values(array_filter($db->getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from `talkto_dead_letters`')));
                } finally {
                    $db->disableQueryLog();
                }

                expect($selected?->getKey())->toBe($totalRows === 0 ? null : 2);
                expect($queries)->toHaveCount($totalRows === 0 ? 1 : 2);
                $ordered = strtolower(str_replace('`', '', $queries[0]['query']));
                expect(explode(' from ', $ordered)[0])->toBe('select talkto_dead_letters.id');
                expect($ordered)->toContain('order by created_at desc, id desc', 'limit 1')
                    ->not->toContain('payload', 'headers', 'failure_reason', 'select *');
                expect($queries[0]['bindings'])->toBe([1, 'audit-anchor']);

                if ($selected !== null) {
                    expect(strlen($selected->payload['visible']))->toBe($sizes[4]);
                    expect($queries[1]['query'])->toStartWith('select * ')->not->toContain('order by');
                }

                $before = queryAuditMysqlMeasure($db, 'original_full_row_sorted', $originalSql, [1, 'audit-anchor']);

                if ($before['error'] === null) {
                    expect($before['returned_ids'])->toBe($totalRows === 0 ? [] : [2]);
                }

                $after = array_map(fn (array $query): array => queryAuditMysqlMeasure($db, 'optimized_service_query', $query['query'], $query['bindings']), $queries);
                expect(array_column($after, 'error'))->each->toBeNull();
                $inspectorBefore = queryAuditMysqlMeasure($db, 'original_inspector_full_row_sorted', $originalInspectorSql, [1, 'audit-anchor']);
                $db->flushQueryLog();
                $db->enableQueryLog();

                try {
                    $summary = $inspector->inspect($message);
                    $inspectorQueries = array_values(array_filter($db->getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from `talkto_dead_letters`')));
                } finally {
                    $db->disableQueryLog();
                }

                expect($summary['dead_letter']['id'])->toBe($totalRows === 0 ? null : 2);
                expect($inspectorQueries)->toHaveCount($totalRows === 0 ? 1 : 2);
                $inspectorOrdered = strtolower(str_replace('`', '', $inspectorQueries[0]['query']));
                expect(explode(' from ', $inspectorOrdered)[0])->toBe('select talkto_dead_letters.id');
                expect($inspectorOrdered)->toContain('order by id desc limit 1')
                    ->not->toContain('payload', 'headers', 'created_at', 'select *');

                if ($totalRows !== 0) {
                    expect($inspectorQueries[1]['query'])->toStartWith('select * ')->not->toContain('order by');
                }

                $inspectorAfter = array_map(fn (array $query): array => queryAuditMysqlMeasure($db, 'optimized_inspector_service_query', $query['query'], $query['bindings']), $inspectorQueries);
                expect(array_column($inspectorAfter, 'error'))->each->toBeNull();
                $evidence['stages'][] = [
                    'total_dead_letters' => $totalRows,
                    'matching_dead_letters' => $totalRows === 0 ? 0 : 2,
                    'sort_buffer_size' => $sortBuffer,
                    'expected_winner_id' => $totalRows === 0 ? null : 2,
                    'before' => $before,
                    'after' => $after,
                    'inspector_before' => $inspectorBefore,
                    'inspector_after' => $inspectorAfter,
                ];
            }
        }

        // Failed sorts may omit filesort_summary. Execute both original shapes
        // successfully with a sufficient buffer so their actual sort mode and
        // tuple width can be compared with the key-only candidate queries.
        $db->statement('SET SESSION sort_buffer_size = 67108864');
        $originalPanelControl = queryAuditMysqlMeasure($db, 'original_panel_successful_sort_control', $originalSql, [1, 'audit-anchor']);
        $originalInspectorControl = queryAuditMysqlMeasure($db, 'original_inspector_successful_sort_control', $originalInspectorSql, [1, 'audit-anchor']);

        foreach ([$originalPanelControl, $originalInspectorControl] as $control) {
            expect($control['error'])->toBeNull();
            expect($control['returned_ids'])->toBe([2]);
        }

        $evidence['successful_original_sort_controls'] = [
            'total_dead_letters' => 1000,
            'matching_dead_letters' => 2,
            'sort_buffer_size' => 67108864,
            'original_panel' => $originalPanelControl,
            'original_inspector' => $originalInspectorControl,
            'panel_key_candidate' => queryAuditMysqlMeasure($db, 'panel_key_candidate_sort_control', $queries[0]['query'], $queries[0]['bindings']),
            'inspector_key_candidate' => queryAuditMysqlMeasure($db, 'inspector_key_candidate_sort_control', $inspectorQueries[0]['query'], $inspectorQueries[0]['bindings']),
        ];

        // The inspector has always chosen highest id; the panel has always
        // chosen latest created_at, then id. Keep those distinct contracts.
        $db->table('talkto_dead_letters')->where('id', 1)->update(['created_at' => '2026-10-28 11:50:00']);
        expect($inspector->inspect($message)['dead_letter']['id'])->toBe(2);
        expect($panel->deadLetterFor($message)->getKey())->toBe(1);
        $evidence['different_timestamp_ordering'] = ['inspector_winner_id' => 2, 'panel_winner_id' => 1];
        $db->table('talkto_dead_letters')->where('id', 1)->update(['created_at' => '2026-10-28 11:10:00']);

        // Representative message histories cover multiple services, directions,
        // statuses, equal/NULL dates, old/recent records and all payload sizes.
        for ($index = 2; $index <= 100; $index++) {
            $status = ['completed', 'failed_retryable', 'sending', 'processing'][$index % 4];
            $db->table('talkto_messages')->insert([
                'id' => $index,
                'message_id' => 'audit-message-'.$index,
                'correlation_id' => $index <= 6 ? 'audit-correlation' : 'other-correlation',
                'parent_message_id' => $index <= 6 ? 'audit-anchor' : null,
                'direction' => $index % 2 === 0 ? 'outgoing' : 'incoming',
                'source_service' => $index % 3 === 0 ? 'other' : 'base',
                'target_service' => 'website',
                'command' => 'domain.sync',
                'overall_status' => $status,
                'transport_status' => $status === 'sending' ? 'sending' : null,
                'destination_action_status' => $status === 'processing' ? 'processing' : null,
                'payload' => $payloads[$index <= 6 ? $index - 2 : 0],
                'next_retry_at' => $status === 'failed_retryable' ? '2026-10-28 10:00:00' : null,
                'locked_at' => in_array($status, ['sending', 'processing'], true) ? '2026-10-28 09:00:00' : null,
                'created_at' => $index % 7 === 0 ? null : '2026-10-28 11:00:00',
                'updated_at' => '2026-10-28 11:00:00',
            ]);
        }

        for ($index = 1; $index <= 12; $index++) {
            $db->table('talkto_events')->insert([
                'talkto_message_id' => 1,
                'message_id' => 'audit-anchor',
                'service_name' => 'base',
                'event_type' => $index % 2 === 0 ? 'result_callback_received' : 'result_callback_applied',
                'meta' => json_encode(['visible' => str_repeat('m', 64 * 1024), 'token' => 'synthetic-private-value'], JSON_THROW_ON_ERROR),
                'created_at' => '2026-10-28 11:20:00',
                'updated_at' => '2026-10-28 11:20:00',
            ]);
            $db->table('talkto_attempts')->insert([
                'talkto_message_id' => 1,
                'message_id' => 'audit-anchor',
                'stage' => 'transport',
                'attempt_no' => $index,
                'status' => 'failed',
                'error_message' => str_repeat('e', 60 * 1024),
                'response_excerpt' => str_repeat('r', 60 * 1024),
                'meta' => json_encode(['visible' => str_repeat('m', 64 * 1024)], JSON_THROW_ON_ERROR),
                'created_at' => '2026-10-28 11:20:00',
                'updated_at' => '2026-10-28 11:20:00',
            ]);
        }

        // Contract checks use a sufficient session buffer. Each captured SELECT
        // is then measured independently at 32KiB and1MiB, including failures.
        $db->statement('SET SESSION sort_buffer_size = 67108864');
        $db->flushQueryLog();
        $db->enableQueryLog();

        try {
            expect($panel->latest(5))->toHaveCount(5);
            expect($panel->paginate(TalktoPanelMessageFilters::fromArray([]), 5)->count())->toBe(5);
            expect($panel->attemptsFor($message)->pluck('id')->all())->toBe(range(12, 1));
            expect($panel->eventsFor($message)->pluck('id')->all())->toBe(range(12, 1));
            $trace = app(TalktoTraceReporter::class);
            $hidden = $trace->traceByMessageId('audit-anchor', 3, false)->toArray();
            $shown = $trace->traceByMessageId('audit-anchor', 3, true)->toArray();
            expect($hidden['anchor_message']['payload'])->toBe(['redacted' => true, 'keys' => array_keys($message->payload)]);
            expect(strlen($shown['anchor_message']['payload']['visible']))->toBe($sizes[4]);
            expect($shown['anchor_message']['payload']['token'])->toBe('[redacted]');
            expect($hidden['related_messages'])->toHaveCount(3);
            expect($hidden['attempts'])->toHaveCount(3);
            expect($hidden['events'])->toHaveCount(3);
            expect($hidden['truncated'])->toBeTrue();
            expect($trace->traceByMessageId('no-such-message')->toArray()['found'])->toBeFalse();
            $callback = app(TalktoCallbackStatusInspector::class)->inspect($message);
            expect($callback['state'])->toBe('callback_applied');
            expect(Artisan::call('talkto:retry-failed', ['--dry-run' => true, '--limit' => 5]))->toBe(0);
            $recovery = app(TalktoStaleMessageRecoveryService::class)->recover(null, 30, 5, true);
            expect($recovery['dry_run'])->toBeTrue();
            expect($recovery['dispatched'])->toBe(0);
            $representativeQueries = $db->getQueryLog();
        } finally {
            $db->disableQueryLog();
        }

        $uniqueQueries = [];

        foreach ($representativeQueries as $query) {
            if (str_starts_with(strtolower($query['query']), 'select ') && ! str_contains($query['query'], 'information_schema')) {
                $uniqueQueries[$query['query'].'|'.json_encode($query['bindings'], JSON_THROW_ON_ERROR)] = $query;
            }
        }

        foreach ([32768, 1048576] as $sortBuffer) {
            $db->statement('SET SESSION sort_buffer_size = '.$sortBuffer);

            foreach ($uniqueQueries as $query) {
                $evidence['representative_reads'][] = [
                    'sort_buffer_size' => $sortBuffer,
                    'measurement' => queryAuditMysqlMeasure($db, 'captured_service_select', $query['query'], $query['bindings']),
                ];
            }
        }

        $evidence['contract_checks'] = [
            'panel_latest_count' => 5,
            'panel_attempt_event_count_each' => 12,
            'trace_hidden_keys' => $hidden['anchor_message']['payload']['keys'],
            'trace_shown_visible_bytes' => strlen($shown['anchor_message']['payload']['visible']),
            'trace_sections_limit' => 3,
            'trace_truncated' => $hidden['truncated'],
            'callback_state' => $callback['state'],
            'recovery_dry_run_dispatched' => $recovery['dispatched'],
        ];
        // A second actual MySQL connection deletes the selected row immediately
        // after the candidate SELECT completes, before Eloquent fetches it.
        // This deterministic boundary needs no timing assumptions or sleeps.
        $other = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $removed = false;
        $db->listen(function (QueryExecuted $event) use ($other, &$removed): void {
            if (! $removed && $event->connectionName === 'talkto_query_audit'
                && str_contains($event->sql, 'from `talkto_dead_letters`')
                && str_contains($event->sql, 'order by `created_at` desc, `id` desc')) {
                $removed = true;
                expect($other->exec('DELETE FROM talkto_dead_letters WHERE id = 2'))->toBe(1);
            }
        });
        $db->flushQueryLog();
        $db->enableQueryLog();

        try {
            expect($panel->deadLetterFor($message))->toBeNull();
            expect($removed)->toBeTrue();
            $disappearanceQueries = array_values(array_filter($db->getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from `talkto_dead_letters`')));
            expect($disappearanceQueries)->toHaveCount(2);
            $evidence['selected_row_disappeared_between_reads'] = [
                'second_connection_deleted_rows' => 1,
                'returned_null' => true,
                'queries' => $disappearanceQueries,
            ];
        } finally {
            $db->disableQueryLog();
            $other = null;
        }

        $evidencePath = getenv('TALKTO_QUERY_AUDIT_EVIDENCE_PATH');

        if (is_string($evidencePath) && $evidencePath !== '') {
            file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        }
    } finally {
        $this->travelBack();

        try {
            DB::purge('talkto_query_audit');
        } finally {
            $admin->exec("DROP DATABASE `{$database}`");
        }
    }
});

function queryAuditMysqlMeasure(Connection $db, string $label, string $sql, array $bindings): array
{
    if (! str_starts_with(strtolower(ltrim($sql)), 'select ')) {
        throw new InvalidArgumentException('The query audit only measures read-only SELECT statements.');
    }

    $result = [
        'label' => $label, 'sql' => $sql, 'bindings' => $bindings,
        'explain' => [], 'explain_json' => null, 'optimizer_trace' => null,
        'explain_analyze' => null, 'explain_analyze_error' => null,
        'error' => null, 'returned_rows' => 0, 'returned_ids' => [],
        'selected_column_count' => null, 'returned_json_bytes_approximate' => null,
    ];
    $result['explain'] = array_map(fn (object $row): array => (array) $row, $db->select('EXPLAIN '.$sql, $bindings));
    $json = (array) $db->selectOne('EXPLAIN FORMAT=JSON '.$sql, $bindings);
    $result['explain_json'] = json_decode((string) reset($json), true, flags: JSON_THROW_ON_ERROR);
    $beforeStatus = queryAuditMysqlSessionStatus($db);
    $beforeMemory = memory_get_usage(true);
    $db->statement('SET SESSION optimizer_trace_max_mem_size = 1048576');
    $db->statement("SET SESSION optimizer_trace = 'enabled=on'");
    $started = hrtime(true);

    try {
        $rows = $db->select($sql, $bindings);
        $result['elapsed_ms'] = (hrtime(true) - $started) / 1000000;
        $result['php_allocated_bytes_after_select'] = memory_get_usage(true);
        $result['returned_rows'] = count($rows);
        $result['selected_column_count'] = $rows === [] ? null : count((array) $rows[0]);
        $result['returned_ids'] = array_values(array_filter(array_map(fn (object $row): mixed => $row->id ?? null, $rows), fn (mixed $id): bool => $id !== null));
        $result['returned_json_bytes_approximate'] = strlen(json_encode($rows, JSON_THROW_ON_ERROR));
        unset($rows);
    } catch (QueryException $exception) {
        $result['elapsed_ms'] = (hrtime(true) - $started) / 1000000;
        $result['error'] = [
            'sqlstate' => $exception->errorInfo[0] ?? (string) $exception->getCode(),
            'driver_code' => $exception->errorInfo[1] ?? null,
            'message' => $exception->errorInfo[2] ?? 'Query failed',
        ];
    }

    try {
        $trace = $db->selectOne('SELECT TRACE, MISSING_BYTES_BEYOND_MAX_MEM_SIZE, INSUFFICIENT_PRIVILEGES FROM INFORMATION_SCHEMA.OPTIMIZER_TRACE');
        $result['optimizer_trace'] = $trace === null ? null : [
            'trace' => json_decode($trace->TRACE, true, flags: JSON_THROW_ON_ERROR),
            'missing_bytes' => $trace->MISSING_BYTES_BEYOND_MAX_MEM_SIZE,
            'insufficient_privileges' => $trace->INSUFFICIENT_PRIVILEGES,
        ];
        $result['optimizer_filesort_summaries'] = queryAuditMysqlFilesortSummaries($result['optimizer_trace']['trace'] ?? []);
    } finally {
        $db->statement("SET SESSION optimizer_trace = 'enabled=off'");
    }

    $afterStatus = queryAuditMysqlSessionStatus($db);
    $result['session_status_delta'] = array_map(fn (int $value, string $key): int => $value - ($beforeStatus[$key] ?? 0), $afterStatus, array_keys($afterStatus));
    $result['session_status_delta'] = array_combine(array_keys($afterStatus), $result['session_status_delta']);
    $result['php_allocated_bytes_before'] = $beforeMemory;
    $result['php_process_peak_allocated_bytes'] = memory_get_peak_usage(true);

    if ($result['error'] === null) {
        try {
            // SELECT only, on the test-owned disposable database. This executes
            // the read again and reports actual iterator rows/loops/timings.
            $result['explain_analyze'] = array_map(fn (object $row): array => (array) $row, $db->select('EXPLAIN ANALYZE '.$sql, $bindings));
        } catch (QueryException $exception) {
            $result['explain_analyze_error'] = $exception->errorInfo[2] ?? 'EXPLAIN ANALYZE unavailable';
        }
    }

    return $result;
}

function queryAuditMysqlFilesortSummaries(array $trace): array
{
    $summaries = [];

    foreach ($trace as $key => $value) {
        if ($key === 'filesort_summary' && is_array($value)) {
            $summaries[] = $value;
        } elseif (is_array($value)) {
            array_push($summaries, ...queryAuditMysqlFilesortSummaries($value));
        }
    }

    return $summaries;
}

function queryAuditMysqlSessionStatus(Connection $db): array
{
    $values = [];

    foreach ($db->select("SHOW SESSION STATUS WHERE Variable_name IN ('Bytes_sent','Sort_rows','Sort_scan','Sort_range','Sort_merge_passes','Created_tmp_tables','Created_tmp_disk_tables','Handler_read_rnd_next')") as $row) {
        $values[$row->Variable_name] = (int) $row->Value;
    }

    return $values;
}
