<?php

use Illuminate\Support\Facades\Log;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Pipelines\ProcessIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Pipelines\SendOutgoingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionRegistry;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Services\TalktoCurrentServiceGuard;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelHealthStatus;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelMessageFilters;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'website',
        'talkto.retry.retryable_statuses' => ['failed_retryable'],
        'talkto.outgoing.inventory' => [
            'url' => 'https://inventory.test',
            'secret' => 'inventory-secret',
            'endpoint' => '/api/talkto/receive',
        ],
        'talkto.incoming.billing' => [
            'secret' => 'billing-secret',
            'allowed_commands' => [
                'billing.invoice-paid' => ['driver' => 'handler'],
            ],
        ],
    ]);

    SharedStorageSendPipeline::$messageId = null;
    SharedStorageProcessPipeline::$messageId = null;
});

test('current service guard recognizes owned outgoing and incoming messages', function (): void {
    $guard = app(TalktoCurrentServiceGuard::class);
    $outgoing = sharedStorageMessage('guard-out', 'outgoing', 'pending', [
        'source_service' => 'website',
        'target_service' => 'inventory',
    ]);
    $incoming = sharedStorageMessage('guard-in', 'incoming', 'queued', [
        'source_service' => 'billing',
        'target_service' => 'website',
    ]);
    $unrelated = sharedStorageMessage('guard-other', 'outgoing', 'pending', [
        'source_service' => 'crm',
        'target_service' => 'inventory',
    ]);

    expect($guard->ownsOutgoing($outgoing))->toBeTrue()
        ->and($guard->ownsIncoming($incoming))->toBeTrue()
        ->and($guard->owns($unrelated))->toBeFalse()
        ->and($guard->involvesCurrentService($unrelated))->toBeFalse();
});

test('outgoing job skips messages owned by another service without mutating them', function (): void {
    app()->instance(SendOutgoingTalktoMessagePipeline::class, new SharedStorageSendPipeline);
    Log::spy();

    $message = sharedStorageMessage('job-out-other', 'outgoing', 'failed_retryable', [
        'source_service' => 'crm',
        'target_service' => 'inventory',
        'next_retry_at' => now()->subMinute(),
    ]);

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect(SharedStorageSendPipeline::$messageId)->toBeNull()
        ->and($message->fresh()->overall_status)->toBe('failed_retryable');

    Log::shouldHaveReceived('warning')->once();
});

test('outgoing job delegates owned messages and can opt out of storage enforcement', function (): void {
    app()->instance(SendOutgoingTalktoMessagePipeline::class, new SharedStorageSendPipeline);

    $owned = sharedStorageMessage('job-out-owned', 'outgoing', 'pending', [
        'source_service' => 'website',
        'target_service' => 'inventory',
    ]);

    (new SendTalktoMessage($owned->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect(SharedStorageSendPipeline::$messageId)->toBe($owned->id);

    SharedStorageSendPipeline::$messageId = null;
    config(['talkto.storage.enforce_current_service' => false]);

    $foreign = sharedStorageMessage('job-out-foreign-allowed', 'outgoing', 'pending', [
        'source_service' => 'crm',
        'target_service' => 'inventory',
    ]);

    (new SendTalktoMessage($foreign->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect(SharedStorageSendPipeline::$messageId)->toBe($foreign->id);
});

test('incoming job skips messages not targeted to the current service', function (): void {
    app()->instance(ProcessIncomingTalktoMessagePipeline::class, new SharedStorageProcessPipeline);
    Log::spy();

    $message = sharedStorageMessage('job-in-other', 'incoming', 'queued', [
        'source_service' => 'billing',
        'target_service' => 'crm',
        'command' => 'billing.invoice-paid',
    ]);

    (new ProcessIncomingTalktoMessage($message->id))->handle();

    expect(SharedStorageProcessPipeline::$messageId)->toBeNull()
        ->and($message->fresh()->overall_status)->toBe('queued');

    Log::shouldHaveReceived('warning')->once();
});

test('panel message query scopes to current service by default and can be disabled for central observers', function (): void {
    sharedStorageMessage('panel-owned-out', 'outgoing', 'completed', [
        'source_service' => 'website',
        'target_service' => 'inventory',
        'created_at' => now()->subMinutes(3),
    ]);
    sharedStorageMessage('panel-owned-in', 'incoming', 'completed', [
        'source_service' => 'billing',
        'target_service' => 'website',
        'created_at' => now()->subMinutes(2),
    ]);
    $foreign = sharedStorageMessage('panel-foreign', 'outgoing', 'completed', [
        'source_service' => 'crm',
        'target_service' => 'inventory',
        'created_at' => now()->subMinute(),
    ]);

    $query = app(TalktoPanelMessageQuery::class);

    expect($query->latest(10)->pluck('message_id')->all())->toBe(['panel-owned-in', 'panel-owned-out'])
        ->and($query->findMessage('panel-foreign'))->toBeNull()
        ->and($query->paginate(TalktoPanelMessageFilters::fromArray([]), 10)->total())->toBe(2);

    config(['talkto.panel.scope.current_service_only' => false]);

    expect($query->latest(10)->pluck('message_id')->all())->toContain('panel-foreign')
        ->and($query->findMessage($foreign->id)?->message_id)->toBe('panel-foreign')
        ->and($query->paginate(TalktoPanelMessageFilters::fromArray([]), 10)->total())->toBe(3);
});

test('connection health ignores third party rows in a shared database', function (): void {
    sharedStorageMessage('health-out-owned', 'outgoing', 'completed', [
        'source_service' => 'website',
        'target_service' => 'inventory',
        'completed_at' => now()->subMinutes(5),
    ]);
    $thirdPartyOutgoing = sharedStorageMessage('health-out-third-party', 'outgoing', 'failed_final', [
        'source_service' => 'crm',
        'target_service' => 'inventory',
        'failed_at' => now()->subMinutes(3),
    ]);
    sharedStorageDeadLetter($thirdPartyOutgoing, [
        'source' => 'crm',
        'target' => 'inventory',
    ]);
    sharedStorageMessage('health-in-owned', 'incoming', 'completed', [
        'source_service' => 'billing',
        'target_service' => 'website',
        'command' => 'billing.invoice-paid',
        'completed_at' => now()->subMinutes(4),
    ]);
    sharedStorageMessage('health-in-third-party', 'incoming', 'failed_final', [
        'source_service' => 'billing',
        'target_service' => 'crm',
        'command' => 'billing.invoice-paid',
        'failed_at' => now()->subMinutes(2),
    ]);

    $registry = app(TalktoPanelConnectionRegistry::class);
    $checker = app(TalktoPanelConnectionHealthChecker::class);

    $outgoing = $checker->check($registry->outgoing()->firstWhere('service', 'inventory'));
    $incoming = $checker->check($registry->incoming()->firstWhere('service', 'billing'));

    expect($outgoing->status)->toBe(TalktoPanelHealthStatus::Healthy)
        ->and($outgoing->recentMessages)->toBe(1)
        ->and($outgoing->deadLetters)->toBe(0)
        ->and($incoming->status)->toBe(TalktoPanelHealthStatus::Healthy)
        ->and($incoming->recentMessages)->toBe(1);
});

class SharedStorageSendPipeline extends SendOutgoingTalktoMessagePipeline
{
    public static ?int $messageId = null;

    public function send(int $talktoMessageId, TalktoOutgoingEnvelopeBuilder $builder, ?TalktoRetryPolicy $retryPolicy = null): void
    {
        self::$messageId = $talktoMessageId;
    }
}

class SharedStorageProcessPipeline extends ProcessIncomingTalktoMessagePipeline
{
    public static ?int $messageId = null;

    public function process(int $talktoMessageId, mixed $resolver = null, ?TalktoRetryPolicy $retryPolicy = null): void
    {
        self::$messageId = $talktoMessageId;
    }
}

function sharedStorageMessage(string $messageId, string $direction, string $status, array $attributes = []): TalktoMessage
{
    $createdAt = $attributes['created_at'] ?? now();

    $message = TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'corr-'.$messageId,
        'direction' => $direction,
        'source_service' => $direction === 'incoming' ? 'billing' : 'website',
        'target_service' => $direction === 'outgoing' ? 'inventory' : 'website',
        'command' => $direction === 'incoming' ? 'billing.invoice-paid' : 'verify-invoice',
        'business_key' => null,
        'idempotency_key' => null,
        'payload' => ['id' => $messageId],
        'payload_hash' => 'hash-'.$messageId,
        'schema_version' => 1,
        'source_action_status' => $direction === 'outgoing' ? $status : null,
        'transport_status' => $direction === 'outgoing' ? $status : null,
        'destination_receive_status' => $direction === 'incoming' ? 'received' : null,
        'destination_action_status' => $direction === 'incoming' ? $status : null,
        'overall_status' => $status,
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 3,
        'next_retry_at' => null,
        'completed_at' => in_array($status, ['completed', 'succeeded'], true) ? $createdAt : null,
        'failed_at' => str_starts_with($status, 'failed') ? $createdAt : null,
    ], array_diff_key($attributes, array_flip(['created_at', 'updated_at']))));

    $message->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $attributes['updated_at'] ?? $createdAt,
    ])->save();

    return $message->fresh();
}

function sharedStorageDeadLetter(TalktoMessage $message, array $attributes = []): TalktoDeadLetter
{
    return TalktoDeadLetter::query()->create(array_merge([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'direction' => $message->direction,
        'source' => $message->source_service,
        'target' => $message->target_service,
        'command' => $message->command,
        'failed_status' => 'failed_final',
        'status' => 'open',
    ], $attributes));
}
