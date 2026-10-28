<?php

// Standalone application process for a parent-owned synthetic MySQL database.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Pipelines\SendOutgoingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackReceiver;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;
use Orchestra\Testbench\Foundation\Application;

$options = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$database = $options['connection']['database'] ?? '';
$directory = $options['barrier_directory'] ?? '';
$worker = $options['worker'] ?? '';

if (getenv('TALKTO_MYSQL_ENABLED') !== '1'
    || ! preg_match('/^talkto_terminal_race_[a-f0-9]{24}$/', $database)
    || ! is_dir($directory)
    || ! preg_match('/^[a-z][a-z0-9-]*$/', $worker)
    || ! in_array($options['operation'] ?? null, ['send', 'callback', 'duplicate'], true)) {
    throw new RuntimeException('This fixture requires the parent-created terminal-state race database and barriers.');
}

$write = function (string $marker, array $value = []) use ($directory, $worker): void {
    $path = $directory.'/'.$worker.'.'.$marker.'.json';
    file_put_contents($path.'.writing', json_encode($value, JSON_THROW_ON_ERROR));
    rename($path.'.writing', $path);
};
$wait = function (string $marker) use ($directory, $worker): void {
    $deadline = microtime(true) + 15;
    while (! is_file($directory.'/'.$worker.'.'.$marker)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for '.$worker.'.'.$marker);
        }
        usleep(10000);
        clearstatcache();
    }
};
$result = ['worker' => $worker, 'operation' => $options['operation'], 'pid' => getmypid(), 'http_calls' => 0, 'queries' => []];
$failurePhase = false;

try {
    $app = Application::create(options: ['extra' => ['providers' => [LaravelTalktoServiceProvider::class], 'dont-discover' => ['*']]]);
    config([
        'app.timezone' => 'UTC', 'database.default' => 'terminal_race',
        'database.connections.terminal_race' => $options['connection'],
        'talkto.database.connection' => 'terminal_race', 'talkto.service' => 'source-service',
        'talkto.security.require_signature' => true,
        'talkto.security.signature_version' => 'v2', 'talkto.security.accept_versions' => ['v2'],
        'talkto.outgoing.target-service' => ['url' => 'https://terminal-race.invalid', 'secret' => 'synthetic-race-secret'],
        'talkto.incoming.target-service' => ['secret' => 'synthetic-race-secret', 'allowed_commands' => ['talkto.result' => ['driver' => 'none']]],
        'talkto.retry.enabled' => true, 'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.max_attempts' => 5, 'talkto.retry.backoff_seconds' => [10, 30, 60], 'talkto.retry.jitter_seconds' => 0,
        'talkto.dead_letter.enabled' => true, 'talkto.dead_letter.auto_store_on_final_failure' => true,
        'queue.default' => 'sync',
    ]);
    date_default_timezone_set('UTC');
    Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'UTC'));
    $db = DB::connection('terminal_race');
    $db->statement('SET SESSION innodb_lock_wait_timeout = 12');
    $connectionId = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $result['connection_id'] = $connectionId;
    $result['php_version'] = PHP_VERSION;
    $result['laravel_version'] = $app->version();
    $result['pipeline_sha256'] = hash_file('sha256', (new ReflectionClass(SendOutgoingTalktoMessagePipeline::class))->getFileName());
    $write('connected', ['connection_id' => $connectionId, 'pid' => getmypid()]);
    $locked = false;
    $lockAttempted = false;
    $terminalWritten = false;
    $snapshot = fn (): array => (array) $db->table('talkto_messages')->where('id', $options['message_id'])->first([
        'id', 'overall_status', 'destination_action_status', 'transport_status', 'completed_at', 'failed_at',
        'last_error', 'last_http_status', 'last_response', 'next_retry_at', 'next_attempt_at', 'retry_count',
        'locked_at', 'locked_by', 'attempts', 'updated_at', 'destination_receive_status', 'sent_at',
    ]);
    $db->beforeExecuting(function (string $sql) use (&$lockAttempted, &$failurePhase, $options, $write, $connectionId): void {
        if (! $lockAttempted && str_contains(strtolower($sql), 'for update')
            && ($options['operation'] === 'callback' || $failurePhase)) {
            $lockAttempted = true;
            $write('lock-attempt', ['connection_id' => $connectionId, 'sql' => $sql, 'time' => microtime(true)]);
        }
    });
    DB::listen(function (QueryExecuted $query) use (&$result, &$locked, &$terminalWritten, &$failurePhase, $options, $write, $wait, $connectionId, $snapshot): void {
        $sql = strtolower($query->sql);
        if (! str_contains($sql, 'talkto_messages')) {
            return;
        }
        $result['queries'][] = ['sql' => $query->sql, 'elapsed_ms' => $query->time];
        if (! $locked && str_contains($sql, 'for update') && ($options['operation'] === 'callback' || $failurePhase)) {
            $locked = true;
            $write('locked', ['connection_id' => $connectionId, 'sql' => $query->sql, 'time' => microtime(true)]);
            if ($options['pause_after_lock'] ?? false) {
                $wait('release-lock');
            }
        }
        if (! $terminalWritten && $options['operation'] === 'callback' && str_starts_with($sql, 'update `talkto_messages`')) {
            $terminalWritten = true;
            $result['terminal_state_inside_transaction'] = $snapshot();
            $write('terminal-state', $result['terminal_state_inside_transaction']);
        }
    });

    if ($options['operation'] === 'callback') {
        $message = TalktoMessage::query()->findOrFail($options['message_id']);
        $payload = [
            'original_message_id' => $message->message_id, 'original_command' => $message->command,
            'status' => 'succeeded', 'succeeded' => true, 'retryable' => false, 'skipped' => false,
            'result' => ['processed' => true], 'meta' => ['fixture' => 'terminal-race'],
        ];
        $envelope = [
            'message_id' => 'callback-'.$message->message_id, 'parent_message_id' => $message->message_id,
            'source' => 'target-service', 'target' => 'source-service', 'command' => 'talkto.result',
            'payload' => $payload, 'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        ];
        // Signature freshness uses wall time; persisted lifecycle dates are
        // frozen separately so the retry backoff remains deterministic.
        $timestamp = gmdate(DATE_ATOM);
        $nonce = 'nonce-'.$worker;
        $signature = app(TalktoSigner::class)->signV2($timestamp, $nonce, $envelope['message_id'], $envelope['source'], $envelope['target'], $envelope['command'], $envelope['payload_hash'], 'synthetic-race-secret');
        $result['callback_result'] = app(TalktoResultCallbackReceiver::class)->receiveResult($envelope, [
            'X-Talkto-Signature' => $signature, 'X-Talkto-Timestamp' => $timestamp,
            'X-Talkto-Message-Id' => $envelope['message_id'], 'X-Talkto-Signature-Version' => 'v2',
            'X-Talkto-Payload-Hash' => $envelope['payload_hash'], 'X-Talkto-Nonce' => $nonce,
        ]);
    } else {
        $client = new class($options, $write, $wait, $result, $failurePhase) implements TalktoHttpClient
        {
            public function __construct(private array $options, private Closure $write, private Closure $wait, private array &$result, private bool &$failurePhase) {}

            public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
            {
                $this->result['http_calls']++;
                ($this->write)('http-ready', ['connection_id' => $this->result['connection_id'], 'message_id' => $envelope['message_id']]);
                ($this->wait)('release-http');
                $this->failurePhase = true;
                if ($this->options['failure'] === 'timeout') {
                    throw new ConnectionException('Synthetic terminal race connection timeout.');
                }

                return new TalktoHttpResponse((int) $this->options['failure'], 'Synthetic HTTP failure response.');
            }
        };
        app()->instance(TalktoHttpClient::class, $client);
        (new SendTalktoMessage((int) $options['message_id']))->handle(app(TalktoOutgoingEnvelopeBuilder::class));
    }

    $result['final_state'] = $snapshot();
    $result['finished_at'] = microtime(true);
    $write('result', $result);
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    $result['error'] = ['class' => $exception::class, 'code' => (string) $exception->getCode(), 'message' => $exception instanceof QueryException ? ($exception->errorInfo[2] ?? 'Database query failed.') : $exception->getMessage()];
    $write('result', $result);
    fwrite(STDERR, json_encode($result['error'], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
} finally {
    Carbon::setTestNow();
    DB::purge('terminal_race');
}
