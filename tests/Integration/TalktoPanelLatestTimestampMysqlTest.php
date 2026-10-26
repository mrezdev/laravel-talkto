<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionRegistry;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelConnection;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelConnectionHealth;
use Mrezdev\LaravelTalkto\Tests\TestCase;

uses(TestCase::class);

test('mysql health timestamps aggregate large payloads without full rows or filesort', function (): void {
    if (getenv('TALKTO_MYSQL_ENABLED') !== '1') {
        throw new RuntimeException('Set TALKTO_MYSQL_ENABLED=1 and TALKTO_MYSQL_HOST/PORT/USER/PASSWORD for a disposable MySQL 8.x server with CREATE DATABASE permission.');
    }

    $host = getenv('TALKTO_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('TALKTO_MYSQL_PORT') ?: 3306);
    $username = getenv('TALKTO_MYSQL_USER') ?: 'root';
    $password = getenv('TALKTO_MYSQL_PASSWORD') ?: '';
    // There is deliberately no configurable database name. Only a database that
    // this test successfully creates may be migrated, written to, or dropped.
    $database = 'talkto_timestamp_max_'.bin2hex(random_bytes(12));
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $version = (string) $admin->query('SELECT VERSION()')->fetchColumn();

    expect($version)->toMatch('/^8\./')->not->toContain('MariaDB');

    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    try {
        config([
            'database.connections.talkto_timestamp_mysql' => [
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
            'talkto.database.connection' => 'talkto_timestamp_mysql',
            'talkto.service' => 'base',
            'talkto.retry.retryable_statuses' => ['failed_retryable', 'waiting_custom'],
            'talkto.outgoing.website' => [
                'url' => 'https://website.test',
                'secret' => 'integration-test-only',
            ],
            'talkto.outgoing.empty-service' => [
                'url' => 'https://empty.test',
                'secret' => 'integration-test-only',
            ],
            'talkto.incoming.website' => [
                'secret' => 'integration-test-only',
                'allowed_commands' => ['domain.sync' => ['driver' => 'handler']],
            ],
        ]);
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
        // Apply the real package migrations without Testbench registering a
        // later rollback against the database this test owns and drops below.
        $migrations = glob(__DIR__.'/../../database/migrations/*.php');
        sort($migrations);

        foreach ($migrations as $migration) {
            (require $migration)->up();
        }

        $db = DB::connection('talkto_timestamp_mysql');
        $db->statement('SET SESSION sort_buffer_size = 32768');
        $sortBufferSize = (int) $db->selectOne('SELECT @@SESSION.sort_buffer_size AS size')->size;
        expect($sortBufferSize)->toBe(32768);
        $payload = json_encode(['large' => str_repeat('x', 256 * 1024)], JSON_THROW_ON_ERROR);
        $rows = [
            ['completed', '11:00:00', '11:05:00', null, '11:50:00'],
            ['succeeded', '11:10:00', '11:15:00', null, '11:55:00'],
            ['failed', '11:20:00', null, '11:25:00', '11:57:00'],
            ['failed_final', '11:30:00', null, '11:35:00', '11:56:00'],
            ['failed_retryable', '11:40:00', null, '11:45:00', '11:54:00'],
            ['waiting_custom', '11:45:00', null, '11:49:00', '11:58:00'],
        ];

        foreach ($rows as $index => [$status, $created, $completed, $failed, $updated]) {
            $db->table('talkto_messages')->insert([
                'message_id' => 'mysql-outgoing-'.$index,
                'direction' => 'outgoing',
                'source_service' => 'base',
                'target_service' => 'website',
                'command' => 'domain.sync',
                'overall_status' => $status,
                'payload' => $payload,
                'last_response' => str_repeat('r', 60 * 1024),
                'created_at' => '2026-10-26 '.$created,
                'updated_at' => '2026-10-26 '.$updated,
                'completed_at' => $completed === null ? null : '2026-10-26 '.$completed,
                'failed_at' => $failed === null ? null : '2026-10-26 '.$failed,
            ]);
        }

        foreach ([
            ['incoming', 'website', 'base'],
            ['incoming', 'base', 'website'],
            ['outgoing', 'other-source', 'website'],
            ['outgoing', 'base', 'other-target'],
        ] as $index => [$direction, $source, $target]) {
            $db->table('talkto_messages')->insert([
                'message_id' => 'mysql-scoping-'.$index,
                'direction' => $direction,
                'source_service' => $source,
                'target_service' => $target,
                'command' => 'domain.sync',
                'overall_status' => 'succeeded',
                'payload' => $payload,
                'created_at' => '2026-10-26 11:59:00',
                'updated_at' => '2026-10-26 11:59:00',
                'completed_at' => '2026-10-26 11:59:00',
            ]);
        }

        $checker = app(TalktoPanelConnectionHealthChecker::class);
        $registry = app(TalktoPanelConnectionRegistry::class);
        $outgoing = $registry->outgoing()->firstWhere('service', 'website');
        $incoming = $registry->incoming()->firstWhere('service', 'website');
        $empty = $registry->outgoing()->firstWhere('service', 'empty-service');
        $originalSql = 'select * from `talkto_messages` where `direction` = ? and `source_service` = ? and `target_service` = ? and `overall_status` in (?, ?) and `completed_at` is not null order by `completed_at` desc, `id` desc limit 1';
        $originalBindings = ['outgoing', 'base', 'website', 'completed', 'succeeded'];
        $evidence = [
            'mysql_version' => $version,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'payload_bytes_per_message' => strlen($payload),
            'messages_inserted' => count($rows) + 4,
            'session_sort_buffer_size' => $sortBufferSize,
            // EXPLAIN the original shape for comparison without executing its
            // full-row sort under this deliberately constrained session.
            'original_completed_at_lookup' => [
                'query' => $originalSql,
                'bindings' => $originalBindings,
                'explain' => array_map(
                    fn (object $row): array => (array) $row,
                    $db->select('EXPLAIN '.$originalSql, $originalBindings),
                ),
            ],
            'phases' => [],
        ];

        $capture = function (TalktoPanelConnection $connection, int $expectedAggregates) use ($checker, $db, &$evidence): TalktoPanelConnectionHealth {
            $db->flushQueryLog();
            $db->enableQueryLog();

            try {
                $health = $checker->check($connection);
                $queries = array_values(array_filter(
                    $db->getQueryLog(),
                    fn (array $query): bool => str_contains(strtolower($query['query']), 'from `talkto_messages`'),
                ));
            } finally {
                $db->disableQueryLog();
            }

            // Check every message-table query so the previous SELECT * lookup
            // cannot evade the assertions by disappearing from a MAX-only filter.
            foreach ($queries as $query) {
                $sql = strtolower($query['query']);
                expect($sql)->not->toContain('select *', 'order by', 'payload', 'last_response', 'limit ');
                expect($sql)->toContain('`direction` = ?', '`source_service` = ?', '`target_service` = ?');
                expect(array_slice($query['bindings'], 0, 3))->toBe($connection->direction === 'outgoing'
                    ? ['outgoing', 'base', $connection->service]
                    : ['incoming', $connection->service, 'base']);
            }

            $timestamps = array_values(array_filter($queries, fn (array $query): bool => str_contains(strtolower($query['query']), 'max(')));
            expect($timestamps)->toHaveCount($expectedAggregates);

            foreach ($timestamps as &$query) {
                if (str_contains($query['query'], 'max(`failed_at`)')) {
                    expect($query['bindings'])->toBe([
                        ...array_slice($timestamps[0]['bindings'], 0, 3),
                        'failed_retryable', 'waiting_custom', 'failed_final', 'failed',
                    ]);
                }

                $query['explain'] = array_map(
                    fn (object $row): array => (array) $row,
                    $db->select('EXPLAIN '.$query['query'], $query['bindings']),
                );

                foreach ($query['explain'] as $row) {
                    expect(strtolower((string) ($row['Extra'] ?? '')))->not->toContain('filesort');
                }
            }
            unset($query);

            expect($timestamps[0]['query'])->toContain('max(`created_at`)');
            expect($timestamps[1]['bindings'])->toBe([
                ...array_slice($timestamps[0]['bindings'], 0, 3), 'completed', 'succeeded',
            ]);
            $evidence['phases'][] = [
                'direction' => $connection->direction,
                'service' => $connection->service,
                'health' => $health->toArray(),
                'timestamp_queries' => $timestamps,
            ];

            return $health;
        };

        $health = $capture($outgoing, 3);
        expect($health->lastMessageAt?->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:45:00')
            ->and($health->lastSuccessAt?->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:15:00')
            ->and($health->lastFailureAt?->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:49:00')
            ->and($health->recentMessages)->toBe(6)
            ->and($health->recentFailures)->toBe(4)
            ->and($health->retryBacklog)->toBe(2)
            ->and($health->toArray()['status'])->toBe('failing');

        $incomingHealth = $capture($incoming, 4);
        expect($incomingHealth->lastMessageAt?->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:59:00')
            ->and($incomingHealth->lastSuccessAt?->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:59:00')
            ->and($incomingHealth->lastFailureAt)->toBeNull()
            ->and($incomingHealth->recentMessages)->toBe(1);

        $db->table('talkto_messages')->where('direction', 'outgoing')->where('source_service', 'base')
            ->where('target_service', 'website')->update(['completed_at' => null, 'failed_at' => null]);
        $fallback = $capture($outgoing, 5);
        expect($fallback->lastSuccessAt?->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:55:00')
            ->and($fallback->lastFailureAt?->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:58:00');

        $emptyHealth = $capture($empty, 5);
        expect($emptyHealth->lastMessageAt)->toBeNull()
            ->and($emptyHealth->lastSuccessAt)->toBeNull()
            ->and($emptyHealth->lastFailureAt)->toBeNull();

        $evidencePath = getenv('TALKTO_MYSQL_EVIDENCE_PATH');

        if (is_string($evidencePath) && $evidencePath !== '') {
            file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        }
    } finally {
        $this->travelBack();
        DB::purge('talkto_timestamp_mysql');
        $admin->exec("DROP DATABASE `{$database}`");
    }
});
