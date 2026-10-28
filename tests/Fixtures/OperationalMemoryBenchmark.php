<?php

// Standalone child process. Fixtures are populated by the parent test before
// this process starts; database credentials arrive on stdin and are never logged.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Carbon\Carbon;
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
use Mrezdev\LaravelTalkto\Support\TalktoMessageCandidates;
use Orchestra\Testbench\Foundation\Application;

try {
    $settings = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

    if (! preg_match('/^talkto_operational_[a-f0-9]{24}$/', $settings['connection']['database'] ?? '')) {
        throw new RuntimeException('Benchmark requires the parent-created disposable database.');
    }

    $strategy = $settings['strategy'];
    $operation = $settings['operation'] ?? 'materialization';
    $baselineReplacements = 0;

    if ($strategy === 'original_operation') {
        // Reuse the actual current business loop, reversing only the new
        // materialization call. This avoids a stale duplicate of claim logic.
        $path = $operation === 'retry'
            ? 'src/Console/Commands/RetryFailedTalktoMessagesCommand.php'
            : 'src/Services/TalktoStaleMessageRecoveryService.php';
        $needle = $operation === 'retry'
            ? 'TalktoMessageCandidates::select($query)'
            : 'TalktoMessageCandidates::select($this->candidateQuery($direction, $olderThanMinutes, $limit))';
        $replacement = $operation === 'retry'
            ? '$query->get()'
            : '$this->candidateQuery($direction, $olderThanMinutes, $limit)->get()';
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);

        if (substr_count($source, $needle) !== 1) {
            throw new RuntimeException('Expected exactly one candidate-selection call for baseline reversal.');
        }

        $source = str_replace($needle, $replacement, $source, $baselineReplacements);
        eval(substr($source, strlen('<?php')));
        unset($source);
    }

    $app = Application::create(options: ['extra' => [
        'providers' => [LaravelTalktoServiceProvider::class],
        'dont-discover' => ['*'],
    ]]);
    config([
        'database.default' => 'operational_benchmark',
        'database.connections.operational_benchmark' => $settings['connection'],
        'talkto.database.connection' => 'operational_benchmark',
        'talkto.service' => 'base',
        'talkto.retry.enabled' => true,
        'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.incoming_enabled' => true,
        'talkto.retry.retryable_statuses' => ['failed_retryable', 'retry_custom'],
        'talkto.retry.backoff_seconds' => [10, 30, 60],
        'talkto.retry.jitter_seconds' => 0,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'UTC'));
    $db = DB::connection('operational_benchmark');
    $db->statement('SET SESSION sort_buffer_size = 67108864');
    $db->select('SELECT 1');
    class_exists(TalktoMessageCandidates::class);
    TalktoMessage::query()->limit(0)->get();
    Queue::fake();
    $transactionBoundaries = 0;
    $hydratedModelCount = 0;
    $totalRetrievedPayloadBytes = 0;
    TalktoMessage::retrieved(function (TalktoMessage $message) use (&$hydratedModelCount, &$totalRetrievedPayloadBytes): void {
        $hydratedModelCount++;
        $totalRetrievedPayloadBytes += strlen((string) $message->getRawOriginal('payload'));
    });

    foreach ([TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class] as $eventClass) {
        Event::listen($eventClass, function () use (&$transactionBoundaries): void {
            $transactionBoundaries++;
        });
    }

    $db->flushQueryLog();
    $db->enableQueryLog();
    gc_collect_cycles();
    $beforeUsed = memory_get_usage(false);
    $beforeAllocated = memory_get_usage(true);
    $peakReset = function_exists('memory_reset_peak_usage');

    if ($peakReset) {
        memory_reset_peak_usage();
    }

    $started = hrtime(true);
    $ids = [];
    $payloadBytes = 0;
    $bodyBytes = 0;
    $summary = null;
    $limit = $settings['limit'];

    if ($operation === 'materialization') {
        // This isolates materialization memory on the same fixed key set. The
        // separate service measurements verify the real operational predicates.
        $query = TalktoMessage::query()->whereBetween('id', [1, $limit])->orderBy('id')->limit($limit);

        if ($strategy === 'upfront_full_get') {
            $items = $query->get();
        } elseif ($strategy === 'one_row') {
            $keyName = $query->getModel()->getQualifiedKeyName();
            $keys = (clone $query)->toBase()->select($keyName)->pluck($keyName)->all();
            $items = (function () use ($keys): Generator {
                foreach ($keys as $key) {
                    yield TalktoMessage::query()->whereKey($key)->firstOrFail();
                }
            })();
        } elseif ($strategy === 'batch_ten') {
            $items = TalktoMessageCandidates::select($query);
        } else {
            throw new InvalidArgumentException('Unknown materialization strategy.');
        }

        foreach ($items as $message) {
            $ids[] = $message->getKey();
            $payloadBytes += strlen((string) $message->getRawOriginal('payload'));
            $bodyBytes += strlen($message->payload['body']);
        }
        unset($message, $items);
    } elseif ($operation === 'retry') {
        $exit = Artisan::call('talkto:retry-failed', ['--limit' => $limit]);
        $summary = ['exit' => $exit, 'output' => trim(Artisan::output())];
    } elseif ($operation === 'recovery') {
        $summary = app(TalktoStaleMessageRecoveryService::class)->recover(null, 30, $limit, false);
    } else {
        throw new InvalidArgumentException('Unknown operational benchmark.');
    }

    $elapsed = (hrtime(true) - $started) / 1000000;
    $peakUsed = memory_get_peak_usage(false);
    $peakAllocated = memory_get_peak_usage(true);
    $queries = $db->getQueryLog();
    $db->disableQueryLog();
    $jobs = [];

    foreach ([SendTalktoMessage::class, ProcessIncomingTalktoMessage::class] as $jobClass) {
        foreach (Queue::pushed($jobClass) as $item) {
            $jobs[] = ['class' => $jobClass, 'id' => $item->talktoMessageId];
        }
    }

    $result = [
        'php_version' => PHP_VERSION,
        'laravel_version' => $app->version(),
        'strategy' => $strategy,
        'operation' => $operation,
        'limit' => $limit,
        'fixtures_created_in_parent' => true,
        'fresh_process_pid' => getmypid(),
        'peak_reset_after_bootstrap' => $peakReset,
        'memory_before_used_bytes' => $beforeUsed,
        'memory_before_allocated_bytes' => $beforeAllocated,
        'peak_used_bytes' => $peakUsed,
        'peak_allocated_bytes' => $peakAllocated,
        'peak_used_increase_bytes' => max(0, $peakUsed - $beforeUsed),
        'peak_allocated_increase_bytes' => max(0, $peakAllocated - $beforeAllocated),
        'elapsed_ms' => $elapsed,
        'statement_count_excluding_transaction_boundaries' => count($queries),
        'transaction_boundary_count' => $transactionBoundaries,
        'full_models_hydrated' => $hydratedModelCount,
        'raw_payload_json_bytes_retrieved_including_claim_reloads' => $totalRetrievedPayloadBytes,
        'queries' => $queries,
        'candidate_ids_consumed' => $ids,
        'raw_payload_json_bytes_consumed' => $payloadBytes,
        'cast_payload_body_bytes_consumed' => $bodyBytes,
        'summary' => operationalBenchmarkNormalize($summary),
        'jobs' => $jobs,
        'baseline_source_call_replacements' => $baselineReplacements,
    ];

    if ($operation !== 'materialization') {
        $result['states'] = operationalBenchmarkNormalize($db->table('talkto_messages')->where('id', '<=', 50)
            ->select('id', 'overall_status', 'transport_status', 'destination_action_status', 'retry_count', 'next_retry_at', 'next_attempt_at', 'locked_at', 'locked_by')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        $createdClaimIds = [];
        $result['events'] = operationalBenchmarkNormalize($db->table('talkto_events')->orderBy('id')->get()
            ->map(function (object $row) use (&$createdClaimIds): array {
                $meta = json_decode($row->meta, true, flags: JSON_THROW_ON_ERROR);

                if (isset($meta['claim_id'])) {
                    $createdClaimIds[$meta['claim_id']] = true;
                }

                return ['message_id' => $row->message_id, 'event_type' => $row->event_type,
                    'old_status' => $row->old_status, 'new_status' => $row->new_status,
                    'meta' => $meta];
            })->all());
        $result['claim_count'] = $db->table('talkto_messages')->where('locked_by', 'like', 'dispatch-claim:%')->count();
        $result['event_count'] = count($result['events']);
        $result['claims_created'] = count($createdClaimIds);
    }

    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

function operationalBenchmarkNormalize(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('operationalBenchmarkNormalize', $value);
    }

    return is_string($value) && str_starts_with($value, 'dispatch-claim:')
        ? 'dispatch-claim:[generated]'
        : $value;
}
