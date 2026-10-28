<?php

// Standalone test process. It may connect only to the random database owned by
// TalktoOperationalConcurrencyMysqlTest; it never creates or drops databases.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoStaleMessageRecoveryService;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Orchestra\Testbench\Foundation\Application;

$options = json_decode(getenv('TALKTO_OPERATIONAL_WORKER_OPTIONS') ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$database = getenv('TALKTO_OPERATIONAL_DATABASE') ?: '';
$directory = $options['barrier_directory'] ?? '';
$worker = $options['worker'] ?? '';

if (getenv('TALKTO_MYSQL_ENABLED') !== '1'
    || ! preg_match('/^talkto_operational_concurrency_[a-f0-9]{24}$/', $database)
    || ! is_dir($directory)
    || ! preg_match('/^[a-z][a-z0-9-]*$/', $worker)) {
    throw new RuntimeException('This fixture requires the isolated concurrency test database and barrier directory.');
}

$write = function (string $name, array $value = []) use ($directory, $worker): void {
    $path = $directory.'/'.$worker.'.'.$name.'.json';
    $temporary = $path.'.writing';
    file_put_contents($temporary, json_encode($value, JSON_THROW_ON_ERROR));
    rename($temporary, $path);
};
$wait = function (string $name) use ($directory, $worker): void {
    $deadline = microtime(true) + 15;

    while (! is_file($directory.'/'.$worker.'.'.$name)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for test barrier '.$worker.'.'.$name);
        }

        usleep(10000);
        clearstatcache();
    }
};
$queries = [];
$result = ['worker' => $worker, 'pid' => getmypid(), 'operation' => $options['operation'] ?? null];

try {
    $app = Application::create(options: ['extra' => [
        'providers' => [LaravelTalktoServiceProvider::class],
        'dont-discover' => ['*'],
    ]]);
    config([
        'app.timezone' => 'UTC',
        'database.default' => 'operational_concurrency',
        'database.connections.operational_concurrency' => [
            'driver' => 'mysql',
            'host' => getenv('TALKTO_MYSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TALKTO_MYSQL_PORT') ?: 3306),
            'database' => $database,
            'username' => getenv('TALKTO_MYSQL_USER') ?: 'root',
            'password' => getenv('TALKTO_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'timezone' => '+00:00',
        ],
        'talkto.database.connection' => 'operational_concurrency',
        'talkto.service' => 'base',
        'talkto.retry.enabled' => true,
        'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.incoming_enabled' => true,
        'talkto.retry.max_attempts' => 5,
        'talkto.retry.backoff_seconds' => [10, 30, 60],
        'talkto.retry.jitter_seconds' => 0,
        'talkto.dead_letter.enabled' => true,
        'talkto.dead_letter.auto_store_on_final_failure' => true,
        'queue.default' => 'sync',
    ]);
    date_default_timezone_set('UTC');
    Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'UTC'));
    Bus::fake([SendTalktoMessage::class, ProcessIncomingTalktoMessage::class]);
    $db = DB::connection('operational_concurrency');
    $db->statement('SET SESSION innodb_lock_wait_timeout = 12');
    $db->statement('SET SESSION sort_buffer_size = 1048576');
    $connectionId = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $result['connection_id'] = $connectionId;
    $write('connected', ['connection_id' => $connectionId, 'pid' => getmypid(), 'time' => microtime(true)]);
    $selected = false;
    $lockAttempted = false;
    $locked = false;

    $db->beforeExecuting(function (string $sql) use (&$lockAttempted, $write, $connectionId): void {
        if (! $lockAttempted && str_contains(strtolower($sql), 'for update')) {
            $lockAttempted = true;
            $write('lock-attempt', ['connection_id' => $connectionId, 'sql' => $sql, 'time' => microtime(true)]);
        }
    });
    DB::listen(function (QueryExecuted $query) use (&$queries, &$selected, &$locked, $options, $write, $wait, $connectionId): void {
        if (! str_contains($query->sql, 'talkto_messages')) {
            return;
        }

        $bindings = array_map(fn (mixed $value): mixed => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s.uP') : $value, $query->bindings);
        $queries[] = ['sql' => $query->sql, 'bindings' => $bindings, 'elapsed_ms' => $query->time];
        $sql = strtolower($query->sql);

        if (! $selected && str_starts_with($sql, 'select ') && str_contains($sql, 'order by')
            && (str_contains($sql, '`next_retry_at` asc') || str_contains($sql, '`locked_at` asc'))) {
            $selected = true;
            $write('selected', ['connection_id' => $connectionId, 'sql' => $query->sql, 'bindings' => $bindings, 'time' => microtime(true)]);

            if ($options['pause_after_selection'] ?? false) {
                $wait('release-selection');
            }
        }

        if (! $locked && str_contains($sql, 'for update')) {
            $locked = true;
            $write('locked', ['connection_id' => $connectionId, 'sql' => $query->sql, 'time' => microtime(true)]);

            if ($options['pause_after_lock'] ?? false) {
                $wait('release-lock');
            }
        }
    });

    if ($options['fail_dispatch'] ?? false) {
        TalktoDispatchTestHooks::set('dispatch.before_queue', function (array $context) use ($write, $wait): void {
            $write('claimed', ['context' => $context, 'time' => microtime(true)]);
            $wait('release-dispatch');

            throw new RuntimeException('Synthetic operational dispatch failure.');
        });
    }

    $operation = $options['operation'];

    if ($operation === 'retry') {
        $result['exit_code'] = Artisan::call('talkto:retry-failed', ['--direction' => 'outgoing', '--limit' => 10]);
        $result['summary'] = trim(Artisan::output());
    } elseif ($operation === 'recover') {
        $result['summary'] = app(TalktoStaleMessageRecoveryService::class)->recover('outgoing', 10, 10, false);
    } elseif (in_array($operation, ['complete', 'refresh', 'replace-claim'], true)) {
        $result['summary'] = $db->transaction(function () use ($operation, $options): array {
            $message = TalktoMessage::query()->whereKey($options['message_id'])->lockForUpdate()->firstOrFail();
            $attributes = match ($operation) {
                'complete' => ['overall_status' => 'completed', 'transport_status' => 'completed', 'completed_at' => now(), 'next_retry_at' => null, 'next_attempt_at' => null, 'locked_at' => null, 'locked_by' => null],
                'refresh' => ['locked_at' => now(), 'locked_by' => 'sender:refreshed-worker'],
                'replace-claim' => ['locked_at' => now(), 'locked_by' => 'dispatch-claim:other-worker:fresh'],
            };
            $message->forceFill($attributes)->save();

            return ['changed' => array_keys($attributes)];
        });
    } else {
        throw new RuntimeException('Unknown isolated worker operation.');
    }

    $result['outgoing_dispatch_ids'] = Bus::dispatched(SendTalktoMessage::class)->map(fn (SendTalktoMessage $job): int => $job->talktoMessageId)->values()->all();
    $result['incoming_dispatch_ids'] = Bus::dispatched(ProcessIncomingTalktoMessage::class)->map(fn (ProcessIncomingTalktoMessage $job): int => $job->talktoMessageId)->values()->all();
    $result['queries'] = $queries;
    $result['finished_at'] = microtime(true);
    $write('result', $result);
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    $result['error'] = [
        'class' => $exception::class,
        'code' => (string) $exception->getCode(),
        'driver_code' => $exception instanceof QueryException ? ($exception->errorInfo[1] ?? null) : null,
        'message' => $exception instanceof QueryException ? ($exception->errorInfo[2] ?? 'Database query failed.') : $exception->getMessage(),
    ];
    $write('result', $result);
    fwrite(STDERR, json_encode($result['error'], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
} finally {
    TalktoDispatchTestHooks::reset();
    Carbon::setTestNow();
    if (isset($db)) {
        DB::purge('operational_concurrency');
    }
}
