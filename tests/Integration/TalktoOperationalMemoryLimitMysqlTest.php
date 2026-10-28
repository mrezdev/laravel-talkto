<?php

use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

test('fresh constrained PHP workers measure operational memory budgets and batch tradeoffs', function (): void {
    if (getenv('TALKTO_MYSQL_ENABLED') !== '1') {
        throw new RuntimeException('Set TALKTO_MYSQL_ENABLED=1 for a disposable MySQL 8.x server with CREATE DATABASE permission.');
    }

    $host = getenv('TALKTO_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('TALKTO_MYSQL_PORT') ?: 3306);
    $username = getenv('TALKTO_MYSQL_USER') ?: 'root';
    $password = getenv('TALKTO_MYSQL_PASSWORD') ?: '';
    $database = 'talkto_memory_limit_'.bin2hex(random_bytes(12));
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $mysqlVersion = (string) $admin->query('SELECT VERSION()')->fetchColumn();
    expect($mysqlVersion)->toMatch('/^8\./')->not->toContain('MariaDB');
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $probeOnly = getenv('TALKTO_MEMORY_LIMIT_PROBE_ONLY') === '1';
    $evidencePath = getenv('TALKTO_OPERATIONAL_MEMORY_LIMIT_EVIDENCE_PATH');
    $evidence = [
        'mysql_version' => $mysqlVersion,
        'parent_php_version' => PHP_VERSION,
        'parent_laravel_version' => app()->version(),
        'probe_only' => $probeOnly,
        'fresh_php_limit_enforced_from_process_start' => true,
        'peak_reset' => false,
        'fixture_notes' => 'Synthetic parent-owned database; alternating directions; complete operational claims/events with Queue fake. Worker retrieved observer exercises native JSON casting and a temporary JSON/hash accessor without retaining bodies. Counts1000 use mostly64KiB rows and only periodic large rows.',
        'cases' => [],
        'native_failures' => [],
        'coverage_boundary' => 'Representative matrix, not every payload/count/limit Cartesian combination. Uniform sizes at10, mixed1/50/200/1000, uniform2.4MiB at50. Arbitrarily large rows, host observers retaining data, and unrelated application memory are not guaranteed safe.',
        'not_run_combinations' => ['Uniform1000x2.4MiB (approximately2.4GiB raw payload fixture) was intentionally not created.', 'Uniform payload/count/limit combinations outside the explicit case list were not run; mixed1000 cases represent the practical high-count workload.'],
    ];

    try {
        $connection = ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $database,
            'username' => $username, 'password' => $password, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true, 'timezone' => '+00:00'];
        config(['database.connections.memory_limit_parent' => $connection, 'talkto.database.connection' => 'memory_limit_parent']);
        $migrations = glob(__DIR__.'/../../database/migrations/*.php');
        sort($migrations);
        foreach ($migrations as $migration) {
            (require $migration)->up();
        }
        $db = DB::connection('memory_limit_parent');
        $sizes = ['64KiB' => 64 * 1024, '256KiB' => 256 * 1024, '1MiB' => 1024 * 1024, '2.4MiB' => (int) (2.4 * 1024 * 1024)];
        $payloads = [];
        foreach ($sizes as $profile => $size) {
            $payloads[$profile] = json_encode(['body' => str_repeat('x', $size), 'id' => 'synthetic'], JSON_THROW_ON_ERROR);
        }
        $seeded = null;
        $seed = function (string $profile, int $count) use ($db, $payloads, &$seeded): void {
            $db->table('talkto_events')->delete();
            $db->table('talkto_attempts')->delete();
            $db->table('talkto_dead_letters')->delete();
            if ($seeded === $profile.':'.$count) {
                return;
            }
            $db->table('talkto_messages')->delete();
            foreach (range(1, $count) as $id) {
                $selectedProfile = $profile === 'mixed'
                    ? match (($id - 1) % 100) {
                        0 => '2.4MiB', 1 => '1MiB', 2 => '256KiB', default => '64KiB'
                    }
                : $profile;
                $incoming = $id % 2 === 0;
                $db->table('talkto_messages')->insert([
                    'id' => $id, 'message_id' => 'memory-'.$id, 'direction' => $incoming ? 'incoming' : 'outgoing',
                    'source_service' => $incoming ? 'website' : 'base', 'target_service' => $incoming ? 'base' : 'website',
                    'command' => 'domain.sync', 'overall_status' => 'failed_retryable', 'payload' => $payloads[$selectedProfile],
                    'last_response' => str_repeat('r', 60 * 1024), 'last_error' => str_repeat('e', 16 * 1024),
                    'attempts' => 1, 'retry_count' => 1, 'max_attempts' => 5,
                    'created_at' => '2026-10-28 09:00:00', 'updated_at' => '2026-10-28 10:00:00',
                ]);
            }
            $seeded = $profile.':'.$count;
        };

        $cases = [];
        if ($probeOnly) {
            foreach (['128M', '256M', '512M'] as $memory) {
                foreach (['retry', 'recovery'] as $operation) {
                    $cases[] = ['profile' => '2.4MiB', 'count' => 50, 'memory' => $memory, 'operation' => $operation, 'override' => null];
                }
            }
        } else {
            foreach (array_keys($sizes) as $profile) {
                foreach (['128M', '256M', '512M'] as $memory) {
                    foreach (['retry', 'recovery'] as $operation) {
                        $cases[] = ['profile' => $profile, 'count' => 10, 'memory' => $memory, 'operation' => $operation, 'override' => null];
                    }
                }
            }
            foreach ([1, 50, 200, 1000] as $count) {
                foreach ($count === 1000 ? ['128M', '256M', '512M'] : ['128M', '256M'] as $memory) {
                    foreach (['retry', 'recovery'] as $operation) {
                        $cases[] = ['profile' => 'mixed', 'count' => $count, 'memory' => $memory, 'operation' => $operation, 'override' => null];
                    }
                }
            }
            foreach (['128M', '256M'] as $memory) {
                foreach (['retry', 'recovery'] as $operation) {
                    $cases[] = ['profile' => '2.4MiB', 'count' => 50, 'memory' => $memory, 'operation' => $operation, 'override' => null];
                }
            }
            // Guarded test-only constant overrides compare strategies in fresh
            // 512M controls. They never add a production option or edit a file.
            foreach ([10, 5, 2, 1] as $batch) {
                foreach (['retry', 'recovery'] as $operation) {
                    $cases[] = ['profile' => '2.4MiB', 'count' => 50, 'memory' => '512M', 'operation' => $operation, 'override' => $batch];
                }
            }
        }

        foreach ($cases as $case) {
            $seed($case['profile'], $case['count']);
            $retry = $case['operation'] === 'retry';
            $db->statement('UPDATE talkto_messages SET overall_status = '.($retry ? "'failed_retryable'" : "CASE direction WHEN 'incoming' THEN 'processing' ELSE 'sending' END")
                .', transport_status = '.($retry ? "'failed'" : "CASE direction WHEN 'outgoing' THEN 'sending' ELSE NULL END")
                .", destination_action_status = CASE direction WHEN 'incoming' THEN '".($retry ? 'failed_retryable' : 'processing')."' ELSE NULL END"
                .', retry_count = 1, attempts = 1, locked_at = '.($retry ? 'NULL' : "DATE_ADD('2026-10-28 10:00:00', INTERVAL id SECOND)")
                .', locked_by = '.($retry ? 'NULL' : "'worker:memory-fixture'")
                .", next_retry_at = DATE_ADD('2026-10-28 10:00:00', INTERVAL id SECOND), next_attempt_at = DATE_ADD('2026-10-28 10:00:00', INTERVAL id SECOND)");
            $settings = ['connection' => $connection, 'operation' => $case['operation'], 'count' => $case['count'], 'profile' => $case['profile'], 'batch_override' => $case['override']];
            $process = new Process([PHP_BINARY, '-d', 'memory_limit='.$case['memory'], __DIR__.'/../Fixtures/OperationalMemoryLimitWorker.php'], dirname(__DIR__, 2));
            $process->setInput(json_encode($settings, JSON_THROW_ON_ERROR));
            $process->setTimeout(90);
            $started = hrtime(true);
            $exit = $process->run();
            $stdout = $process->getOutput();
            $lines = array_values(array_filter(explode("\n", trim($stdout))));
            $metrics = json_decode($lines[array_key_last($lines)] ?? '', true);
            $oom = str_contains($process->getErrorOutput(), 'Allowed memory size') || str_contains($metrics['fatal_error']['message'] ?? '', 'Allowed memory size');
            $record = ['case' => $case, 'exit_code' => $exit, 'stderr' => $process->getErrorOutput(), 'wall_ms' => (hrtime(true) - $started) / 1000000, 'metrics' => $metrics, 'memory_exhaustion' => $oom];
            if ($exit !== 0) {
                $record['stdout'] = $stdout;
            }
            $evidence['cases'][] = $record;
            if ($case['override'] === null && ($exit !== 0 || ! ($metrics['completed'] ?? false))) {
                $evidence['native_failures'][] = $case;
            }
            if (is_string($evidencePath) && $evidencePath !== '') {
                file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR).PHP_EOL);
            }

            expect($metrics)->toBeArray()->and($metrics['memory_limit_at_process_start'])->toBe($case['memory']);
            if ($exit !== 0) {
                // A measured OOM baseline is evidence, not a suppressed success.
                // The complete native matrix must still pass before acceptance.
                expect($oom)->toBeTrue('Unexpected child failure: '.$process->getErrorOutput());

                continue;
            }
            expect($metrics['completed'])->toBeTrue()
                ->and($metrics['dispatch_ids'])->toBe(range(1, $case['count']))
                ->and($metrics['jobs_prepared'])->toBe($case['count'])
                ->and($metrics['persisted_events'])->toBe($case['count'])
                ->and($metrics['persisted_claims'])->toBe($case['count'])
                ->and($metrics['candidate_selector_count'])->toBe(1)
                ->and($metrics['materialization_select_count'])->toBe((int) ceil($case['count'] / $metrics['batch_size']))
                ->and($metrics['peak_was_reset'])->toBeFalse()
                ->and($metrics['query_log_enabled'])->toBeFalse();
        }

        $evidence['native_case_count'] = count(array_filter($cases, fn (array $case): bool => $case['override'] === null));
        $evidence['batch_comparison_case_count'] = count($cases) - $evidence['native_case_count'];
        $evidence['all_native_workers_completed'] = $evidence['native_failures'] === [];
        expect($evidence['native_failures'])->toBe([], 'The actual runtime policy must pass every required constrained-memory case.');
    } finally {
        if (is_string($evidencePath) && $evidencePath !== '') {
            file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR).PHP_EOL);
        }
        DB::purge('memory_limit_parent');
        $admin->exec("DROP DATABASE `{$database}`");
    }
});
