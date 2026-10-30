<?php

// Real queue application restricted to disposable databases owned by the parent test.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackReceiver;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Orchestra\Testbench\Foundation\Application;

$settings = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (isset($settings['freeze_time'])) {
    Carbon::setTestNow($settings['freeze_time']);
}
foreach (['connection' => '/^talkto_callback_[a-f0-9]{24}$/', 'source_connection' => '/^talkto_callback_source_[a-f0-9]{24}$/', 'queue_connection' => '/^talkto_callback_queue_[a-f0-9]{24}$/'] as $key => $pattern) {
    if (getenv('TALKTO_MYSQL_ENABLED') !== '1' || ! preg_match($pattern, $settings[$key]['database'] ?? '')) {
        throw new RuntimeException('Requires parent-owned callback fixture databases.');
    }
}
if (! preg_match('/^[a-z0-9-]+$/', $settings['worker'] ?? '') || ! is_dir($settings['directory'] ?? '')) {
    throw new RuntimeException('Requires an owned callback fixture barrier directory.');
}
$write = function (string $marker, array $data = []) use ($settings): void {
    $path = $settings['directory'].'/'.$settings['worker'].'.'.$marker.'.json';
    file_put_contents($path.'.writing', json_encode($data + ['observed_at' => microtime(true)], JSON_THROW_ON_ERROR));
    rename($path.'.writing', $path);
};
$wait = function () use ($settings): void {
    $deadline = microtime(true) + 25;
    while (! is_file($settings['directory'].'/'.$settings['worker'].'.release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Callback fixture barrier expired.');
        }
        usleep(10000);
        clearstatcache();
    }
};

class ReliableCallbackRetryMysqlHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): IncomingCommandResultContract
    {
        DB::connection('callback_fixture')->table('callback_probe_metrics')->where('name', 'handler_calls')->increment('value');
        if (is_callable($GLOBALS['callback_handler_barrier'] ?? null)) {
            ($GLOBALS['callback_handler_barrier'])();
        }
        $settings = $GLOBALS['callback_fixture_settings'];
        $body = ['synthetic' => true, 'unicode' => 'نتیجه ✓', 'body' => str_repeat('x', $settings['payload_bytes'] ?? 1024), 'empty' => [], 'nested' => ['null' => null]];
        $meta = array_merge(['fixture' => 'reliable-callback', 'nested' => ['empty' => [], 'unicode' => 'پایدار']], $settings['result_meta'] ?? []);

        return match ($settings['result_type'] ?? 'succeeded') {
            'skipped' => TalktoIncomingCommandResult::skipped('Synthetic skip.', $meta),
            'failed_retryable' => TalktoIncomingCommandResult::failedRetryable($settings['result_error'] ?? 'Synthetic retryable result.', 'SyntheticRetryable', $meta),
            'failed_final' => TalktoIncomingCommandResult::failedFinal($settings['result_error'] ?? 'Synthetic final result.', 'SyntheticFinal', $meta),
            'unexpected' => throw new RuntimeException('Synthetic handler exception.'),
            'invalid' => TalktoIncomingCommandResult::succeeded(['invalid' => NAN]),
            default => TalktoIncomingCommandResult::succeeded($body, $meta),
        };
    }
}

class ReliableCallbackRetryMysqlMessage extends TalktoMessage {}

class ReliableCallbackRetryMysqlForeignAttempt extends TalktoAttempt
{
    public function getConnectionName()
    {
        return 'callback_queue';
    }
}

class ReliableCallbackRetryMysqlSendJob extends SendTalktoMessage
{
    public $queue = 'callback-custom-out';
}

class ReliableCallbackRetryMysqlIncomingJob extends ProcessIncomingTalktoMessage
{
    public function __construct(int $id)
    {
        parent::__construct($id);
        $this->onQueue('callback-custom-in');
    }
}

$result = ['worker' => $settings['worker'], 'operation' => $settings['operation'], 'pid' => getmypid(), 'exceptions' => []];
try {
    $app = Application::create(options: ['extra' => ['providers' => [LaravelTalktoServiceProvider::class], 'dont-discover' => ['*']]]);
    config([
        'logging.default' => 'stderr',
        'database.default' => 'callback_fixture',
        'database.connections.callback_fixture' => $settings['connection'],
        'database.connections.callback_source' => $settings['source_connection'],
        'database.connections.callback_queue' => $settings['queue_connection'],
        'talkto.database.connection' => 'callback_fixture', 'talkto.service' => 'destination',
        'talkto.dispatch_intents.enabled' => $settings['durable_enabled'] ?? true,
        'talkto.dispatch_intents.recovery_enabled' => $settings['recovery_enabled'] ?? true,
        'talkto.dispatch_intents.publish_after_commit' => $settings['publish_after_commit'] ?? true,
        'talkto.callbacks.enabled' => $settings['callbacks_enabled'] ?? true,
        'talkto.callbacks.auto_dispatch' => $settings['auto_dispatch'] ?? true,
        'talkto.callbacks.reliable.enabled' => $settings['reliable_enabled'] ?? true,
        'talkto.callbacks.reliable.recovery_enabled' => $settings['callback_recovery_enabled'] ?? true,
        'talkto.outgoing.source' => ['url' => 'https://callback-fixture.invalid', 'secret' => 'synthetic-callback-secret'],
        'talkto.outgoing.destination' => ['url' => 'https://callback-fixture.invalid', 'secret' => 'synthetic-callback-secret'],
        'talkto.incoming.source' => ['secret' => 'synthetic-callback-secret', 'allowed_commands' => ['domain.command' => ['handler' => ReliableCallbackRetryMysqlHandler::class]]],
        'talkto.incoming.destination' => ['secret' => 'synthetic-callback-secret', 'allowed_commands' => ['talkto.result' => ['driver' => 'none']]],
        'talkto.security.require_signature' => true, 'talkto.security.signature_version' => 'v2', 'talkto.security.accept_versions' => ['v2'],
        'talkto.retry.incoming_enabled' => $settings['incoming_retry_enabled'] ?? true,
        'talkto.retry.backoff_seconds' => [0], 'talkto.callbacks.command' => $settings['callback_command'] ?? 'talkto.result',
        'queue.default' => 'callback_probe',
        'queue.connections.callback_probe' => ['driver' => 'database', 'connection' => ($settings['same_connection'] ?? true) ? 'callback_fixture' : 'callback_queue',
            'table' => ($settings['missing_queue_table'] ?? false) ? 'deliberately_missing_jobs' : 'jobs', 'queue' => 'callback-probe', 'retry_after' => 120, 'after_commit' => true],
        'queue.connections.callback_sync' => ['driver' => 'sync', 'after_commit' => false], 'app.timezone' => 'UTC',
    ]);
    if ($settings['custom_model'] ?? false) {
        config(['talkto.models.message' => ReliableCallbackRetryMysqlMessage::class]);
    }
    if ($settings['foreign_attempt'] ?? false) {
        config(['talkto.models.attempt' => ReliableCallbackRetryMysqlForeignAttempt::class]);
    }
    if ($settings['custom_jobs'] ?? false) {
        config(['talkto.jobs.send_message' => ReliableCallbackRetryMysqlSendJob::class, 'talkto.jobs.process_incoming' => ReliableCallbackRetryMysqlIncomingJob::class]);
    }
    if ($settings['sync_queue'] ?? false) {
        config(['queue.default' => 'callback_sync']);
    }
    $db = DB::connection('callback_fixture');
    $sourceDb = DB::connection('callback_source');
    $queueDb = DB::connection(($settings['same_connection'] ?? true) ? 'callback_fixture' : 'callback_queue');
    $db->statement('SET SESSION innodb_lock_wait_timeout = 15');
    $result['connection_id'] = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $result['queue_connection_id'] = (int) $queueDb->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $result['source_connection_id'] = (int) $sourceDb->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $result['php_version'] = PHP_VERSION;
    $result['laravel_version'] = $app->version();
    $startedAt = microtime(true);
    $queryCount = 0;
    $queryTime = 0.0;
    $intentQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryTime, &$intentQueries): void {
        $queryCount++;
        $queryTime += $query->time;
        if (str_contains($query->sql, 'talkto_dispatch_intents')) {
            $intentQueries++;
        }
    });
    $GLOBALS['callback_fixture_settings'] = $settings;
    $write('connected', ['connection_id' => $result['connection_id']]);
    if ($settings['pause_handler'] ?? false) {
        $GLOBALS['callback_handler_barrier'] = function () use ($write, $wait): void {
            $write('handler-started');
            $wait();
        };
    }
    if ($settings['pause_legacy_sender'] ?? false) {
        app()->instance(ResultCallbackSenderContract::class, new class($write, $wait) implements ResultCallbackSenderContract
        {
            public function __construct(private $write, private $wait) {}

            public function sendResult(Model $message, IncomingCommandResultContract $result, array $options = []): array
            {
                ($this->write)('legacy-after-result-commit');
                ($this->wait)();

                return ['sent' => false, 'queued' => false, 'status' => 'synthetic'];
            }
        });
    }
    Event::listen(JobQueued::class, function (JobQueued $event) use ($db, $settings): void {
        $db->table('callback_probe_metrics')->where('name', 'publications')->increment('value');
        if ($event->job instanceof SendTalktoMessage) {
            $db->table('callback_probe_metrics')->where('name', 'callback_publications')->increment('value');
        }
        if ($settings['lost_publication_ack'] ?? false) {
            throw new RuntimeException('Synthetic queue acceptance acknowledgement loss.');
        }
    });
    if (isset($settings['hook'])) {
        TalktoDispatchTestHooks::set($settings['hook'], function (array $context) use ($settings, $write, $wait): void {
            $write('hook', ['name' => $settings['hook']]);
            if ($settings['throw_hook'] ?? false) {
                throw new RuntimeException('Synthetic failure at callback boundary.');
            }
            $wait();
            if ($settings['deadlock_metric_at_hook'] ?? false) {
                DB::connection('callback_fixture')->table('callback_probe_metrics')->where('name', 'deadlock_probe')->increment('value');
            }
        });
    }
    if (isset($settings['query_barrier'])) {
        $paused = false;
        DB::listen(function (QueryExecuted $query) use (&$paused, $settings, $write, $wait): void {
            $match = match ($settings['query_barrier']) {
                'result-update' => str_starts_with(strtolower($query->sql), 'update `talkto_messages`') && in_array('succeeded', $query->bindings, true),
                'callback-insert' => str_starts_with(strtolower($query->sql), 'insert into `talkto_messages`') && in_array('talkto.result', $query->bindings, true),
                'intent-insert' => str_starts_with(strtolower($query->sql), 'insert into `talkto_dispatch_intents`'),
                default => false,
            };
            if (! $paused && $match) {
                $paused = true;
                $write('query-held', ['name' => $settings['query_barrier']]);
                $wait();
            }
        });
    }
    app()->instance(TalktoHttpClient::class, new class($settings, $write, $wait) implements TalktoHttpClient
    {
        public function __construct(private array $settings, private $write, private $wait) {}

        public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
        {
            $db = DB::connection('callback_fixture');
            $db->table('callback_probe_metrics')->where('name', 'http_calls')->increment('value');
            if ($this->settings['pause_http'] ?? false) {
                ($this->write)('http-started');
                ($this->wait)();
            }
            if ($this->settings['http_outage'] ?? false) {
                return new TalktoHttpResponse($this->settings['http_status'] ?? 503, '{"synthetic":"unavailable"}');
            }
            if ($envelope['command'] !== config('talkto.callbacks.command')) {
                $oldService = config('talkto.service');
                $oldConnection = config('talkto.database.connection');
                try {
                    config(['talkto.service' => 'destination', 'talkto.database.connection' => 'callback_fixture']);
                    $request = Request::create('/talkto/receive', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($envelope, JSON_THROW_ON_ERROR));
                    foreach ($headers as $name => $value) {
                        $request->headers->set($name, $value);
                    }
                    $received = app(ReceiveIncomingTalktoMessagePipeline::class)->receive($request, app(TalktoSignatureVerifier::class));
                } finally {
                    config(['talkto.service' => $oldService, 'talkto.database.connection' => $oldConnection]);
                }
                $db->table('callback_probe_metrics')->where('name', 'command_deliveries')->increment('value');

                return new TalktoHttpResponse($received->getStatusCode(), $received->getContent());
            }
            $oldService = config('talkto.service');
            $oldConnection = config('talkto.database.connection');
            try {
                config(['talkto.service' => 'source', 'talkto.database.connection' => 'callback_source']);
                $accepted = app(TalktoResultCallbackReceiver::class)->receiveResult($envelope, $headers);
                $GLOBALS['retry_callback_receipts'][] = [
                    'callback_message_id' => $envelope['message_id'],
                    'payload_hash' => $envelope['payload_hash'],
                    'status' => $envelope['payload']['status'],
                    'error_message' => $envelope['payload']['error_message'] ?? null,
                    'meta_hash' => hash('sha256', json_encode($envelope['payload']['meta'] ?? [], JSON_THROW_ON_ERROR)),
                    'receiver_response' => $accepted,
                ];
            } finally {
                config(['talkto.service' => $oldService, 'talkto.database.connection' => $oldConnection]);
            }
            $db->table('callback_probe_metrics')->where('name', 'deliveries')->increment('value');
            if (! ($accepted['accepted'] ?? false)) {
                throw new RuntimeException('Synthetic receiver rejected signed callback: '.($accepted['error'] ?? 'unknown'));
            }
            if ($this->settings['lost_http_ack'] ?? false) {
                throw new RuntimeException('Synthetic callback delivered but HTTP acknowledgement lost.');
            }
            $db->table('callback_probe_metrics')->where('name', 'acknowledgements')->increment('value');

            return new TalktoHttpResponse(200, json_encode($accepted, JSON_THROW_ON_ERROR));
        }
    });
    try {
        switch ($settings['operation']) {
            case 'legacy-source-send':
                try {
                    config(['talkto.service' => 'source', 'talkto.database.connection' => 'callback_source']);
                    $message = app(TalktoOutgoingMessageFactory::class)->create('destination', 'domain.command', ['synthetic' => true], ['message_id' => $settings['message_id']]);
                    (new SendTalktoMessage((int) $message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class));
                } finally {
                    config(['talkto.service' => 'destination', 'talkto.database.connection' => 'callback_fixture']);
                }
                break;
            case 'process':
                $messageClass = config('talkto.models.message');
                $message = $messageClass::query()->where('message_id', $settings['message_id'])->sole();
                $jobClass = config('talkto.jobs.process_incoming');
                (new $jobClass((int) $message->id))->handle();
                break;
            case 'manual-callback':
                $messageClass = config('talkto.models.message');
                $message = $messageClass::query()->where('message_id', $settings['message_id'])->sole();
                $meta = array_merge(['fixture' => 'reliable-callback', 'nested' => ['empty' => [], 'unicode' => 'پایدار']], $settings['result_meta'] ?? []);
                $snapshot = match ($settings['result_type'] ?? 'succeeded') {
                    'failed_retryable' => TalktoIncomingCommandResult::failedRetryable($settings['result_error'] ?? 'Synthetic retryable result.', 'SyntheticRetryable', $meta),
                    'failed_final' => TalktoIncomingCommandResult::failedFinal($settings['result_error'] ?? 'Synthetic final result.', 'SyntheticFinal', $meta),
                    'skipped' => TalktoIncomingCommandResult::skipped('Synthetic skip.', $meta),
                    default => TalktoIncomingCommandResult::succeeded(['synthetic' => true, 'unicode' => 'نتیجه ✓', 'body' => str_repeat('x', $settings['payload_bytes'] ?? 1024), 'empty' => [], 'nested' => ['null' => null]], $meta),
                };
                $result['manual_summary'] = app(ResultCallbackSenderContract::class)->sendResult($message, $snapshot, $settings['manual_options'] ?? []);
                break;
            case 'signed-redelivery':
                $messageClass = config('talkto.models.message');
                $callback = $messageClass::query()->where('message_id', $settings['callback_id'])->sole();
                $builder = app(TalktoOutgoingEnvelopeBuilder::class);
                $signed = $builder->build($callback);
                $response = app(TalktoHttpClient::class)->post($builder->endpointFor($callback), $signed['headers'], $signed['envelope'], $builder->timeoutFor($callback));
                $result['redelivery_http_status'] = $response->status();
                break;
            case 'recover':
            case 'inspect':
                $args = ['--min-age' => 0, '--limit' => $settings['limit'] ?? 20, '--json' => true];
                if (isset($settings['callback_id'])) {
                    $args['--message-id'] = $settings['callback_id'];
                }
                if ($settings['operation'] === 'recover') {
                    $args['--execute'] = true;
                }
                $result['command_exit'] = Artisan::call('talkto:dispatch-intents', $args);
                $result['command_output'] = Artisan::output();
                break;
            case 'retry':
                $result['command_exit'] = Artisan::call('talkto:retry-failed', ['--limit' => 10, '--direction' => $settings['retry_direction'] ?? 'incoming']);
                $result['command_output'] = Artisan::output();
                break;
            case 'stale':
                $result['command_exit'] = Artisan::call('talkto:recover-stale', ['--older-than' => 1, '--limit' => 10]);
                $result['command_output'] = Artisan::output();
                break;
            case 'dlq':
                $result['command_exit'] = Artisan::call('talkto:dlq-reprocess', ['--limit' => 10]);
                $result['command_output'] = Artisan::output();
                break;
            case 'nested-commit':
            case 'nested-rollback':
                $db->beginTransaction();
                $messageClass = config('talkto.models.message');
                $message = $messageClass::query()->where('message_id', $settings['message_id'])->sole();
                (new ProcessIncomingTalktoMessage((int) $message->id))->handle();
                $result['jobs_before_outer_resolution'] = $queueDb->table('jobs')->count();
                $settings['operation'] === 'nested-commit' ? $db->commit() : $db->rollBack();
                break;
            case 'deadlock-opponent':
                $db->beginTransaction();
                $db->table('callback_probe_metrics')->where('name', 'deadlock_probe')->lockForUpdate()->first();
                $write('metric-locked', ['connection_id' => $result['connection_id']]);
                $write('incoming-lock-attempt', ['connection_id' => $result['connection_id']]);
                $db->table('talkto_messages')->where('message_id', $settings['message_id'])->update(['last_error' => 'Synthetic deadlock opponent.']);
                $db->commit();
                break;
            case 'consume':
            case 'reserve':
                $job = Queue::connection('callback_probe')->pop($settings['queue_name'] ?? 'callback-probe');
                $result['popped'] = $job !== null;
                if ($job !== null && $settings['operation'] !== 'reserve') {
                    $result['job_class'] = $job->resolveName();
                    $db->table('callback_probe_metrics')->where('name', 'executions')->increment('value');
                    $job->fire();
                    if (! $job->isDeleted()) {
                        $job->delete();
                    }
                }
                break;
            default:
                throw new RuntimeException('Unknown callback fixture operation.');
        }
    } catch (Throwable $error) {
        $result['exceptions'][] = ['class' => $error::class, 'driver_code' => $error instanceof QueryException ? ($error->errorInfo[1] ?? null) : null,
            'message' => $error instanceof QueryException ? 'Synthetic database operation failed.' : mb_substr($error->getMessage(), 0, 1000)];
        while ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
    }
    $result['operation_sql_query_count'] = $queryCount;
    $result['operation_intent_sql_count'] = $intentQueries;
    $result['messages'] = $db->table('talkto_messages')->orderBy('id')->get(['id', 'message_id', 'parent_message_id', 'direction', 'command', 'overall_status', 'destination_action_status', 'transport_status', 'attempts', 'retry_count', 'locked_by', 'locked_at', 'completed_at', 'last_error', 'next_retry_at', 'next_attempt_at', 'failed_at'])->map(function ($row): array {
        $value = (array) $row;
        if (str_starts_with((string) ($value['locked_by'] ?? ''), 'reliable-callback:')) {
            $value['locked_by_hash'] = hash('sha256', $value['locked_by']);
            $value['locked_by'] = 'reliable-callback:[redacted]';
        }

        return $value;
    })->all();
    $callbackIds = $db->table('talkto_messages')->where('direction', 'outgoing')->where('parent_message_id', $settings['message_id'] ?? '')->orderBy('id')->pluck('id');
    $result['callbacks'] = $callbackIds->map(fn ($id) => $db->table('talkto_messages')->where('id', $id)->sole())->map(function ($row): array {
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

        return [
            'id' => $row->id, 'message_id' => $row->message_id, 'idempotency_key' => $row->idempotency_key,
            'overall_status' => $row->overall_status, 'payload_hash' => $row->payload_hash,
            'raw_payload_sha256' => hash('sha256', $row->payload), 'payload_bytes' => strlen($row->payload),
            'payload_status' => $payload['status'], 'error_message' => $payload['error_message'] ?? null,
            'meta_hash' => hash('sha256', json_encode($payload['meta'] ?? [], JSON_THROW_ON_ERROR)),
            'immutable_binding_sha256' => hash('sha256', json_encode(array_intersect_key((array) $row, array_flip([
                'message_id', 'parent_message_id', 'correlation_id', 'idempotency_key', 'command', 'source_service', 'target_service', 'business_key', 'payload', 'payload_hash', 'schema_version',
            ])), JSON_THROW_ON_ERROR)),
        ];
    })->all();
    $result['jobs'] = $queueDb->table('jobs')->orderBy('id')->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at', DB::raw('OCTET_LENGTH(payload) AS payload_bytes')])->map(fn ($row) => (array) $row)->all();
    $result['metrics'] = $db->table('callback_probe_metrics')->pluck('value', 'name')->all();
    $result['intents'] = $db->getSchemaBuilder()->hasTable('talkto_dispatch_intents') ? $db->table('talkto_dispatch_intents')->orderBy('id')->get()->map(function ($row): array {
        $value = (array) $row;
        if (isset($value['claim_token'])) {
            $value['claim_token_hash'] = hash('sha256', $value['claim_token']);
            unset($value['claim_token']);
        }

        return $value;
    })->all() : [];
    $result['attempts'] = $db->table('talkto_attempts')->orderBy('id')->get(['id', 'message_id', 'stage', 'attempt_no', 'status', 'error_class', 'error_message', 'created_at', 'updated_at'])->map(fn ($row) => (array) $row)->all();
    $result['events'] = $db->table('talkto_events')->orderBy('id')->get(['id', 'message_id', 'event_type', 'old_status', 'new_status'])->map(fn ($row) => (array) $row)->all();
    $result['source_messages'] = $sourceDb->table('talkto_messages')->get(['message_id', 'overall_status', 'destination_action_status', 'last_error', 'completed_at', 'failed_at'])->map(fn ($row) => (array) $row)->all();
    $result['receipts'] = $GLOBALS['retry_callback_receipts'] ?? [];
    $result['source_events'] = $sourceDb->table('talkto_events')->orderBy('id')->get(['id', 'message_id', 'event_type', 'old_status', 'new_status', 'meta'])->map(fn ($row) => (array) $row)->all();
    $result['peak_allocated_bytes'] = memory_get_peak_usage(true);
    $result['elapsed_ms'] = round((microtime(true) - $startedAt) * 1000, 3);
    $result['sql_query_count'] = $queryCount;
    $result['sql_total_ms'] = round($queryTime, 3);
    $result['statement_rows_examined'] = (int) $db->selectOne('SELECT COALESCE(SUM(summary.SUM_ROWS_EXAMINED), 0) AS total FROM performance_schema.events_statements_summary_by_thread_by_event_name AS summary JOIN performance_schema.threads AS thread ON thread.THREAD_ID = summary.THREAD_ID WHERE thread.PROCESSLIST_ID = CONNECTION_ID()')->total;
    $write('result', $result);
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.($error instanceof QueryException ? 'Disposable database operation failed.' : mb_substr($error->getMessage(), 0, 1000)).PHP_EOL);
    exit(1);
} finally {
    unset($GLOBALS['callback_fixture_settings'], $GLOBALS['callback_handler_barrier']);
    TalktoDispatchTestHooks::reset();
    DB::purge('callback_fixture');
    DB::purge('callback_source');
    DB::purge('callback_queue');
}
