<?php

// Runs one receiver application independently; all storage is in-memory SQLite.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoNonce;
use Mrezdev\LaravelTalkto\Pipelines\ProcessIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackReceiver;
use Orchestra\Testbench\Foundation\Application;

$settings = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! in_array($settings['receiver'] ?? null, ['baseline', 'current'], true)
    || ! preg_match('/^[a-z0-9-]{1,80}$/', $settings['original_message_id'] ?? '')
    || ! in_array($settings['operation'] ?? 'receive', ['receive', 'legacy_send'], true)
    || ! is_array($settings['callbacks'] ?? null)) {
    throw new RuntimeException('Requires a synthetic receiver compatibility fixture.');
}
$receiverFile = $settings['receiver'] === 'baseline'
    ? __DIR__.'/RetryCompatibilityBaseline/TalktoResultCallbackReceiver.php'
    : dirname(__DIR__, 2).'/src/Services/TalktoResultCallbackReceiver.php';
$baselineHash = '3904022b33dba526a0ad8244e8d4cbcb6d312f998fdccfb4fd3bd24ff0d4933e';
if ($settings['receiver'] === 'baseline') {
    if (hash_file('sha256', $receiverFile) !== $baselineHash) {
        throw new RuntimeException('The frozen pre-correction receiver was modified.');
    }
    require $receiverFile;
}
if (($settings['operation'] ?? 'receive') === 'legacy_send') {
    if (hash_file('sha256', __DIR__.'/RetryCompatibilityBaseline/TalktoResultCallbackMessageFactory.php') !== 'fa05d12d9c9d7d4caa28d930c62eda6e9afbe1c8a476722592867a480708503a') {
        throw new RuntimeException('The frozen pre-correction callback factory was modified.');
    }
    require __DIR__.'/RetryCompatibilityBaseline/TalktoResultCallbackMessageFactory.php';
}
$app = Application::create(options: ['extra' => ['providers' => [LaravelTalktoServiceProvider::class], 'dont-discover' => ['*']]]);
config([
    'logging.default' => 'stderr',
    'database.default' => 'sqlite',
    'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
    'talkto.database.connection' => 'sqlite',
    'talkto.service' => 'source-service',
    'talkto.callbacks.enabled' => true,
    'talkto.callbacks.reliable.enabled' => (bool) ($settings['reliable_enabled'] ?? false),
    'talkto.security.require_signature' => true,
    'talkto.security.signature_version' => 'v2',
    'talkto.security.accept_versions' => ['v2'],
    'talkto.security.replay_protection.enabled' => true,
    'talkto.security.replay_protection.require_nonce_for_v2' => true,
    'talkto.incoming.target-service' => [
        'secret' => 'synthetic-retry-receiver-secret',
        'allowed_commands' => ['talkto.result' => ['driver' => 'none']],
    ],
]);
foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $migration) {
    (require $migration)->up();
}
$payload = ['synthetic' => true];
if (($settings['operation'] ?? 'receive') === 'legacy_send') {
    config([
        'talkto.service' => 'target-service',
        'talkto.callbacks.reliable.enabled' => false,
        'talkto.callbacks.auto_dispatch' => true,
        'talkto.retry.incoming_enabled' => true,
        'talkto.retry.backoff_seconds' => [0],
        'talkto.retry.jitter_seconds' => 0,
        'talkto.outgoing.source-service' => ['url' => 'https://synthetic-receiver.invalid', 'secret' => 'synthetic-retry-receiver-secret'],
        'queue.default' => 'receiver_compatibility',
        'queue.connections.receiver_compatibility' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'callbacks', 'retry_after' => 90, 'after_commit' => false],
    ]);
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    $incoming = TalktoMessage::query()->create([
        'message_id' => $settings['original_message_id'], 'correlation_id' => 'retry-receiver-correlation',
        'direction' => 'incoming', 'source_service' => 'source-service', 'target_service' => 'target-service',
        'command' => 'domain.command', 'payload' => $payload, 'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1, 'destination_receive_status' => 'received', 'destination_action_status' => 'queued',
        'overall_status' => 'queued', 'attempts' => 0, 'retry_count' => 0, 'max_attempts' => 5,
    ]);
    $calls = 0;
    foreach (['failed_retryable', 'succeeded'] as $kind) {
        $result = $kind === 'succeeded'
            ? TalktoIncomingCommandResult::succeeded(['value' => 'legacy-success'], ['attempt' => 2])
            : TalktoIncomingCommandResult::failedRetryable('Legacy failure A', 'SyntheticError', ['attempt' => 1]);
        $resolver = new class($result, $calls)
        {
            public function __construct(private readonly IncomingCommandResultContract $result, public int &$calls) {}

            public function resolve(TalktoMessage $message): TalktoIncomingCommandHandler
            {
                return new class($this->result, $this->calls) implements TalktoIncomingCommandHandler
                {
                    public function __construct(private readonly IncomingCommandResultContract $result, public int &$calls) {}

                    public function handle(TalktoMessage $message): IncomingCommandResultContract
                    {
                        $this->calls++;

                        return $this->result;
                    }
                };
            }
        };
        app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, $resolver);
    }
    $callbacks = TalktoMessage::query()->where('direction', 'outgoing')->orderBy('id')->get();
    echo json_encode([
        'operation' => 'legacy_send', 'pid' => getmypid(), 'php_version' => PHP_VERSION, 'laravel_version' => $app->version(),
        'baseline_factory_sha256' => hash_file('sha256', __DIR__.'/RetryCompatibilityBaseline/TalktoResultCallbackMessageFactory.php'),
        'callbacks' => $callbacks->map(fn (TalktoMessage $callback): array => app(TalktoOutgoingEnvelopeBuilder::class)->build($callback))->all(),
        'callback_ids' => $callbacks->pluck('message_id')->all(), 'incoming' => $incoming->fresh()->getAttributes(),
        'handler_calls' => $calls, 'accepted_queue_publications' => DB::table('jobs')->count(),
    ], JSON_THROW_ON_ERROR);
    exit;
}
$original = TalktoMessage::query()->create([
    'message_id' => $settings['original_message_id'],
    'correlation_id' => 'retry-receiver-correlation',
    'direction' => 'outgoing',
    'source_service' => 'source-service',
    'target_service' => 'target-service',
    'command' => 'domain.command',
    'payload' => $payload,
    'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
    'schema_version' => 1,
    'source_action_status' => 'succeeded',
    'transport_status' => 'sent',
    'destination_receive_status' => 'received',
    'destination_action_status' => 'queued',
    'overall_status' => 'destination_received',
    'attempts' => 1,
    'retry_count' => 0,
    'max_attempts' => 5,
    'sent_at' => now(),
]);
$results = [];
foreach ($settings['callbacks'] as $callback) {
    $response = app(TalktoResultCallbackReceiver::class)->receiveResult($callback['envelope'], $callback['headers']);
    $results[] = ['response' => $response, 'message' => $original->fresh()->getAttributes()];
}
echo json_encode([
    'receiver' => $settings['receiver'],
    'receiver_sha256' => hash_file('sha256', $receiverFile),
    'baseline_receiver_sha256' => $baselineHash,
    'pid' => getmypid(),
    'php_version' => PHP_VERSION,
    'laravel_version' => $app->version(),
    'database' => DB::connection()->getDriverName(),
    'reliable_enabled' => (bool) config('talkto.callbacks.reliable.enabled'),
    'results' => $results,
    'events' => TalktoEvent::query()->orderBy('id')->get()->toArray(),
    'nonce_count' => TalktoNonce::query()->count(),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
