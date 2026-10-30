<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Pipelines\ProcessIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoSigner;

beforeEach(function (): void {
    RetryReceiverCompatibilityProbe::$calls = 0;
    config([
        'talkto.service' => 'target-service',
        'talkto.callbacks.enabled' => true,
        'talkto.callbacks.auto_dispatch' => true,
        'talkto.callbacks.reliable.enabled' => true,
        'talkto.dispatch_intents.enabled' => true,
        'talkto.dispatch_intents.publish_after_commit' => false,
        'talkto.retry.incoming_enabled' => true,
        'talkto.retry.backoff_seconds' => [0],
        'talkto.retry.jitter_seconds' => 0,
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.outgoing.source-service' => ['url' => 'https://synthetic-receiver.invalid', 'secret' => 'synthetic-retry-receiver-secret'],
        'queue.default' => 'retry_receiver_database',
        'queue.connections.retry_receiver_database' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'callbacks', 'retry_after' => 90, 'after_commit' => false],
    ]);
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    expect($this->artisan('migrate')->run())->toBe(0);
    foreach (glob(__DIR__.'/../../database/dispatch-intents/*.php') as $migration) {
        (require $migration)->up();
    }
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

test('signed callback peers interoperate without requiring reliable mode at the receiver', function (string $sender, string $receiver, bool $receiverReliable): void {
    $id = 'retry-peer-'.strtolower($sender).'-'.$receiver.'-'.(int) $receiverReliable;
    if ($sender === 'baseline') {
        $generated = retryReceiverChild(['operation' => 'legacy_send', 'receiver' => 'baseline', 'original_message_id' => $id, 'callbacks' => []]);
        $wires = $generated['callbacks'];
        expect($generated['baseline_factory_sha256'])->toBe('fa05d12d9c9d7d4caa28d930c62eda6e9afbe1c8a476722592867a480708503a')
            ->and($generated['handler_calls'])->toBe(2)
            ->and($generated['accepted_queue_publications'])->toBe(2)
            ->and($generated['incoming']['overall_status'])->toBe('succeeded')
            ->and($generated['callback_ids'])->toBe(['cb-'.sha1($id.'|failed_retryable'), 'cb-'.sha1($id.'|succeeded')]);
    } else {
        $wires = retryReceiverGenerate($id, ['failed_retryable', 'succeeded']);
        expect($wires[0]['envelope']['message_id'])->toBe('cb-'.sha1($id.'|failed_retryable'))
            ->and($wires[1]['envelope']['message_id'])->toStartWith('cb-a-');
    }
    $observed = retryReceiverChild(['receiver' => $receiver, 'reliable_enabled' => $receiverReliable, 'original_message_id' => $id, 'callbacks' => $wires]);

    expect($observed['receiver_sha256'])->toBe('3904022b33dba526a0ad8244e8d4cbcb6d312f998fdccfb4fd3bd24ff0d4933e')
        ->and($observed['pid'])->not->toBe(getmypid())
        ->and($observed['database'])->toBe('sqlite')
        ->and($observed['reliable_enabled'])->toBe($receiverReliable)
        ->and(array_column(array_column($observed['results'], 'response'), 'status'))->toBe(['applied', 'applied'])
        ->and($observed['results'][1]['message']['overall_status'])->toBe('completed')
        ->and($observed['results'][1]['message']['destination_action_status'])->toBe('succeeded')
        ->and($observed['results'][1]['message']['last_error'])->toBeNull()
        ->and($observed['nonce_count'])->toBe(2)
        ->and(array_keys($wires[1]['envelope']['payload']))->toBe(['original_message_id', 'original_command', 'status', 'succeeded', 'retryable', 'skipped', 'error_class', 'error_message', 'result', 'meta']);
})->with([
    'old sender and old receiver, disabled reliable mode' => ['baseline', 'baseline', false],
    'old sender and patched receiver' => ['baseline', 'current', false],
    'old sender and opted in patched receiver' => ['baseline', 'current', true],
    'patched sender and old receiver' => ['current', 'baseline', false],
    'destination only enables reliable callbacks' => ['current', 'current', false],
    'both peers use patched code and reliable configuration' => ['current', 'current', true],
]);

test('same status receivers acknowledge distinct attempt callbacks and preserve first applied details', function (string $receiver, bool $reverse): void {
    $id = 'same-status-'.$receiver.'-'.(int) $reverse;
    $wires = retryReceiverGenerate($id, ['failed_retryable', 'failed_retryable']);
    if ($reverse) {
        $wires = array_reverse($wires);
    }
    $observed = retryReceiverChild(['receiver' => $receiver, 'original_message_id' => $id, 'callbacks' => $wires]);
    $applied = array_values(array_filter($observed['events'], fn (array $event): bool => $event['event_type'] === 'result_callback_applied'));
    $duplicates = array_values(array_filter($observed['events'], fn (array $event): bool => $event['event_type'] === 'result_callback_duplicate'));

    expect($wires[0]['envelope']['message_id'])->not->toBe($wires[1]['envelope']['message_id'])
        ->and($wires[0]['envelope']['payload_hash'])->not->toBe($wires[1]['envelope']['payload_hash'])
        ->and($observed['results'][0]['response'])->toMatchArray(['accepted' => true, 'status' => 'applied', 'duplicate' => false])
        ->and($observed['results'][1]['response'])->toMatchArray(['accepted' => true, 'status' => 'duplicate', 'duplicate' => true])
        ->and($observed['results'][1]['message'])->toBe($observed['results'][0]['message'])
        ->and($observed['results'][1]['message']['last_error'])->toBe($wires[0]['envelope']['payload']['error_message'])
        ->and($applied)->toHaveCount(1)
        ->and($applied[0]['meta']['result_meta'])->toBe($wires[0]['envelope']['payload']['meta'])
        ->and($duplicates)->toHaveCount(1)
        ->and($duplicates[0]['meta'])->toBe(['callback_message_id' => $wires[1]['envelope']['message_id'], 'status' => 'failed_retryable'])
        ->and($observed['nonce_count'])->toBe(2);
})->with(['old A then B' => ['baseline', false], 'old B then A' => ['baseline', true], 'new A then B' => ['current', false], 'new B then A' => ['current', true]]);

test('A B A deliveries with fresh signatures do not oscillate the applied error or metadata', function (string $receiver): void {
    $id = 'aba-'.$receiver;
    $wires = retryReceiverGenerate($id, ['failed_retryable', 'failed_retryable']);
    $wires[] = retryReceiverResign($wires[0]);
    $observed = retryReceiverChild(['receiver' => $receiver, 'original_message_id' => $id, 'callbacks' => $wires]);

    expect(array_column(array_column($observed['results'], 'response'), 'status'))->toBe(['applied', 'duplicate', 'duplicate'])
        ->and($observed['results'][2]['message'])->toBe($observed['results'][0]['message'])
        ->and($observed['results'][2]['message']['last_error'])->toBe('Failure 1')
        ->and($observed['nonce_count'])->toBe(3)
        ->and(count(array_filter($observed['events'], fn (array $event): bool => $event['event_type'] === 'result_callback_applied')))->toBe(1)
        ->and(count(array_filter($observed['events'], fn (array $event): bool => $event['event_type'] === 'result_callback_duplicate')))->toBe(2);
})->with(['baseline', 'current']);

test('reverse callback order cannot weaken an applied terminal outcome', function (string $receiver, string $terminal): void {
    $id = 'reverse-'.$receiver.'-'.str_replace('_', '-', $terminal);
    $wires = array_reverse(retryReceiverGenerate($id, ['failed_retryable', $terminal]));
    $wires[] = retryReceiverResign($wires[1]);
    $observed = retryReceiverChild(['receiver' => $receiver, 'original_message_id' => $id, 'callbacks' => $wires]);

    expect(array_column(array_column($observed['results'], 'response'), 'status'))->toBe(['applied', 'stale_ignored', 'stale_ignored'])
        ->and($observed['results'][2]['message'])->toBe($observed['results'][0]['message'])
        ->and($observed['results'][2]['message']['overall_status'])->toBe($terminal === 'failed_final' ? 'failed_final' : 'completed')
        ->and($observed['results'][2]['message']['destination_action_status'])->toBe($terminal)
        ->and($observed['nonce_count'])->toBe(3);
})->with(['baseline', 'current'])->with(['succeeded', 'failed_final', 'skipped']);

test('success after two retryable callbacks is preserved against delayed failure redelivery', function (string $receiver): void {
    $id = 'eventual-success-'.$receiver;
    $wires = retryReceiverGenerate($id, ['failed_retryable', 'failed_retryable', 'succeeded']);
    $wires[] = retryReceiverResign($wires[0]);
    $observed = retryReceiverChild(['receiver' => $receiver, 'original_message_id' => $id, 'callbacks' => $wires]);

    expect(array_column(array_column($observed['results'], 'response'), 'status'))->toBe(['applied', 'duplicate', 'applied', 'stale_ignored'])
        ->and($observed['results'][3]['message'])->toBe($observed['results'][2]['message'])
        ->and($observed['results'][3]['message']['overall_status'])->toBe('completed')
        ->and($observed['results'][3]['message']['last_error'])->toBeNull()
        ->and($observed['nonce_count'])->toBe(4);
})->with(['baseline', 'current']);

test('old and current receivers preserve signature nonce and original relationship validation', function (string $receiver): void {
    $id = 'security-'.$receiver;
    $first = retryReceiverGenerate($id, ['succeeded'])[0];
    $badSignature = retryReceiverResign($first);
    $badSignature['headers']['X-Talkto-Signature'] = str_repeat('0', 64);
    $wrongCommand = $first;
    $wrongCommand['envelope']['payload']['original_command'] = 'different.command';
    $wrongCommand['envelope']['payload_hash'] = app(TalktoPayloadHasher::class)->hash($wrongCommand['envelope']['payload']);
    $wrongCommand = retryReceiverResign($wrongCommand);
    $observed = retryReceiverChild(['receiver' => $receiver, 'original_message_id' => $id, 'callbacks' => [$first, $first, $badSignature, $wrongCommand]]);

    expect($observed['results'][0]['response']['status'])->toBe('applied')
        ->and($observed['results'][1]['response'])->toMatchArray(['accepted' => false, 'error' => 'replay_nonce_reused'])
        ->and($observed['results'][2]['response'])->toMatchArray(['accepted' => false, 'error' => 'invalid_signature'])
        ->and($observed['results'][3]['response'])->toMatchArray(['accepted' => false, 'error' => 'callback_original_command_mismatch'])
        ->and($observed['results'][3]['message'])->toBe($observed['results'][0]['message'])
        ->and($observed['nonce_count'])->toBe(2);
})->with(['baseline', 'current']);

function retryReceiverGenerate(string $id, array $kinds): array
{
    $payload = ['synthetic' => true];
    $incoming = TalktoMessage::query()->create([
        'message_id' => $id, 'correlation_id' => 'retry-receiver-correlation', 'direction' => 'incoming',
        'source_service' => 'source-service', 'target_service' => 'target-service', 'command' => 'domain.command',
        'payload' => $payload, 'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload), 'schema_version' => 1,
        'destination_receive_status' => 'received', 'destination_action_status' => 'queued', 'overall_status' => 'queued',
        'attempts' => 0, 'retry_count' => 0, 'max_attempts' => 5,
    ]);
    foreach ($kinds as $index => $kind) {
        $meta = ['attempt' => $index + 1, 'nested' => ['unicode' => 'نتیجه ✓', 'empty' => [], 'null' => null]];
        $result = match ($kind) {
            'failed_retryable' => TalktoIncomingCommandResult::failedRetryable('Failure '.($index + 1), 'SyntheticError', $meta),
            'failed_final' => TalktoIncomingCommandResult::failedFinal('Final '.($index + 1), 'SyntheticError', $meta),
            'skipped' => TalktoIncomingCommandResult::skipped('Skipped.', $meta),
            default => TalktoIncomingCommandResult::succeeded(['attempt' => $index + 1], $meta),
        };
        $resolver = new class($result)
        {
            public function __construct(private readonly IncomingCommandResultContract $result) {}

            public function resolve(TalktoMessage $message): TalktoIncomingCommandHandler
            {
                return new class($this->result) implements TalktoIncomingCommandHandler
                {
                    public function __construct(private readonly IncomingCommandResultContract $result) {}

                    public function handle(TalktoMessage $message): IncomingCommandResultContract
                    {
                        RetryReceiverCompatibilityProbe::$calls++;

                        return $this->result;
                    }
                };
            }
        };
        app(ProcessIncomingTalktoMessagePipeline::class)->process($incoming->id, $resolver);
    }
    $callbacks = TalktoMessage::query()->where('direction', 'outgoing')->where('parent_message_id', $id)->orderBy('id')->get();
    expect($callbacks)->toHaveCount(count($kinds))
        ->and(RetryReceiverCompatibilityProbe::$calls)->toBe(count($kinds));

    return $callbacks->map(fn (TalktoMessage $callback): array => app(TalktoOutgoingEnvelopeBuilder::class)->build($callback))->all();
}

function retryReceiverResign(array $wire): array
{
    $envelope = $wire['envelope'];
    $timestamp = now()->toIso8601String();
    $nonce = (string) Str::uuid();
    $wire['headers']['X-Talkto-Timestamp'] = $timestamp;
    $wire['headers']['X-Talkto-Nonce'] = $nonce;
    $wire['headers']['X-Talkto-Payload-Hash'] = $envelope['payload_hash'];
    $wire['headers']['X-Talkto-Signature'] = app(TalktoSigner::class)->signV2($timestamp, $nonce, $envelope['message_id'], $envelope['source'], $envelope['target'], $envelope['command'], $envelope['payload_hash'], 'synthetic-retry-receiver-secret');

    return $wire;
}

function retryReceiverChild(array $settings): array
{
    $process = proc_open([PHP_BINARY, __DIR__.'/../Fixtures/RetryReceiverCompatibilityWorker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (! is_resource($process)) {
        throw new RuntimeException('Receiver application failed to start.');
    }
    fwrite($pipes[0], json_encode($settings, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('Synthetic receiver worker failed: '.mb_substr($stderr.' '.$stdout, 0, 3000));
    }
    expect($exit)->toBe(0, $stderr);
    $observed = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    $evidencePath = getenv('TALKTO_RETRY_RECEIVER_EVIDENCE_PATH');
    if (is_string($evidencePath) && $evidencePath !== '') {
        $records = is_file($evidencePath) ? json_decode(file_get_contents($evidencePath), true, flags: JSON_THROW_ON_ERROR) : [];
        $records[] = $observed;
        file_put_contents($evidencePath, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    return $observed;
}

class RetryReceiverCompatibilityProbe
{
    public static int $calls = 0;
}
