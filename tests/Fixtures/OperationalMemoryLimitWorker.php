<?php

// Run only as a fresh CLI process with -d memory_limit=... . The parent owns
// every fixture database. Connection credentials arrive on stdin, never logs.
$memoryLimitStarted = hrtime(true);
$memoryLimitReserve = str_repeat('r', 65536);
$memoryLimitReported = false;
$memoryLimitResult = [
    'php_version' => PHP_VERSION,
    'memory_limit_at_process_start' => ini_get('memory_limit'),
    'pid' => getmypid(),
    'completed' => false,
    'phase' => 'autoload',
    'statement_count' => 0,
    'transaction_boundary_count' => 0,
    'candidate_selector_count' => 0,
    'materialization_select_count' => 0,
    'full_models_hydrated' => 0,
    'payload_body_bytes_cast' => 0,
    'accessor_calls' => 0,
    'dispatch_ids' => [],
    'sample_selects' => [],
];
register_shutdown_function(function () use (&$memoryLimitReserve, &$memoryLimitReported, &$memoryLimitResult, $memoryLimitStarted): void {
    if ($memoryLimitReported) {
        return;
    }

    $memoryLimitReserve = null;
    $error = error_get_last();
    $memoryLimitResult['fatal_error'] = $error;
    $memoryLimitResult['elapsed_ms'] = (hrtime(true) - $memoryLimitStarted) / 1000000;
    $memoryLimitResult['peak_used_bytes'] = memory_get_peak_usage(false);
    $memoryLimitResult['peak_allocated_bytes'] = memory_get_peak_usage(true);
    $memoryLimitResult['shutdown_reserve_released_bytes'] = 65536;
    echo json_encode($memoryLimitResult, JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
});

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoStaleMessageRecoveryService;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Mrezdev\LaravelTalkto\Support\TalktoMessageCandidates;
use Orchestra\Testbench\Foundation\Application;

class MemoryLimitProbeMessage extends TalktoMessage
{
    public function getMemoryProbeAttribute(): array
    {
        // Representative accessor work: decode native JSON, derive a digest,
        // and re-encode temporary data. No body or model is retained externally.
        $payload = $this->payload;
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        return ['bytes' => strlen((string) ($payload['body'] ?? '')), 'digest' => hash('sha256', $encoded)];
    }
}

try {
    $settings = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

    if (! preg_match('/^talkto_memory_limit_[a-f0-9]{24}$/', $settings['connection']['database'] ?? '')
        || ! in_array($settings['operation'] ?? null, ['retry', 'recovery'], true)
        || ! in_array($settings['count'] ?? null, [1, 10, 50, 200, 1000], true)) {
        throw new RuntimeException('This worker requires the parent-created memory-limit database and an allowed case.');
    }

    $source = file_get_contents(dirname(__DIR__, 2).'/src/Support/TalktoMessageCandidates.php');
    if (preg_match_all('/private const BATCH_SIZE = (\d+);/', $source, $matches) !== 1) {
        throw new RuntimeException('Expected exactly one current private batch-size constant.');
    }
    $nativeBatch = (int) $matches[1][0];
    $override = $settings['batch_override'] ?? null;
    $memoryLimitResult['helper_sha256'] = hash('sha256', $source);
    $memoryLimitResult['native_batch_size'] = $nativeBatch;
    $memoryLimitResult['batch_size'] = $override ?? $nativeBatch;
    $memoryLimitResult['test_only_batch_override'] = $override;

    if ($override !== null) {
        if (! in_array($override, [10, 5, 2, 1], true) || class_exists(TalktoMessageCandidates::class, false)) {
            throw new RuntimeException('Invalid batch experiment or helper was loaded before the guarded override.');
        }
        $source = preg_replace('/private const BATCH_SIZE = \d+;/', 'private const BATCH_SIZE = '.$override.';', $source, 1, $replacements);
        if ($replacements !== 1) {
            throw new RuntimeException('The test-only override must replace exactly one constant.');
        }
        eval(substr($source, strlen('<?php')));
    }
    unset($source);

    $memoryLimitResult['phase'] = 'bootstrap';
    $app = Application::create(options: ['extra' => ['providers' => [LaravelTalktoServiceProvider::class], 'dont-discover' => ['*']]]);
    config([
        'app.timezone' => 'UTC',
        'database.default' => 'memory_limit_mysql',
        'database.connections.memory_limit_mysql' => $settings['connection'],
        'talkto.database.connection' => 'memory_limit_mysql',
        'talkto.models.message' => MemoryLimitProbeMessage::class,
        'talkto.service' => 'base',
        'talkto.retry.enabled' => true,
        'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.incoming_enabled' => true,
        'talkto.retry.retryable_statuses' => ['failed_retryable'],
        'talkto.retry.jitter_seconds' => 0,
        'queue.default' => 'sync',
    ]);
    date_default_timezone_set('UTC');
    Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'UTC'));
    Queue::fake();
    $db = DB::connection('memory_limit_mysql');
    $memoryLimitResult['mysql_version'] = (string) $db->selectOne('SELECT VERSION() AS version')->version;
    $db->statement('SET SESSION sort_buffer_size = 1048576');
    class_exists(TalktoMessageCandidates::class);
    MemoryLimitProbeMessage::query()->limit(0)->get();
    $memoryLimitResult['laravel_version'] = $app->version();
    $memoryLimitResult['operation'] = $settings['operation'];
    $memoryLimitResult['requested_count'] = $settings['count'];
    $memoryLimitResult['profile'] = $settings['profile'];
    $memoryLimitResult['framework_bootstrap_used_bytes'] = memory_get_usage(false);
    $memoryLimitResult['framework_bootstrap_allocated_bytes'] = memory_get_usage(true);
    $memoryLimitResult['bootstrap_peak_used_bytes'] = memory_get_peak_usage(false);
    $memoryLimitResult['peak_was_reset'] = false;
    $memoryLimitResult['query_log_enabled'] = $db->logging();
    $memoryLimitResult['phase'] = 'operation';
    $operationStarted = hrtime(true);

    MemoryLimitProbeMessage::retrieved(function (MemoryLimitProbeMessage $message) use (&$memoryLimitResult): void {
        $memoryLimitResult['full_models_hydrated']++;
        $payload = $message->payload;
        $memoryLimitResult['payload_body_bytes_cast'] += strlen((string) ($payload['body'] ?? ''));
        $probe = $message->memory_probe;
        $memoryLimitResult['accessor_calls']++;
        if (strlen($probe['digest']) !== 64 || $probe['bytes'] !== strlen((string) ($payload['body'] ?? ''))) {
            throw new RuntimeException('The complete native model payload/accessor result changed.');
        }
    });
    DB::listen(function (QueryExecuted $query) use (&$memoryLimitResult): void {
        $memoryLimitResult['statement_count']++;
        $sql = strtolower($query->sql);
        if (! str_starts_with($sql, 'select ') || ! str_contains($sql, 'from `talkto_messages`')) {
            return;
        }
        if (str_contains($sql, 'order by')) {
            $memoryLimitResult['candidate_selector_count']++;
            if (str_contains(substr($sql, 0, strpos($sql, ' from ')), '*')
                || str_contains($sql, 'payload') || str_contains($sql, 'last_response') || str_contains($sql, 'last_error')) {
                throw new RuntimeException('A wide candidate-sorting query returned.');
            }
        } elseif (str_contains($sql, ' in (')) {
            $memoryLimitResult['materialization_select_count']++;
        }
        if (count($memoryLimitResult['sample_selects']) < 3) {
            $memoryLimitResult['sample_selects'][] = $query->sql;
        }
    });
    foreach ([TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class] as $eventClass) {
        Event::listen($eventClass, function () use (&$memoryLimitResult): void {
            $memoryLimitResult['transaction_boundary_count']++;
        });
    }
    TalktoDispatchTestHooks::set('dispatch.before_queue', function (array $context) use (&$memoryLimitResult): void {
        $memoryLimitResult['dispatch_ids'][] = $context['message_db_id'];
    });

    if ($settings['operation'] === 'retry') {
        $exit = Artisan::call('talkto:retry-failed', ['--limit' => $settings['count']]);
        $memoryLimitResult['summary'] = ['exit' => $exit, 'output' => trim(Artisan::output())];
    } else {
        $summary = app(TalktoStaleMessageRecoveryService::class)->recover(null, 30, $settings['count'], false);
        $memoryLimitResult['summary'] = array_diff_key($summary, ['messages' => true]);
        $memoryLimitResult['summary_message_count'] = count($summary['messages']);
    }
    $memoryLimitResult['operation_elapsed_ms'] = (hrtime(true) - $operationStarted) / 1000000;
    $memoryLimitResult['statement_count_during_operation'] = $memoryLimitResult['statement_count'];
    $memoryLimitResult['phase'] = 'verification';
    $memoryLimitResult['jobs_prepared'] = Queue::pushed(SendTalktoMessage::class)->count() + Queue::pushed(ProcessIncomingTalktoMessage::class)->count();
    $memoryLimitResult['persisted_events'] = $db->table('talkto_events')->count();
    $memoryLimitResult['persisted_claims'] = $db->table('talkto_messages')->where('locked_by', 'like', 'dispatch-claim:%')->count();
    $memoryLimitResult['elapsed_ms'] = (hrtime(true) - $memoryLimitStarted) / 1000000;
    $memoryLimitResult['peak_used_bytes'] = memory_get_peak_usage(false);
    $memoryLimitResult['peak_allocated_bytes'] = memory_get_peak_usage(true);
    $memoryLimitResult['completed'] = true;
    $memoryLimitResult['phase'] = 'complete';
    $memoryLimitReported = true;
    echo json_encode($memoryLimitResult, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    $memoryLimitResult['exception'] = ['class' => $exception::class, 'message' => mb_substr($exception->getMessage(), 0, 2000)];
    fwrite(STDERR, $exception::class.': '.mb_substr($exception->getMessage(), 0, 2000).PHP_EOL);
    exit(1);
}
