<?php

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Services\TalktoStaleMessageRecoveryService;
use Mrezdev\LaravelTalkto\Support\TalktoMessageCandidates;
use Mrezdev\LaravelTalkto\Support\TalktoRetryDecision;
use Mrezdev\LaravelTalkto\Tests\TestCase;

uses(TestCase::class);

test('real mysql distinct scopes preserve narrow operational candidate selection', function (): void {
    if (getenv('TALKTO_MYSQL_ENABLED') !== '1') {
        throw new RuntimeException('Set TALKTO_MYSQL_ENABLED=1 for an isolated MySQL 8.x test server.');
    }

    $host = getenv('TALKTO_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('TALKTO_MYSQL_PORT') ?: 3306);
    $username = getenv('TALKTO_MYSQL_USER') ?: 'root';
    $password = getenv('TALKTO_MYSQL_PASSWORD') ?: '';
    $database = 'talkto_distinct_'.bin2hex(random_bytes(12));
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $version = (string) $admin->query('SELECT VERSION()')->fetchColumn();
    expect($version)->toMatch('/^8\./')->not->toContain('MariaDB');
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $evidence = ['mysql_version' => $version, 'php_version' => PHP_VERSION, 'laravel_version' => app()->version(),
        'initial_actual_service_failures' => [], 'cases' => [],
        'notes' => ['Synthetic owned database only; actual package migrations remain unchanged.',
            'Extra projection columns must be small base-table values functionally determined by the unique message key.',
            'The joined fixture duplicates only tag rows; every selected ordering value belongs to the uniquely identified base message.',
            'No arbitrary joined-column ordering, GROUP BY, raw expression, or wide-field fallback is claimed supported.',
            'Equal timestamps have no invented primary-key tie-breaker. Candidate identities are compared at the ordering boundary.']];

    try {
        $connection = ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $database,
            'username' => $username, 'password' => $password, 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00'];
        config(['database.connections.distinct_primary' => $connection, 'database.connections.distinct_secondary' => $connection,
            'talkto.database.connection' => 'distinct_primary', 'talkto.service' => 'base']);
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00', 'UTC'));
        $migrations = glob(__DIR__.'/../../database/migrations/*.php');
        sort($migrations);

        foreach ($migrations as $migration) {
            (require $migration)->up();
        }

        $db = DB::connection('distinct_primary');
        $db->statement('CREATE TABLE distinct_messages LIKE talkto_messages');
        $db->statement('ALTER TABLE distinct_messages ADD candidate_key VARCHAR(80) NOT NULL, MODIFY id BIGINT UNSIGNED NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (candidate_key), ADD UNIQUE KEY distinct_numeric_id (id)');
        $db->statement('CREATE TABLE distinct_scope_tags (talkto_message_id BIGINT UNSIGNED NOT NULL, label VARCHAR(20) NOT NULL)');
        $fixtures = [];

        for ($id = 1; $id <= 48; $id++) {
            $retry = $id <= 24;
            $direction = $id % 2 === 0 ? 'outgoing' : 'incoming';
            $orphan = $id % 4 < 2;
            $status = $retry ? 'failed_retryable' : ($direction === 'outgoing' ? ($orphan ? 'waiting_to_send' : 'sending') : ($orphan ? 'queued' : 'processing'));
            $date = Carbon::parse($retry ? '2026-10-28 09:00:00' : '2026-10-28 08:00:00')->addMinutes((int) floor(($id - 1) / 2))->format('Y-m-d H:i:s');
            $row = ['id' => $id, 'message_id' => 'distinct-'.$id, 'direction' => $direction,
                'source_service' => $direction === 'outgoing' ? 'base' : 'peer',
                'target_service' => $direction === 'incoming' ? 'base' : 'peer',
                'command' => 'domain.sync', 'business_key' => in_array($id, [1, 25], true) ? 'hidden' : 'visible',
                'overall_status' => in_array($id, [22, 46], true) ? 'completed' : $status,
                'transport_status' => $direction === 'outgoing' ? ($retry ? 'failed' : ($orphan ? 'pending' : 'sending')) : null,
                'destination_action_status' => $direction === 'incoming' ? ($retry ? 'failed_retryable' : ($orphan ? 'queued' : 'processing')) : null,
                'next_retry_at' => $retry ? ($id === 24 ? null : ($id === 23 ? '2026-10-29 12:00:00' : $date)) : null,
                'locked_at' => $retry || $id === 48 ? null : ($id === 47 ? '2026-10-28 11:59:00' : $date),
                'locked_by' => $retry ? null : ($orphan ? 'dispatch-claim:distinct-fixture' : 'worker:distinct-fixture'),
                'max_attempts' => 5, 'retry_count' => 1,
                'payload' => json_encode(['body' => str_repeat('x', in_array($id, [1, 25], true) ? (int) (2.4 * 1024 * 1024) : 1024)], JSON_THROW_ON_ERROR),
                'last_response' => str_repeat('r', 1024), 'created_at' => Carbon::parse('2026-10-27 10:00:00')->addSeconds($id)->format('Y-m-d H:i:s'),
                'updated_at' => '2026-10-28 09:00:00'];
            $db->table('talkto_messages')->insert($row);
            $row['candidate_key'] = $id === 1 ? '0' : 'raw-key-'.$id;
            $db->table('distinct_messages')->insert($row);
            $fixtures[] = $row;
            $db->table('distinct_scope_tags')->insert([['talkto_message_id' => $id, 'label' => 'a'], ['talkto_message_id' => $id, 'label' => 'b']]);
        }

        Queue::fake();
        app()->instance(TalktoRetryPolicy::class, new DqmdRetryPolicy);
        config(['talkto.models.message' => DqmdDistinctMessage::class]);

        // Before the fix, these actual configured-model service calls reproduce
        // 3065. Persist their generated SQL before the success assertion fails.
        foreach (['retry', 'recovery'] as $operation) {
            try {
                dqmdRunOperation($operation, 'all', 1);
            } catch (QueryException $exception) {
                $evidence['initial_actual_service_failures'][] = ['operation' => $operation,
                    'sql' => $exception->getSql(), 'bindings' => $exception->getBindings(),
                    'error' => $exception->errorInfo];
            }
        }

        dqmdWriteEvidence($evidence);
        expect($evidence['initial_actual_service_failures'])->toBe([]);

        $cases = [
            ['default_control', DqmdPlainMessage::class, 'talkto_messages', 'distinct_primary'],
            ['distinct', DqmdDistinctMessage::class, 'talkto_messages', 'distinct_primary'],
            ['filter', DqmdFilteredMessage::class, 'talkto_messages', 'distinct_primary'],
            ['safe_order', DqmdOrderedMessage::class, 'talkto_messages', 'distinct_primary'],
            ['safe_plain_order', DqmdPlainOrderedMessage::class, 'talkto_messages', 'distinct_primary'],
            ['custom_table_key_connection', DqmdCustomMessage::class, 'distinct_messages', 'distinct_secondary'],
            ['joined_duplicates', DqmdJoinedMessage::class, 'talkto_messages', 'distinct_primary'],
            ['empty', DqmdEmptyMessage::class, 'talkto_messages', 'distinct_primary'],
        ];

        foreach ($cases as [$case, $class, $table, $connectionName]) {
            config(['talkto.models.message' => $class, 'talkto.database.tables.messages' => $table, 'talkto.database.connection' => $connectionName]);
            $db = DB::connection($connectionName);
            $db->statement('SET SESSION sort_buffer_size = 1048576');

            foreach (['retry', 'recovery'] as $operation) {
                foreach (['all', 'incoming', 'outgoing'] as $direction) {
                    foreach ([1, 10] as $limit) {
                        DqmdPlainMessage::$hydrations = [];
                        DqmdPlainMessage::$summaryIds = [];
                        DqmdRetryPolicy::$observed = [];
                        $selectorHydrations = null;
                        $listening = true;
                        $db->listen(function (QueryExecuted $event) use (&$listening, &$selectorHydrations, $table, $connectionName): void {
                            if ($listening && $event->connectionName === $connectionName && str_starts_with($event->sql, 'select ')
                                && str_contains($event->sql, 'from `'.$table.'`') && str_contains($event->sql, 'order by')) {
                                $selectorHydrations = count(DqmdPlainMessage::$hydrations);
                                $listening = false;
                            }
                        });
                        $db->flushQueryLog();
                        $db->enableQueryLog();

                        try {
                            $summary = dqmdRunOperation($operation, $direction, $limit);
                            $queries = $db->getQueryLog();
                        } finally {
                            $listening = false;
                            $db->disableQueryLog();
                        }

                        $eligible = array_values(array_filter($fixtures, function (array $row) use ($operation, $direction, $case): bool {
                            if ($case === 'empty' || ($case === 'filter' && $row['business_key'] !== 'visible')
                                || ($direction !== 'all' && $row['direction'] !== $direction)) {
                                return false;
                            }

                            return $operation === 'retry'
                                ? $row['overall_status'] === 'failed_retryable' && $row['next_retry_at'] !== null && $row['next_retry_at'] < '2026-10-28 12:00:00'
                                : $row['locked_at'] !== null && $row['locked_at'] < '2026-10-28 11:30:00' && $row['overall_status'] !== 'completed';
                        }));
                        $expectedCount = min($limit, count($eligible));
                        $ids = $operation === 'retry' ? DqmdRetryPolicy::$observed : DqmdPlainMessage::$summaryIds;
                        expect($ids)->toHaveCount($expectedCount);
                        expect(array_values(array_unique($ids)))->toHaveCount($expectedCount);
                        expect(array_diff($ids, array_column($eligible, 'id')))->toBe([]);
                        expect($selectorHydrations)->toBe(0);
                        expect(DqmdPlainMessage::$hydrations)->toHaveCount($expectedCount);
                        $batchSize = (new ReflectionClass(TalktoMessageCandidates::class))->getConstant('BATCH_SIZE');
                        expect($queries)->toHaveCount(1 + (int) ceil($expectedCount / $batchSize));
                        $selector = $queries[0];
                        $sql = strtolower($selector['query']);
                        $key = $class === DqmdCustomMessage::class ? 'candidate_key' : 'id';
                        expect($sql)->toContain('`'.$table.'`.`'.$key.'`', 'limit '.$limit)
                            ->not->toContain('select *', 'payload', 'last_response', 'last_error');
                        $order = $operation === 'retry' ? 'next_retry_at' : 'locked_at';
                        expect($sql)->toContain('order by `'.$order.'` asc');

                        if ($case !== 'default_control') {
                            expect($sql)->toStartWith('select distinct ');
                            $projection = 'select distinct `'.$table.'`.`'.$key.'`, `'.$table.'`.`'.$order.'`';

                            if (in_array($case, ['safe_order', 'safe_plain_order'], true)) {
                                $projection .= ', `'.$table.'`.`created_at`';
                            }

                            expect(explode(' from ', $sql)[0])->toBe($projection);
                        } else {
                            expect(explode(' from ', $sql)[0])->toBe('select `'.$table.'`.`id`');
                        }

                        if (in_array($case, ['safe_order', 'safe_plain_order'], true)) {
                            expect($sql)->toContain($case === 'safe_order' ? ', `'.$table.'`.`created_at` desc' : ', `created_at` desc');
                        } else {
                            expect($sql)->not->toContain('asc,');
                        }

                        foreach (array_slice($queries, 1) as $query) {
                            expect($query['query'])->not->toContain('order by');
                        }

                        $byId = array_column($fixtures, null, 'id');
                        $dates = array_map(fn (int $id): string => $byId[$id][$order], $ids);
                        $sortedDates = $dates;
                        sort($sortedDates);
                        expect($dates)->toBe($sortedDates);
                        $eligibleDates = array_column($eligible, $order);
                        sort($eligibleDates);
                        expect($dates)->toBe(array_slice($eligibleDates, 0, $expectedCount));

                        if (in_array($case, ['safe_order', 'safe_plain_order'], true)) {
                            usort($eligible, fn (array $a, array $b): int => strcmp($a[$order], $b[$order]) ?: strcmp($b['created_at'], $a['created_at']));
                            expect($ids)->toBe(array_column(array_slice($eligible, 0, $expectedCount), 'id'));
                        }

                        foreach (DqmdPlainMessage::$hydrations as $hydration) {
                            expect($hydration['columns'])->toContain('payload', 'last_response');
                            expect($hydration['payload_body_bytes'])->toBe(in_array($hydration['numeric_id'], [1, 25], true) ? (int) (2.4 * 1024 * 1024) : 1024);

                            if ($case === 'custom_table_key_connection') {
                                expect($hydration['key'])->toBe($byId[$hydration['numeric_id']]['candidate_key']);
                            }
                        }

                        $plan = $db->select('EXPLAIN '.$selector['query'], $selector['bindings']);
                        $planJson = (array) $db->selectOne('EXPLAIN FORMAT=JSON '.$selector['query'], $selector['bindings']);
                        $legacySql = preg_replace('/^select distinct .+? from /i', 'select distinct `'.$table.'`.`'.$key.'` from ', $selector['query'], 1);
                        $legacyError = null;
                        $legacyExplain = null;
                        $legacyExplainError = null;

                        if ($case !== 'default_control') {
                            try {
                                $db->select($legacySql, $selector['bindings']);
                            } catch (QueryException $exception) {
                                $legacyError = $exception->errorInfo;
                            }

                            try {
                                $legacyExplain = $db->select('EXPLAIN '.$legacySql, $selector['bindings']);
                            } catch (QueryException $exception) {
                                $legacyExplainError = $exception->errorInfo;
                            }
                        }

                        $evidence['cases'][] = ['case' => $case, 'operation' => $operation, 'direction' => $direction, 'limit' => $limit,
                            'model' => $class, 'connection_name' => $connectionName, 'table' => $table, 'primary_key' => $key,
                            'candidate_count' => $expectedCount, 'consumed_numeric_ids' => $ids,
                            'hydrated_raw_keys' => array_column(DqmdPlainMessage::$hydrations, 'key'),
                            'hydrations_before_selector_returned' => $selectorHydrations, 'summary' => $summary,
                            'queries' => $queries, 'explain' => array_map(fn (object $row): array => (array) $row, $plan),
                            'explain_json' => json_decode((string) reset($planJson), true, flags: JSON_THROW_ON_ERROR),
                            'legacy_key_only_sql' => $legacySql, 'legacy_error' => $legacyError,
                            'legacy_explain' => $legacyExplain, 'legacy_explain_error' => $legacyExplainError];
                    }
                }
            }
        }

        // Ordering by one-to-many joined values is not functionally dependent
        // on a message key. A raw payload expression is also outside the small
        // scalar-column contract. Never broaden these projections to hide it.
        config(['talkto.database.tables.messages' => 'talkto_messages', 'talkto.database.connection' => 'distinct_primary']);
        $evidence['unsupported_scope_boundaries'] = [];

        foreach ([DqmdForeignOrderedMessage::class, DqmdPayloadOrderedMessage::class] as $class) {
            config(['talkto.models.message' => $class]);
            DqmdPlainMessage::$hydrations = [];
            $error = null;

            try {
                dqmdRunOperation('retry', 'all', 10);
            } catch (QueryException $exception) {
                $error = ['sql' => $exception->getSql(), 'bindings' => $exception->getBindings(), 'error' => $exception->errorInfo];
            }

            expect($error)->not->toBeNull();
            expect($error['error'][1])->toBe(3065);
            expect(DqmdPlainMessage::$hydrations)->toBe([]);
            expect(explode(' from ', $error['sql'])[0])->not->toContain('payload', 'last_response', 'last_error', '*', 'distinct_scope_tags');
            $evidence['unsupported_scope_boundaries'][] = ['model' => $class, ...$error];
        }

        Queue::assertNothingPushed();
        dqmdWriteEvidence($evidence);
    } finally {
        $this->travelBack();

        try {
            DB::purge('distinct_primary');
            DB::purge('distinct_secondary');
        } finally {
            $admin->exec("DROP DATABASE `{$database}`");
        }
    }
});

function dqmdRunOperation(string $operation, string $direction, int $limit): array
{
    if ($operation === 'retry') {
        $exit = Artisan::call('talkto:retry-failed', ['--direction' => $direction, '--limit' => $limit, '--dry-run' => true]);

        return ['exit' => $exit, 'output' => trim(Artisan::output())];
    }

    return app(TalktoStaleMessageRecoveryService::class)->recover($direction === 'all' ? null : $direction, 30, $limit, true);
}

function dqmdWriteEvidence(array $evidence): void
{
    $path = getenv('TALKTO_DISTINCT_MYSQL_EVIDENCE_PATH');

    if (is_string($path) && $path !== '') {
        file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    }
}

class DqmdPlainMessage extends TalktoMessage
{
    public static array $hydrations = [];

    public static array $summaryIds = [];

    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        static::$hydrations[] = ['columns' => array_keys($attributes), 'key' => $attributes[$this->getKeyName()],
            'numeric_id' => (int) $attributes['id'],
            'payload_body_bytes' => strlen(json_decode($attributes['payload'], true, flags: JSON_THROW_ON_ERROR)['body'])];

        return parent::newFromBuilder($attributes, $connection);
    }

    public function getIdAttribute(mixed $value): int
    {
        static::$summaryIds[] = (int) $value;

        return (int) $value;
    }
}

class DqmdDistinctMessage extends DqmdPlainMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('distinct_candidates', fn (Builder $query) => $query->distinct());
    }
}

class DqmdFilteredMessage extends DqmdDistinctMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('visible_candidates', fn (Builder $query) => $query->where($query->getModel()->qualifyColumn('business_key'), 'visible'));
    }
}

class DqmdOrderedMessage extends DqmdDistinctMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('safe_order', fn (Builder $query) => $query->orderByDesc($query->getModel()->qualifyColumn('created_at')));
    }
}

class DqmdCustomMessage extends DqmdDistinctMessage
{
    protected $primaryKey = 'candidate_key';

    protected $keyType = 'string';

    public $incrementing = false;

    public function getCandidateKeyAttribute(mixed $value): string
    {
        return 'accessor:'.$value;
    }
}

class DqmdPlainOrderedMessage extends DqmdDistinctMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('safe_plain_order', fn (Builder $query) => $query->orderByDesc('created_at'));
    }
}

class DqmdJoinedMessage extends DqmdDistinctMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('duplicate_tags', function (Builder $query): void {
            $query->join('distinct_scope_tags', $query->getModel()->qualifyColumn('id'), '=', 'distinct_scope_tags.talkto_message_id')
                ->select($query->getModel()->getTable().'.*');
        });
    }
}

class DqmdForeignOrderedMessage extends DqmdJoinedMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('foreign_order', fn (Builder $query) => $query->orderBy('distinct_scope_tags.label'));
    }
}

class DqmdPayloadOrderedMessage extends DqmdDistinctMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('raw_payload_order', fn (Builder $query) => $query->orderByRaw('JSON_LENGTH(payload) ASC'));
    }
}

class DqmdEmptyMessage extends DqmdDistinctMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('empty_candidates', fn (Builder $query) => $query->where('business_key', 'does-not-exist'));
    }
}

class DqmdRetryPolicy extends TalktoRetryPolicy
{
    public static array $observed = [];

    public function decisionFor(TalktoMessage $message, array $context = []): TalktoRetryDecision
    {
        static::$observed[] = (int) $message->getRawOriginal('id');

        return parent::decisionFor($message, $context);
    }
}
