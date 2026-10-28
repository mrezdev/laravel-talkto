<?php

// Standalone real-queue application, restricted to parent-created fixture databases.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoFlowBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackReceiver;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Orchestra\Testbench\Foundation\Application;

$settings = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (getenv('TALKTO_MYSQL_ENABLED') !== '1'
    || ! preg_match('/^talkto_intent_[a-f0-9]{24}$/', $settings['connection']['database'] ?? '')
    || ! preg_match('/^talkto_intent_queue_[a-f0-9]{24}$/', $settings['queue_connection']['database'] ?? '')
    || ! preg_match('/^[a-z0-9-]+$/', $settings['worker'] ?? '')
    || ! is_dir($settings['directory'] ?? '')) {
    throw new RuntimeException('This worker requires parent-owned durable-intent fixture databases.');
}
$write = function (string $marker, array $value = []) use ($settings): void {
    $path = $settings['directory'].'/'.$settings['worker'].'.'.$marker.'.json';
    file_put_contents($path.'.writing', json_encode($value + ['observed_at' => microtime(true)], JSON_THROW_ON_ERROR));
    rename($path.'.writing', $path);
};
$wait = function (string $marker) use ($settings): void {
    $deadline = microtime(true) + 20;
    while (! is_file($settings['directory'].'/'.$settings['worker'].'.'.$marker)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Durable-intent fixture barrier expired.');
        }
        usleep(10000);
        clearstatcache();
    }
};

class DispatchIntentProbeHandler implements TalktoIncomingCommandHandler
{
    public function handle(TalktoMessage $message): IncomingCommandResultContract
    {
        DB::connection('intent_fixture')->table('intent_probe_metrics')->where('name', 'handler_calls')->increment('value');
        if (is_callable($GLOBALS['dispatch_intent_handler_pause'] ?? null)) {
            ($GLOBALS['dispatch_intent_handler_pause'])();
        }

        return TalktoIncomingCommandResult::succeeded(['synthetic' => true]);
    }
}

class DispatchIntentCustomSendJob extends SendTalktoMessage
{
    public function __construct(int $id)
    {
        parent::__construct($id);
        $this->onQueue('custom-out');
    }
}

class DispatchIntentCustomIncomingJob extends ProcessIncomingTalktoMessage
{
    public function __construct(int $id)
    {
        parent::__construct($id);
        $this->onQueue('custom-in');
    }
}

class DispatchIntentDelayedSendJob extends SendTalktoMessage
{
    public function __construct(int $id)
    {
        parent::__construct($id);
        $this->delay(60);
    }
}

$result = ['worker' => $settings['worker'], 'operation' => $settings['operation'], 'pid' => getmypid(), 'exceptions' => []];
try {
    $app = Application::create(options: ['extra' => ['providers' => [LaravelTalktoServiceProvider::class], 'dont-discover' => ['*']]]);
    config([
        'database.default' => 'intent_fixture', 'database.connections.intent_fixture' => $settings['connection'],
        'database.connections.intent_queue' => $settings['queue_connection'],
        'talkto.database.connection' => 'intent_fixture', 'talkto.service' => 'base',
        'talkto.dispatch_intents.enabled' => $settings['enabled'] ?? true,
        'talkto.dispatch_intents.recovery_enabled' => $settings['recovery_enabled'] ?? true,
        'talkto.dispatch_intents.publish_after_commit' => $settings['publish_after_commit'] ?? true,
        'talkto.dispatch_intents.lease_seconds' => $settings['lease_seconds'] ?? 60,
        'talkto.outgoing.peer' => ['url' => 'https://intent-fixture.invalid', 'secret' => 'synthetic-intent-secret'],
        'talkto.incoming.peer' => ['secret' => 'synthetic-intent-secret', 'allowed_commands' => ['domain.command' => ['handler' => DispatchIntentProbeHandler::class], 'talkto.result' => ['driver' => 'none']]],
        'talkto.security.require_signature' => true, 'talkto.security.signature_version' => 'v2', 'talkto.security.accept_versions' => ['v2'],
        'talkto.callbacks.auto_dispatch' => false,
        'app.timezone' => 'UTC', 'queue.default' => 'intent_probe',
        'queue.connections.intent_probe' => ['driver' => 'database', 'connection' => ($settings['same_connection'] ?? true) ? 'intent_fixture' : 'intent_queue',
            'table' => ($settings['missing_queue_table'] ?? false) ? 'deliberately_missing_jobs' : 'jobs', 'queue' => 'intent-probe', 'retry_after' => 120, 'after_commit' => true],
        'queue.connections.intent_sync' => ['driver' => 'sync', 'after_commit' => false],
    ]);
    if ($settings['custom_jobs'] ?? false) {
        config(['talkto.jobs.send_message' => DispatchIntentCustomSendJob::class, 'talkto.jobs.process_incoming' => DispatchIntentCustomIncomingJob::class]);
    }
    if ($settings['delayed_job'] ?? false) {
        config(['talkto.jobs.send_message' => DispatchIntentDelayedSendJob::class]);
    }
    if ($settings['pause_handler'] ?? false) {
        $GLOBALS['dispatch_intent_handler_pause'] = function () use ($write, $wait): void {
            $write('handler-started');
            $wait('release');
        };
    }
    if ($settings['sync_queue'] ?? false) {
        config(['queue.default' => 'intent_sync']);
    }
    $db = DB::connection('intent_fixture');
    $queueDb = DB::connection(($settings['same_connection'] ?? true) ? 'intent_fixture' : 'intent_queue');
    $db->statement('SET SESSION innodb_lock_wait_timeout = 12');
    $result['connection_id'] = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $result['queue_connection_id'] = (int) $queueDb->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $result['php_version'] = PHP_VERSION;
    $result['laravel_version'] = $app->version();
    $startedAt = microtime(true);
    $queryCount = 0;
    $queryMilliseconds = 0.0;
    DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryMilliseconds): void {
        $queryCount++;
        $queryMilliseconds += $query->time;
    });
    $write('connected', ['connection_id' => $result['connection_id']]);
    if ($settings['observe_message_insert'] ?? false) {
        $db->beforeExecuting(function (string $sql) use ($write, $result): void {
            if (str_starts_with(strtolower($sql), 'insert into `talkto_messages`')) {
                $write('message-insert-attempt', ['connection_id' => $result['connection_id']]);
            }
        });
    }
    if ($settings['pause_atomic_publishing'] ?? false) {
        $paused = false;
        DB::listen(function (QueryExecuted $query) use (&$paused, $write, $wait, $result): void {
            if (! $paused && str_starts_with(strtolower($query->sql), 'update `talkto_dispatch_intents`') && in_array('publishing', $query->bindings, true)) {
                $paused = true;
                $write('atomic-publishing-held', ['connection_id' => $result['connection_id']]);
                $wait('release');
            }
        });
    }

    app()->instance(TalktoHttpClient::class, new class implements TalktoHttpClient
    {
        public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
        {
            DB::connection('intent_fixture')->table('intent_probe_metrics')->where('name', 'http_calls')->increment('value');

            return new TalktoHttpResponse(200, '{"received":true,"status":"queued"}');
        }
    });
    Event::listen(JobQueued::class, function () use ($db, $settings): void {
        $db->table('intent_probe_metrics')->where('name', 'publications')->increment('value');
        if ($settings['lost_ack'] ?? false) {
            throw new RuntimeException('Synthetic acknowledgement loss after actual DatabaseQueue publication.');
        }
    });
    if (isset($settings['hook'])) {
        TalktoDispatchTestHooks::set($settings['hook'], function (array $context) use ($write, $wait, $settings): void {
            $write('hook', ['name' => $settings['hook'], 'observed_at' => microtime(true)]);
            if ($settings['throw_hook'] ?? false) {
                throw new RuntimeException('Synthetic durable-intent failure at an explicit boundary.');
            }
            $wait('release');
        });
    }
    $flow = fn (): TalktoFlowBuilder => (new TalktoFlowBuilder('intent-fixture', app(TalktoOutgoingMessageFactory::class)))
        ->to('peer')->command('domain.command')->payload(['synthetic' => true, 'body' => str_repeat('x', $settings['payload_bytes'] ?? 1024)])
        ->options(['message_id' => $settings['message_id'] ?? $settings['worker'], 'idempotency_key' => $settings['idempotency_key'] ?? null]);

    try {
        switch ($settings['operation']) {
            case 'send':
                $flow()->send();
                break;
            case 'factory':
                app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', ['synthetic' => true], ['message_id' => $settings['message_id'] ?? $settings['worker']]);
                break;
            case 'run':
                $flow()->run(fn (): array => ['synthetic' => true]);
                break;
            case 'foreign-source-run':
                config(['database.default' => 'intent_queue']);
                $result['source_action_called'] = false;
                $flow()->run(function () use (&$result): array {
                    $result['source_action_called'] = true;

                    return ['synthetic' => true];
                });
                break;
            case 'nested-commit':
            case 'nested-rollback':
                $db->beginTransaction();
                $db->beginTransaction();
                $flow()->send();
                $result['jobs_before_inner_commit'] = $queueDb->table('jobs')->count();
                $db->commit();
                $result['jobs_before_outer_resolution'] = $queueDb->table('jobs')->count();
                $settings['operation'] === 'nested-commit' ? $db->commit() : $db->rollBack();
                break;
            case 'receive':
                $id = $settings['message_id'] ?? $settings['worker'];
                $payload = ['synthetic' => true];
                $envelope = ['message_id' => $id, 'source' => 'peer', 'target' => 'base', 'command' => 'domain.command',
                    'payload' => $payload, 'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload)];
                $timestamp = gmdate(DATE_ATOM);
                $nonce = 'nonce-'.$settings['worker'];
                $signature = app(TalktoSigner::class)->signV2($timestamp, $nonce, $id, 'peer', 'base', 'domain.command', $envelope['payload_hash'], 'synthetic-intent-secret');
                $request = Request::create('/talkto/receive', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($envelope, JSON_THROW_ON_ERROR));
                foreach (['X-Talkto-Signature' => $signature, 'X-Talkto-Signature-Version' => 'v2', 'X-Talkto-Timestamp' => $timestamp,
                    'X-Talkto-Message-Id' => $id, 'X-Talkto-Payload-Hash' => $envelope['payload_hash'], 'X-Talkto-Nonce' => $nonce] as $name => $value) {
                    $request->headers->set($name, $value);
                }
                $response = app(ReceiveIncomingTalktoMessagePipeline::class)->receive($request, app(TalktoSignatureVerifier::class));
                $result['response'] = ['status' => $response->getStatusCode(), 'body' => $response->getData(true)];
                break;
            case 'recover':
            case 'inspect':
                $arguments = ['--min-age' => 0, '--limit' => $settings['limit'] ?? 20, '--json' => true];
                if (isset($settings['message_id'])) {
                    $arguments['--message-id'] = $settings['message_id'];
                }
                if ($settings['operation'] === 'recover') {
                    $arguments['--execute'] = true;
                }
                $result['command_exit'] = Artisan::call('talkto:dispatch-intents', $arguments);
                $result['command_output'] = Artisan::output();
                break;
            case 'retry':
                $result['command_exit'] = Artisan::call('talkto:retry-failed', ['--limit' => 1]);
                $result['command_output'] = Artisan::output();
                break;
            case 'stale':
                $result['command_exit'] = Artisan::call('talkto:recover-stale', ['--older-than' => 1, '--limit' => 1]);
                $result['command_output'] = Artisan::output();
                break;
            case 'callback':
                $message = TalktoMessage::query()->where('message_id', $settings['message_id'])->sole();
                $payload = ['original_message_id' => $message->message_id, 'original_command' => $message->command,
                    'status' => 'succeeded', 'succeeded' => true, 'retryable' => false, 'skipped' => false, 'result' => ['synthetic' => true], 'meta' => []];
                $envelope = ['message_id' => 'callback-'.$message->message_id, 'parent_message_id' => $message->message_id,
                    'source' => 'peer', 'target' => 'base', 'command' => 'talkto.result', 'payload' => $payload,
                    'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload)];
                $timestamp = gmdate(DATE_ATOM);
                $nonce = 'nonce-'.$settings['worker'];
                $signature = app(TalktoSigner::class)->signV2($timestamp, $nonce, $envelope['message_id'], 'peer', 'base', 'talkto.result', $envelope['payload_hash'], 'synthetic-intent-secret');
                $result['callback_result'] = app(TalktoResultCallbackReceiver::class)->receiveResult($envelope, [
                    'X-Talkto-Signature' => $signature, 'X-Talkto-Signature-Version' => 'v2', 'X-Talkto-Timestamp' => $timestamp,
                    'X-Talkto-Message-Id' => $envelope['message_id'], 'X-Talkto-Payload-Hash' => $envelope['payload_hash'], 'X-Talkto-Nonce' => $nonce]);
                break;
            case 'deadlock-opponent':
                $db->beginTransaction();
                $db->table('intent_probe_metrics')->where('name', 'publications')->lockForUpdate()->first();
                $write('metric-locked', ['connection_id' => $result['connection_id']]);
                $write('message-lock-attempt', ['connection_id' => $result['connection_id']]);
                $db->table('talkto_messages')->where('message_id', $settings['message_id'])->update(['last_error' => 'Synthetic deadlock opponent.']);
                $db->commit();
                break;
            case 'consume':
            case 'reserve':
                $job = Queue::connection('intent_probe')->pop($settings['queue_name'] ?? 'intent-probe');
                $result['popped'] = $job !== null;
                if ($job !== null && $settings['operation'] !== 'reserve') {
                    $result['job_class'] = $job->resolveName();
                    $db->table('intent_probe_metrics')->where('name', 'executions')->increment('value');
                    $job->fire();
                    if (! $job->isDeleted()) {
                        $job->delete();
                    }
                }
                break;
            default:
                throw new RuntimeException('Unsupported durable-intent fixture operation.');
        }
    } catch (Throwable $error) {
        $result['exceptions'][] = ['class' => $error::class, 'driver_code' => $error instanceof QueryException ? ($error->errorInfo[1] ?? null) : null,
            'message' => $error instanceof QueryException ? 'Synthetic database operation failed.' : mb_substr($error->getMessage(), 0, 1000)];
        while ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
    }
    $result['messages'] = $db->table('talkto_messages')->orderBy('id')->get(['id', 'message_id', 'direction', 'overall_status', 'transport_status', 'destination_action_status', 'retry_count', 'attempts'])->map(fn ($row) => (array) $row)->all();
    $result['jobs'] = $queueDb->table('jobs')->orderBy('id')->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at'])->map(fn ($row) => (array) $row)->all();
    $result['metrics'] = $db->table('intent_probe_metrics')->pluck('value', 'name')->all();
    $result['intents'] = $db->getSchemaBuilder()->hasTable('talkto_dispatch_intents') ? $db->table('talkto_dispatch_intents')->orderBy('id')->get()->map(function ($row): array {
        $value = (array) $row;
        if (isset($value['claim_token'])) {
            $value['claim_token_hash'] = hash('sha256', $value['claim_token']);
            unset($value['claim_token']);
        }

        return $value;
    })->all() : [];
    $result['peak_allocated_bytes'] = memory_get_peak_usage(true);
    $result['elapsed_ms'] = round((microtime(true) - $startedAt) * 1000, 3);
    $result['sql_query_count'] = $queryCount;
    $result['sql_total_ms'] = round($queryMilliseconds, 3);
    $result['statement_rows_examined'] = (int) $db->selectOne('SELECT COALESCE(SUM(summary.SUM_ROWS_EXAMINED), 0) AS total FROM performance_schema.events_statements_summary_by_thread_by_event_name AS summary JOIN performance_schema.threads AS thread ON thread.THREAD_ID = summary.THREAD_ID WHERE thread.PROCESSLIST_ID = CONNECTION_ID()')->total;
    $write('result', $result);
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.($error instanceof QueryException ? 'Disposable database operation failed.' : mb_substr($error->getMessage(), 0, 1000)).PHP_EOL);
    exit(1);
} finally {
    unset($GLOBALS['dispatch_intent_handler_pause']);
    TalktoDispatchTestHooks::reset();
    DB::purge('intent_fixture');
    DB::purge('intent_queue');
}
