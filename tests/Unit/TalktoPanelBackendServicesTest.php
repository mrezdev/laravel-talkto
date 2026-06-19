<?php

use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionRegistry;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelHealthStatus;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelMessageFilters;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'panel-test',
        'talkto.outgoing' => [],
        'talkto.incoming' => [
            'handlers' => [],
            'unknown_command_strategy' => 'fail',
        ],
        'talkto.retry.retryable_statuses' => ['failed_retryable'],
    ]);
});

test('panel message filters normalize empty strings and export arrays', function (): void {
    $filters = TalktoPanelMessageFilters::fromArray([
        'direction' => ' outgoing ',
        'status' => '',
        'service' => 'target-alpha',
        'command' => 'domain.sync',
        'message_id' => ' ',
        'correlationId' => 'corr-123',
        'business_key' => 'business-123',
        'idempotencyKey' => '',
        'created_from' => '2026-01-01 00:00:00',
        'createdTo' => '2026-01-02 00:00:00',
    ]);

    expect($filters->direction)->toBe('outgoing')
        ->and($filters->status)->toBeNull()
        ->and($filters->messageId)->toBeNull()
        ->and($filters->correlationId)->toBe('corr-123')
        ->and($filters->idempotencyKey)->toBeNull()
        ->and($filters->toArray())->toMatchArray([
            'direction' => 'outgoing',
            'status' => null,
            'service' => 'target-alpha',
            'command' => 'domain.sync',
            'message_id' => null,
            'correlation_id' => 'corr-123',
            'business_key' => 'business-123',
            'idempotency_key' => null,
            'created_from' => '2026-01-01 00:00:00',
            'created_to' => '2026-01-02 00:00:00',
        ]);
});

test('panel connection registry reads outgoing and incoming config without exposing secrets', function (): void {
    config([
        'talkto.outgoing' => [
            'target-alpha' => [
                'url' => 'https://target-alpha.test',
                'secret' => 'outgoing-secret-value',
                'endpoint' => '/api/talkto/receive',
            ],
            'target-missing-secret' => [
                'url' => 'https://target-missing-secret.test',
                'endpoint' => '/api/talkto/receive',
            ],
        ],
        'talkto.incoming' => [
            'handlers' => [],
            'unknown_command_strategy' => 'fail',
            'source-alpha' => [
                'secret' => 'incoming-secret-value',
                'allowed_commands' => [
                    'website.event-created' => ['driver' => 'handler'],
                    'website.event-updated' => ['driver' => 'handler'],
                ],
            ],
        ],
    ]);

    $registry = app(TalktoPanelConnectionRegistry::class);
    $outgoing = $registry->outgoing();
    $incoming = $registry->incoming();
    $all = $registry->all();

    expect($outgoing)->toHaveCount(2)
        ->and($incoming)->toHaveCount(1)
        ->and($all)->toHaveCount(3);

    $configuredOutgoing = $outgoing->firstWhere('service', 'target-alpha');
    $missingSecret = $outgoing->firstWhere('service', 'target-missing-secret');
    $incomingConnection = $incoming->first();

    expect($configuredOutgoing->configured)->toBeTrue()
        ->and($configuredOutgoing->urlConfigured)->toBeTrue()
        ->and($configuredOutgoing->secretConfigured)->toBeTrue()
        ->and($configuredOutgoing->endpoint)->toBe('/api/talkto/receive')
        ->and($configuredOutgoing->toArray())->not->toContain('outgoing-secret-value')
        ->and($missingSecret->configured)->toBeFalse()
        ->and($missingSecret->warnings)->toContain('missing_secret')
        ->and($incomingConnection->direction)->toBe('incoming')
        ->and($incomingConnection->urlConfigured)->toBeFalse()
        ->and($incomingConnection->commands)->toBe([
            'website.event-created',
            'website.event-updated',
        ])
        ->and($incomingConnection->toArray())->not->toContain('incoming-secret-value');
});

test('panel health checker reports misconfigured and unknown configured connections honestly', function (): void {
    config([
        'talkto.outgoing.target-alpha' => [
            'url' => 'https://target-alpha.test',
            'endpoint' => '/api/talkto/receive',
        ],
        'talkto.incoming.source-alpha' => [
            'secret' => 'incoming-secret',
            'allowed_commands' => [
                'website.event-created' => ['driver' => 'handler'],
            ],
        ],
    ]);

    $registry = app(TalktoPanelConnectionRegistry::class);
    $checker = app(TalktoPanelConnectionHealthChecker::class);

    $misconfigured = $checker->check($registry->outgoing()->firstWhere('service', 'target-alpha'));
    $unknown = $checker->check($registry->incoming()->firstWhere('service', 'source-alpha'));

    expect($misconfigured->status)->toBe(TalktoPanelHealthStatus::Misconfigured)
        ->and($misconfigured->warnings)->toContain('missing_secret')
        ->and($unknown->status)->toBe(TalktoPanelHealthStatus::Unknown)
        ->and($unknown->recentMessages)->toBe(0)
        ->and($unknown->toArray())->not->toContain('incoming-secret');
});

test('panel health checker reports recent successful outgoing and incoming traffic as healthy', function (): void {
    config([
        'talkto.outgoing.target-alpha' => [
            'url' => 'https://target-alpha.test',
            'secret' => 'outgoing-secret',
            'endpoint' => '/api/talkto/receive',
        ],
        'talkto.incoming.source-alpha' => [
            'secret' => 'incoming-secret',
            'allowed_commands' => [
                'website.event-created' => ['driver' => 'handler'],
            ],
        ],
    ]);

    panelMessage('panel-out-success', 'outgoing', 'completed', [
        'source_service' => 'panel-test',
        'target_service' => 'target-alpha',
        'completed_at' => now()->subMinutes(5),
    ]);
    panelMessage('panel-in-success', 'incoming', 'completed', [
        'source_service' => 'source-alpha',
        'target_service' => 'panel-test',
        'completed_at' => now()->subMinutes(3),
    ]);

    $registry = app(TalktoPanelConnectionRegistry::class);
    $checker = app(TalktoPanelConnectionHealthChecker::class);

    $outgoing = $checker->check($registry->outgoing()->firstWhere('service', 'target-alpha'));
    $incoming = $checker->check($registry->incoming()->firstWhere('service', 'source-alpha'));

    expect($outgoing->status)->toBe(TalktoPanelHealthStatus::Healthy)
        ->and($outgoing->recentMessages)->toBe(1)
        ->and($outgoing->lastSuccessAt)->not->toBeNull()
        ->and($incoming->status)->toBe(TalktoPanelHealthStatus::Healthy)
        ->and($incoming->recentMessages)->toBe(1);
});

test('panel health checker reports retry backlog as degraded and dead letters as failing', function (): void {
    config([
        'talkto.outgoing.target-alpha' => [
            'url' => 'https://target-alpha.test',
            'secret' => 'outgoing-secret',
            'endpoint' => '/api/talkto/receive',
        ],
        'talkto.outgoing.target-beta' => [
            'url' => 'https://target-beta.test',
            'secret' => 'outgoing-secret',
            'endpoint' => '/api/talkto/receive',
        ],
    ]);

    panelMessage('panel-out-retryable', 'outgoing', 'failed_retryable', [
        'target_service' => 'target-alpha',
        'next_retry_at' => now()->subMinute(),
        'failed_at' => now()->subMinutes(2),
    ]);
    $failed = panelMessage('panel-out-final', 'outgoing', 'failed_final', [
        'target_service' => 'target-beta',
        'failed_at' => now()->subMinutes(4),
    ]);
    panelDeadLetter($failed, [
        'target' => 'target-beta',
        'status' => 'open',
    ]);

    $registry = app(TalktoPanelConnectionRegistry::class);
    $checker = app(TalktoPanelConnectionHealthChecker::class);

    $degraded = $checker->check($registry->outgoing()->firstWhere('service', 'target-alpha'));
    $failing = $checker->check($registry->outgoing()->firstWhere('service', 'target-beta'));

    expect($degraded->status)->toBe(TalktoPanelHealthStatus::Degraded)
        ->and($degraded->retryBacklog)->toBe(1)
        ->and($degraded->warnings)->toContain('retry_backlog=1')
        ->and($failing->status)->toBe(TalktoPanelHealthStatus::Failing)
        ->and($failing->deadLetters)->toBe(1)
        ->and($failing->warnings)->toContain('dead_letters=1');
});

test('panel health checker checks all configured connections', function (): void {
    config([
        'talkto.outgoing.target-alpha' => [
            'url' => 'https://target-alpha.test',
            'secret' => 'outgoing-secret',
            'endpoint' => '/api/talkto/receive',
        ],
        'talkto.incoming.source-alpha' => [
            'secret' => 'incoming-secret',
            'allowed_commands' => [
                'website.event-created' => ['driver' => 'handler'],
            ],
        ],
    ]);

    $health = app(TalktoPanelConnectionHealthChecker::class)->checkAll();

    expect($health)->toHaveCount(2)
        ->and($health->pluck('connection.service')->all())->toBe(['target-alpha', 'source-alpha']);
});

test('panel message query paginates latest messages and applies filters', function (): void {
    $old = panelMessage('panel-old', 'outgoing', 'completed', [
        'target_service' => 'target-alpha',
        'command' => 'domain.old',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    $outgoing = panelMessage('panel-out', 'outgoing', 'completed', [
        'target_service' => 'target-alpha',
        'command' => 'domain.sync',
        'business_key' => 'business-panel',
        'idempotency_key' => 'idem-panel',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);
    $incoming = panelMessage('panel-in', 'incoming', 'failed_retryable', [
        'source_service' => 'source-alpha',
        'target_service' => 'panel-test',
        'command' => 'website.event-created',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $query = app(TalktoPanelMessageQuery::class);

    expect($query->latest(2)->pluck('message_id')->all())->toBe(['panel-in', 'panel-out']);
    expect($query->paginate(TalktoPanelMessageFilters::fromArray(['direction' => 'outgoing']), 10)->total())->toBe(2);
    expect($query->paginate(TalktoPanelMessageFilters::fromArray(['command' => 'website.event-created']), 10)->total())->toBe(1);
    expect($query->paginate(TalktoPanelMessageFilters::fromArray(['direction' => 'outgoing', 'service' => 'target-alpha']), 10)->total())->toBe(2);
    expect($query->paginate(TalktoPanelMessageFilters::fromArray(['direction' => 'incoming', 'service' => 'source-alpha']), 10)->total())->toBe(1);
    expect($query->paginate(TalktoPanelMessageFilters::fromArray(['business_key' => 'panel']), 10)->total())->toBe(1);
    expect($query->paginate(TalktoPanelMessageFilters::fromArray(['created_from' => now()->subHour()->toDateTimeString()]), 10)->total())->toBe(2);
    expect($query->findMessage($outgoing->id)?->message_id)->toBe('panel-out');
    expect($query->findMessage('panel-in')?->id)->toBe($incoming->id);
    expect($query->findMessage('missing-message'))->toBeNull();
    expect($query->paginate(TalktoPanelMessageFilters::fromArray(['message_id' => (string) $old->id]), 10)->total())->toBe(1);
});

test('panel message query returns related attempts events and dead letter safely', function (): void {
    $message = panelMessage('panel-related', 'outgoing', 'failed_final', [
        'target_service' => 'target-alpha',
        'failed_at' => now()->subMinute(),
    ]);

    TalktoAttempt::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'stage' => 'transport',
        'attempt_no' => 1,
        'status' => 'failed',
    ]);
    TalktoEvent::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'service_name' => 'panel-test',
        'event_type' => 'message_failed',
    ]);
    panelDeadLetter($message);

    $query = app(TalktoPanelMessageQuery::class);

    expect($query->attemptsFor($message))->toHaveCount(1)
        ->and($query->eventsFor($message))->toHaveCount(1)
        ->and($query->deadLetterFor($message)?->message_id)->toBe('panel-related');
});

function panelMessage(string $messageId, string $direction, string $status, array $attributes = []): TalktoMessage
{
    $createdAt = $attributes['created_at'] ?? now();

    $message = TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'corr-'.$messageId,
        'direction' => $direction,
        'source_service' => $direction === 'incoming' ? 'source-alpha' : 'panel-test',
        'target_service' => $direction === 'outgoing' ? 'target-alpha' : 'panel-test',
        'command' => 'domain.sync',
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

function panelDeadLetter(TalktoMessage $message, array $attributes = []): TalktoDeadLetter
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
