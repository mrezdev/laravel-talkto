<?php

// Only a parent-owned synthetic database is accepted. Credentials arrive on stdin.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
use Mrezdev\LaravelTalkto\Services\TalktoUndispatchedInspector;
use Orchestra\Testbench\Foundation\Application;

$options = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (getenv('TALKTO_MYSQL_ENABLED') !== '1'
    || ! preg_match('/^talkto_undispatched_[a-f0-9]{24}$/', $options['connection']['database'] ?? '')
    || ! preg_match('/^talkto_undispatched_queue_[a-f0-9]{24}$/', $options['queue_connection']['database'] ?? '')
    || ! is_dir($options['directory'] ?? '')
    || ! preg_match('/^[a-z0-9-]+$/', $options['worker'] ?? '')) {
    throw new RuntimeException('Requires the parent-owned dispatch-gap fixture databases.');
}
$write = function (string $marker, array $value = []) use ($options): void {
    $path = $options['directory'].'/'.$options['worker'].'.'.$marker.'.json';
    file_put_contents($path.'.writing', json_encode($value, JSON_THROW_ON_ERROR));
    rename($path.'.writing', $path);
};
$wait = function (string $marker) use ($options): void {
    $deadline = microtime(true) + 15;
    while (! is_file($options['directory'].'/'.$options['worker'].'.'.$marker)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Bounded fixture barrier expired.');
        }
        usleep(10000);
        clearstatcache();
    }
};

class UndispatchedProbeHandler implements TalktoIncomingCommandHandler
{
    public static ?Closure $duringHandle = null;

    public function handle(TalktoMessage $message): IncomingCommandResultContract
    {
        DB::connection('undispatched')->table('dispatch_probe_metrics')->where('name', 'handler_calls')->increment('value');
        if (self::$duringHandle !== null) {
            (self::$duringHandle)();
        }

        return TalktoIncomingCommandResult::succeeded(['synthetic' => true]);
    }
}

class UndispatchedCustomSendJob extends SendTalktoMessage {}

try {
    $app = Application::create(options: ['extra' => ['providers' => [LaravelTalktoServiceProvider::class], 'dont-discover' => ['*']]]);
    config([
        'database.default' => 'undispatched', 'database.connections.undispatched' => $options['connection'],
        'database.connections.undispatched_queue' => $options['queue_connection'],
        'database.connections.undispatched_source' => $options['queue_connection'],
        'talkto.database.connection' => 'undispatched', 'talkto.service' => 'base',
        'talkto.outgoing.peer' => ['url' => 'https://undispatched.invalid', 'secret' => 'synthetic-dispatch-probe'],
        'talkto.incoming.peer' => ['secret' => 'synthetic-dispatch-probe', 'allowed_commands' => ['domain.command' => ['handler' => UndispatchedProbeHandler::class], 'talkto.result' => ['driver' => 'none']]],
        'talkto.security.require_signature' => true, 'talkto.security.signature_version' => 'v2', 'talkto.security.accept_versions' => ['v2'],
        'talkto.callbacks.auto_dispatch' => false,
        'queue.default' => 'probe',
        'queue.connections.probe' => ['driver' => 'database', 'connection' => ($options['same_connection'] ?? false) ? 'undispatched' : 'undispatched_queue', 'table' => 'jobs', 'queue' => 'probe', 'retry_after' => 120, 'after_commit' => false],
        'queue.connections.sync' => ['driver' => 'sync', 'after_commit' => false],
    ]);
    $db = DB::connection('undispatched');
    if ($options['custom_job'] ?? false) {
        config(['talkto.jobs.send_message' => UndispatchedCustomSendJob::class]);
    }
    $queueDb = DB::connection(($options['same_connection'] ?? false) ? 'undispatched' : 'undispatched_queue');
    $result = ['worker' => $options['worker'], 'operation' => $options['operation'], 'php_version' => PHP_VERSION,
        'laravel_version' => $app->version(), 'connection_id' => (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id,
        'queue_connection_id' => (int) $queueDb->selectOne('SELECT CONNECTION_ID() AS id')->id, 'exceptions' => [], 'responses' => []];
    $action = $options['operation'];
    $queueMode = $options['queue_mode'] ?? 'normal';
    if ($queueMode === 'missing-table') {
        config(['queue.connections.probe.table' => 'deliberately_missing_jobs']);
    } elseif ($queueMode !== 'normal') {
        Queue::extend('dispatch_probe', function () use ($queueDb, $queueMode, $write, $wait) {
            $connection = new class($queueDb, 'jobs', 'probe', 120, false, $queueMode, $write, $wait) extends DatabaseQueue
            {
                public function __construct($database, $table, $default, $retryAfter, $dispatchAfterCommit, private string $mode, private Closure $write, private Closure $wait)
                {
                    parent::__construct($database, $table, $default, $retryAfter, $dispatchAfterCommit);
                }

                public function push($job, $data = '', $queue = null)
                {
                    if ($this->mode === 'before') {
                        ($this->write)('before-publication');
                        ($this->wait)('release');
                    }
                    if ($this->mode === 'unavailable') {
                        throw new RuntimeException('Synthetic queue connection unavailable before publication.');
                    }
                    $id = parent::push($job, $data, $queue);
                    if (in_array($this->mode, ['after', 'after-lost-ack'], true)) {
                        ($this->write)('after-publication');
                        ($this->wait)('release');
                    }
                    if (in_array($this->mode, ['lost-ack', 'after-lost-ack'], true)) {
                        throw new RuntimeException('Synthetic acknowledgement lost after actual database enqueue.');
                    }

                    return $id;
                }
            };

            return new class($connection) implements ConnectorInterface
            {
                public function __construct(private QueueContract $queue) {}

                public function connect(array $config)
                {
                    return $this->queue;
                }
            };
        });
        config(['queue.connections.probe.driver' => 'dispatch_probe']);
    }
    app()->instance(TalktoHttpClient::class, new class implements TalktoHttpClient
    {
        public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
        {
            DB::connection('undispatched')->table('dispatch_probe_metrics')->where('name', 'http_calls')->increment('value');

            return new TalktoHttpResponse(200, '{"received":true,"status":"queued"}');
        }
    });
    $createFlow = fn (): TalktoFlowBuilder => (new TalktoFlowBuilder('dispatch-probe', app(TalktoOutgoingMessageFactory::class)))
        ->to('peer')->command('domain.command')->payload(['synthetic' => true])
        ->options(['message_id' => $options['message_id'] ?? $options['worker']]);
    $receive = function (string $id, string $nonce, string $variant = 'valid') use (&$result): void {
        $payload = ['synthetic' => true];
        $envelope = ['message_id' => $id, 'source' => 'peer', 'target' => $variant === 'wrong-target' ? 'other' : 'base',
            'command' => $variant === 'unauthorized' ? 'forbidden.command' : 'domain.command',
            'payload' => $payload, 'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload), 'idempotency_key' => $variant === 'idempotent' ? 'same-idempotency' : null];
        $timestamp = gmdate(DATE_ATOM);
        $signature = app(TalktoSigner::class)->signV2($timestamp, $nonce, $id, $envelope['source'], $envelope['target'], $envelope['command'], $envelope['payload_hash'], 'synthetic-dispatch-probe');
        $headers = ['X-Talkto-Signature' => $variant === 'invalid-hmac' ? str_repeat('0', 64) : $signature,
            'X-Talkto-Signature-Version' => 'v2', 'X-Talkto-Timestamp' => $timestamp, 'X-Talkto-Message-Id' => $id,
            'X-Talkto-Payload-Hash' => $envelope['payload_hash'], 'X-Talkto-Nonce' => $nonce];
        $request = Request::create('/talkto/receive', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($envelope, JSON_THROW_ON_ERROR));
        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }
        $response = app(ReceiveIncomingTalktoMessagePipeline::class)->receive($request, app(TalktoSignatureVerifier::class));
        $result['responses'][] = ['http_status' => $response->getStatusCode(), 'body' => $response->getData(true)];
    };

    try {
        if ($action === 'factory') {
            app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', ['synthetic' => true], ['message_id' => $options['message_id']]);
        } elseif ($action === 'send') {
            $createFlow()->send();
        } elseif ($action === 'send-delayed') {
            $message = app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', ['synthetic' => true], ['message_id' => $options['message_id']]);
            SendTalktoMessage::dispatch($message->id)->delay(now()->addHour());
        } elseif ($action === 'nested-commit' || $action === 'nested-rollback') {
            $db->beginTransaction();
            $db->beginTransaction();
            $createFlow()->send();
            $result['jobs_before_inner_commit'] = $queueDb->table('jobs')->count();
            $db->commit();
            $result['jobs_before_outer_resolution'] = $queueDb->table('jobs')->count();
            $action === 'nested-commit' ? $db->commit() : $db->rollBack();
        } elseif ($action === 'source-rollback-gap') {
            config(['database.default' => 'undispatched_source']);
            $source = DB::connection('undispatched_source');
            $source->beginTransaction();
            $createFlow()->run(fn (): array => ['synthetic' => true]);
            $source->rollBack();
        } elseif ($action === 'receive') {
            $receive($options['message_id'], 'nonce-'.$options['worker']);
        } elseif ($action === 'receive-duplicate-jobs') {
            $receive($options['message_id'], 'nonce-'.$options['worker']);
            ProcessIncomingTalktoMessage::dispatch(TalktoMessage::query()->where('message_id', $options['message_id'])->sole()->id);
        } elseif ($action === 'receive-security') {
            $receive('valid-incoming', 'nonce-valid');
            $receive('valid-incoming', 'nonce-valid');
            $receive('replayed-incoming', 'nonce-valid');
            $receive('bad-hmac', 'nonce-hmac', 'invalid-hmac');
            $receive('bad-target', 'nonce-target', 'wrong-target');
            $receive('bad-command', 'nonce-command', 'unauthorized');
            $receive('idempotent-one', 'nonce-idempotent-1', 'idempotent');
            $receive('idempotent-two', 'nonce-idempotent-2', 'idempotent');
        } elseif ($action === 'callback') {
            $message = TalktoMessage::query()->where('message_id', $options['message_id'])->sole();
            $payload = ['original_message_id' => $message->message_id, 'original_command' => $message->command,
                'status' => 'succeeded', 'succeeded' => true, 'retryable' => false, 'skipped' => false,
                'result' => ['synthetic' => true], 'meta' => []];
            $envelope = ['message_id' => 'callback-'.$message->message_id, 'parent_message_id' => $message->message_id,
                'source' => 'peer', 'target' => 'base', 'command' => 'talkto.result', 'payload' => $payload,
                'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload)];
            $timestamp = gmdate(DATE_ATOM);
            $nonce = 'nonce-'.$options['worker'];
            $signature = app(TalktoSigner::class)->signV2($timestamp, $nonce, $envelope['message_id'], 'peer', 'base', 'talkto.result', $envelope['payload_hash'], 'synthetic-dispatch-probe');
            $result['callback_result'] = app(TalktoResultCallbackReceiver::class)->receiveResult($envelope, [
                'X-Talkto-Signature' => $signature, 'X-Talkto-Signature-Version' => 'v2', 'X-Talkto-Timestamp' => $timestamp,
                'X-Talkto-Message-Id' => $envelope['message_id'], 'X-Talkto-Payload-Hash' => $envelope['payload_hash'], 'X-Talkto-Nonce' => $nonce]);
        } elseif ($action === 'sync-send') {
            config(['queue.default' => 'sync']);
            $db->beginTransaction();
            $createFlow()->send();
            $result['http_before_commit'] = $db->table('dispatch_probe_metrics')->where('name', 'http_calls')->value('value');
            $db->commit();
        } elseif (in_array($action, ['consume', 'reserve', 'process-paused'], true)) {
            $job = Queue::connection('probe')->pop('probe');
            $result['popped_job'] = $job !== null;
            if ($job !== null) {
                $result['consumed_job_name'] = $job->resolveName();
                if ($action === 'reserve') {
                    $write('reserved', ['job_id' => $job->getJobId()]);
                    $wait('release');
                } else {
                    if ($action === 'process-paused') {
                        UndispatchedProbeHandler::$duringHandle = function () use ($write, $wait): void {
                            $write('processing');
                            $wait('release');
                        };
                    }
                    $db->table('dispatch_probe_metrics')->where('name', 'job_executions')->increment('value');
                    $job->fire();
                    if (! $job->isDeleted()) {
                        $job->delete();
                    }
                }
            }
        } elseif ($action === 'inspect' || $action === 'execute-attempt') {
            $write('ready');
            if ($options['pause'] ?? false) {
                $wait('release');
            }
            $db->enableQueryLog();
            $started = hrtime(true);
            $result['report'] = app(TalktoUndispatchedInspector::class)->inspect(null, $options['message_id'] ?? null, 0, 100);
            $result['inspect_elapsed_ms'] = (hrtime(true) - $started) / 1000000;
            $result['inspect_queries'] = array_map(fn ($query): array => ['sql' => $query['query'], 'time_ms' => $query['time']], $db->getQueryLog());
            $db->disableQueryLog();
            if ($action === 'execute-attempt') {
                $result['command_exit'] = Artisan::call('talkto:recover-undispatched', ['--execute' => true]);
                $result['command_output'] = Artisan::output();
            }
        } else {
            throw new RuntimeException('Unknown fixture operation.');
        }
    } catch (Throwable $error) {
        $result['exceptions'][] = ['class' => $error::class, 'message' => $error instanceof QueryException ? 'Synthetic queue/database operation failed.' : $error->getMessage()];
    }
    $result['messages'] = $db->table('talkto_messages')->orderBy('id')->get(['id', 'message_id', 'direction', 'source_service', 'target_service', 'overall_status', 'transport_status', 'destination_action_status', 'retry_count', 'attempts', 'locked_at', 'locked_by'])->map(fn ($row) => (array) $row)->all();
    $result['jobs'] = $queueDb->table('jobs')->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at'])->map(fn ($row) => (array) $row)->all();
    $result['metrics'] = $db->table('dispatch_probe_metrics')->pluck('value', 'name')->all();
    $result['events'] = $db->table('talkto_events')->count();
    $result['dead_letters'] = $db->table('talkto_dead_letters')->count();
    $result['peak_php_allocated_bytes'] = memory_get_peak_usage(true);
    $result['peak_php_used_bytes'] = memory_get_peak_usage(false);
    $write('result', $result);
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.($error instanceof QueryException ? 'Disposable database fixture operation failed.' : $error->getMessage()).PHP_EOL);
    exit(1);
}
