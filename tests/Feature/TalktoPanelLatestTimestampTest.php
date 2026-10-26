<?php

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionRegistry;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelConnectionHealth;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelHealthStatus;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));

    config([
        'talkto.service' => 'base',
        'talkto.outgoing' => [
            'website' => ['url' => 'https://website.test', 'secret' => 'test-secret'],
        ],
        'talkto.incoming' => [
            'website' => ['secret' => 'test-secret', 'allowed_commands' => ['domain.sync' => ['driver' => 'none']]],
        ],
        'talkto.retry.retryable_statuses' => ['failed_retryable'],
    ]);
});

afterEach(function (): void {
    $this->travelBack();
});

test('latest message uses the maximum created timestamp within direction and both services', function (string $direction): void {
    $services = $direction === 'outgoing'
        ? ['source_service' => 'base', 'target_service' => 'website']
        : ['source_service' => 'website', 'target_service' => 'base'];

    foreach (['2026-10-26 11:35:00', '2026-10-26 11:55:00', '2026-10-26 11:40:00'] as $timestamp) {
        panelTimestampInsert([...$services, 'direction' => $direction, 'created_at' => $timestamp]);
    }

    // Each excluded row is newer than every matching row.
    panelTimestampInsert([...$services, 'direction' => $direction, 'source_service' => 'unrelated', 'created_at' => '2026-10-26 11:59:00']);
    panelTimestampInsert([...$services, 'direction' => $direction, 'target_service' => 'unrelated', 'created_at' => '2026-10-26 11:59:00']);
    panelTimestampInsert([...$services, 'direction' => $direction === 'outgoing' ? 'incoming' : 'outgoing', 'created_at' => '2026-10-26 11:59:00']);

    $health = panelTimestampHealth($direction);

    expect($health->lastMessageAt)->toBeInstanceOf(CarbonInterface::class)
        ->and($health->lastMessageAt->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:55:00')
        ->and($health->recentMessages)->toBe(3);
})->with(['outgoing', 'incoming']);

test('latest success includes both canonical statuses and excludes unrelated traffic', function (array $statuses): void {
    foreach ($statuses as $index => $status) {
        panelTimestampInsert([
            'overall_status' => $status,
            'completed_at' => $index === 1 ? '2026-10-26 11:55:00' : '2026-10-26 11:30:00',
        ]);
    }

    foreach ([
        ['source_service' => 'unrelated'],
        ['target_service' => 'unrelated'],
        ['direction' => 'incoming'],
        ['overall_status' => 'pending'],
    ] as $attributes) {
        panelTimestampInsert([...['overall_status' => 'completed', 'completed_at' => '2026-10-26 11:59:00'], ...$attributes]);
    }

    expect(panelTimestampHealth()->lastSuccessAt->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:55:00');
})->with([
    'completed' => [['completed', 'completed', 'completed']],
    'succeeded' => [['succeeded', 'succeeded', 'succeeded']],
    'mixed' => [['completed', 'succeeded', 'completed']],
]);

test('latest failure includes final legacy and configured retryable statuses', function (string $status, array $retryableStatuses): void {
    config(['talkto.retry.retryable_statuses' => $retryableStatuses]);

    panelTimestampInsert(['overall_status' => $status, 'failed_at' => '2026-10-26 11:55:00']);
    panelTimestampInsert(['overall_status' => $status, 'failed_at' => '2026-10-26 11:30:00']);

    foreach ([
        ['source_service' => 'unrelated'],
        ['target_service' => 'unrelated'],
        ['direction' => 'incoming'],
        ['overall_status' => 'pending'],
    ] as $attributes) {
        panelTimestampInsert([...['overall_status' => $status, 'failed_at' => '2026-10-26 11:59:00'], ...$attributes]);
    }

    expect(panelTimestampHealth()->lastFailureAt->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:55:00');
})->with([
    'failed' => ['failed', ['failed_retryable']],
    'failed_final' => ['failed_final', ['failed_retryable']],
    'default retryable' => ['failed_retryable', ['failed_retryable']],
    'custom retryable' => ['waiting_for_retry', ['waiting_for_retry', 'temporary_failure']],
]);

test('custom retryable configuration replaces the default failure status', function (): void {
    config(['talkto.retry.retryable_statuses' => ['waiting_for_retry']]);
    panelTimestampInsert(['overall_status' => 'waiting_for_retry', 'failed_at' => '2026-10-26 11:40:00']);
    panelTimestampInsert(['overall_status' => 'failed_retryable', 'failed_at' => '2026-10-26 11:59:00']);

    $health = panelTimestampHealth();

    expect($health->lastFailureAt->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:40:00')
        ->and($health->retryBacklog)->toBe(1)
        ->and($health->recentFailures)->toBe(1);
});

test('timestamp fallback uses maximum updated_at only when every primary value is null', function (string $status, string $primary, string $field): void {
    panelTimestampInsert(['overall_status' => $status, $primary => null, 'updated_at' => '2026-10-26 11:55:00']);
    panelTimestampInsert(['overall_status' => $status, $primary => null, 'updated_at' => '2026-10-26 11:30:00']);

    [$health, $queries] = panelTimestampCapture(fn () => panelTimestampHealth());

    expect($health->{$field}->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:55:00');
    panelTimestampAssertAggregateQueries($queries, 5);

    $fallback = array_values(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'max("updated_at")')));
    expect($fallback)->toHaveCount(2)
        ->and($fallback[$status === 'completed' ? 0 : 1]['bindings'])->toContain($status);
})->with([
    'success' => ['completed', 'completed_at', 'lastSuccessAt'],
    'failure' => ['failed_final', 'failed_at', 'lastFailureAt'],
]);

test('any populated primary timestamp takes precedence over a later updated_at', function (string $status, string $primary, string $field): void {
    panelTimestampInsert(['overall_status' => $status, $primary => null, 'updated_at' => '2026-10-26 11:59:00']);
    panelTimestampInsert(['overall_status' => $status, $primary => '2026-10-26 11:30:00', 'updated_at' => '2026-10-26 11:58:00']);
    panelTimestampInsert(['overall_status' => $status, $primary => '2026-10-26 11:45:00', 'updated_at' => '2026-10-26 11:57:00']);

    [$health, $queries] = panelTimestampCapture(fn () => panelTimestampHealth());

    expect($health->{$field}->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:45:00');
    panelTimestampAssertAggregateQueries($queries, 4);

    $primaryQuery = array_values(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'max("'.$primary.'")')))[0];
    $fallback = array_values(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'max("updated_at")')));

    expect($fallback)->toHaveCount(1)
        ->and($fallback[0]['bindings'])->not->toBe($primaryQuery['bindings']);
})->with([
    'success' => ['succeeded', 'completed_at', 'lastSuccessAt'],
    'failure' => ['failed_retryable', 'failed_at', 'lastFailureAt'],
]);

test('empty or unmatched datasets preserve null timestamps', function (?array $attributes): void {
    if ($attributes !== null) {
        panelTimestampInsert($attributes);
    }

    [$health, $queries] = panelTimestampCapture(fn () => panelTimestampHealth());

    expect($health->lastMessageAt)->toBeNull()
        ->and($health->lastSuccessAt)->toBeNull()
        ->and($health->lastFailureAt)->toBeNull()
        ->and($health->recentMessages)->toBe(0)
        ->and($health->status)->toBe(TalktoPanelHealthStatus::Unknown);
    panelTimestampAssertAggregateQueries($queries, 5);
})->with([
    'no messages' => [null],
    'different source' => [['source_service' => 'other']],
    'different target' => [['target_service' => 'other']],
    'different direction' => [['direction' => 'incoming']],
    'all timestamp columns null' => [['created_at' => null, 'updated_at' => null, 'overall_status' => 'completed']],
]);

test('messages without matching success or failure statuses preserve null results', function (): void {
    panelTimestampInsert(['overall_status' => 'queued', 'completed_at' => '2026-10-26 11:59:00', 'failed_at' => '2026-10-26 11:59:00']);

    $health = panelTimestampHealth();

    expect($health->lastMessageAt)->toBeInstanceOf(CarbonInterface::class)
        ->and($health->lastSuccessAt)->toBeNull()
        ->and($health->lastFailureAt)->toBeNull();
});

test('a custom accessor returning null does not trigger a raw timestamp fallback', function (): void {
    config(['talkto.models.message' => PanelTimestampNullAccessorMessage::class]);
    panelTimestampInsert([
        'overall_status' => 'completed',
        'completed_at' => '2026-10-26 11:30:00',
        'updated_at' => '2026-10-26 11:59:00',
    ]);

    [$health, $queries] = panelTimestampCapture(fn () => panelTimestampHealth());

    expect($health->lastSuccessAt)->toBeNull();
    panelTimestampAssertAggregateQueries($queries, 4);

    $fallback = array_values(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'max("updated_at")')));
    expect($fallback)->toHaveCount(1)
        ->and($fallback[0]['bindings'])->toBe(['outgoing', 'base', 'website', 'failed_retryable', 'failed_final', 'failed']);
});

test('a configured message subclass retains its global scope for every aggregate', function (): void {
    config(['talkto.models.message' => PanelTimestampScopedMessage::class]);
    panelTimestampInsert(['overall_status' => 'completed', 'completed_at' => '2026-10-26 11:30:00', 'business_key' => 'visible']);
    panelTimestampInsert(['overall_status' => 'failed', 'failed_at' => '2026-10-26 11:35:00', 'business_key' => 'visible']);
    panelTimestampInsert(['overall_status' => 'completed', 'completed_at' => '2026-10-26 11:59:00', 'created_at' => '2026-10-26 11:59:00', 'business_key' => 'hidden']);
    panelTimestampInsert(['overall_status' => 'failed', 'failed_at' => '2026-10-26 11:59:00', 'created_at' => '2026-10-26 11:59:00', 'business_key' => 'hidden']);

    [$health, $queries] = panelTimestampCapture(fn () => panelTimestampHealth());

    expect($health->lastMessageAt->format('H:i:s'))->toBe('11:40:00')
        ->and($health->lastSuccessAt->format('H:i:s'))->toBe('11:30:00')
        ->and($health->lastFailureAt->format('H:i:s'))->toBe('11:35:00')
        ->and($health->recentMessages)->toBe(2);
    panelTimestampAssertAggregateQueries($queries, 3);

    foreach ($queries as $query) {
        expect($query['query'])->toContain('"business_key" = ?')
            ->and($query['bindings'])->toContain('visible');
    }
});

test('aggregate dates exactly match original Eloquent casts in the application timezone', function (string $modelClass, string $timezone, bool $fallback): void {
    $previousTimezone = date_default_timezone_get();

    try {
        config(['app.timezone' => $timezone, 'talkto.models.message' => $modelClass]);
        date_default_timezone_set($timezone);

        panelTimestampInsert([
            'overall_status' => 'completed',
            'created_at' => '2026-10-26 09:10:11',
            'completed_at' => $fallback ? null : '2026-10-26 09:20:21',
            'updated_at' => '2026-10-26 09:30:31',
        ]);
        panelTimestampInsert([
            'overall_status' => 'failed',
            'created_at' => '2026-10-26 09:40:41',
            'failed_at' => $fallback ? null : '2026-10-26 09:50:51',
            'updated_at' => '2026-10-26 09:59:01',
        ]);

        $health = panelTimestampHealth();

        // This is deliberately the old Eloquent retrieval path, used only as an oracle.
        foreach ([
            ['lastMessageAt', 'created_at', null],
            ['lastSuccessAt', $fallback ? 'updated_at' : 'completed_at', 'completed'],
            ['lastFailureAt', $fallback ? 'updated_at' : 'failed_at', 'failed'],
        ] as [$field, $column, $status]) {
            $expected = $modelClass::query()
                ->where('direction', 'outgoing')
                ->where('source_service', 'base')
                ->where('target_service', 'website')
                ->when($status !== null, fn ($query) => $query->where('overall_status', $status))
                ->whereNotNull($column)
                ->orderByDesc($column)
                ->orderByDesc('id')
                ->firstOrFail()->{$column};

            expect($health->{$field})->toBeInstanceOf(CarbonInterface::class)
                ->and(get_class($health->{$field}))->toBe(get_class($expected))
                ->and($health->{$field}->format('Y-m-d H:i:s.uP e'))->toBe($expected->format('Y-m-d H:i:s.uP e'))
                ->and($health->{$field}->equalTo($expected))->toBeTrue();

            if ($modelClass !== TalktoMessage::class) {
                expect($health->{$field})->toBeInstanceOf(CarbonImmutable::class);
            }
        }
    } finally {
        date_default_timezone_set($previousTimezone);
    }
})->with([
    'default datetime UTC' => [TalktoMessage::class, 'UTC', false],
    'default datetime Tehran' => [TalktoMessage::class, 'Asia/Tehran', false],
    'default fallback Tehran' => [TalktoMessage::class, 'Asia/Tehran', true],
    'custom immutable UTC' => [PanelTimestampImmutableMessage::class, 'UTC', false],
    'custom immutable Tehran' => [PanelTimestampImmutableMessage::class, 'Asia/Tehran', false],
    'custom immutable fallback Tehran' => [PanelTimestampImmutableMessage::class, 'Asia/Tehran', true],
    'custom date cast Tehran' => [PanelTimestampCustomCastMessage::class, 'Asia/Tehran', false],
    'custom date cast fallback Tehran' => [PanelTimestampCustomCastMessage::class, 'Asia/Tehran', true],
]);

test('large payload health checks use scoped scalar aggregates without message hydration', function (): void {
    config(['talkto.models.message' => PanelTimestampNoHydrationMessage::class]);

    $largeJson = json_encode(['body' => str_repeat('x', 256 * 1024)], JSON_THROW_ON_ERROR);
    panelTimestampInsert(['overall_status' => 'completed', 'payload' => $largeJson, 'last_response' => $largeJson, 'completed_at' => '2026-10-26 11:45:00']);
    panelTimestampInsert(['overall_status' => 'succeeded', 'payload' => $largeJson, 'last_response' => $largeJson, 'completed_at' => '2026-10-26 11:55:00']);
    panelTimestampInsert(['overall_status' => 'failed_retryable', 'payload' => $largeJson, 'last_response' => $largeJson, 'failed_at' => '2026-10-26 11:50:00']);

    [$health, $queries] = panelTimestampCapture(fn () => panelTimestampHealth());

    expect($health->lastSuccessAt->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:55:00')
        ->and($health->lastFailureAt->format('Y-m-d H:i:s'))->toBe('2026-10-26 11:50:00');
    panelTimestampAssertAggregateQueries($queries, 3);

    expect($queries[0]['bindings'])->toBe(['outgoing', 'base', 'website'])
        ->and($queries[1]['bindings'])->toBe(['outgoing', 'base', 'website', 'completed', 'succeeded'])
        ->and($queries[2]['bindings'])->toBe(['outgoing', 'base', 'website', 'failed_retryable', 'failed_final', 'failed']);
});

test('dashboard health values types warnings checks and serialization remain compatible', function (): void {
    panelTimestampInsert(['overall_status' => 'completed', 'created_at' => '2026-10-26 11:50:00', 'completed_at' => '2026-10-26 11:51:00']);
    panelTimestampInsert(['overall_status' => 'succeeded', 'created_at' => '2026-10-26 09:00:00', 'completed_at' => '2026-10-26 09:01:00']);
    panelTimestampInsert(['overall_status' => 'failed_retryable', 'created_at' => '2026-10-26 11:52:00', 'failed_at' => '2026-10-26 11:53:00']);
    panelTimestampInsert(['overall_status' => 'failed_retryable', 'created_at' => '2026-10-26 11:54:00', 'failed_at' => '2026-10-26 11:55:00', 'next_retry_at' => '2026-10-26 13:00:00']);
    panelTimestampInsert(['overall_status' => 'failed_final', 'created_at' => '2026-10-26 11:56:00', 'failed_at' => '2026-10-26 11:57:00']);
    DB::table('talkto_dead_letters')->insert([
        ['direction' => 'outgoing', 'source' => 'base', 'target' => 'website', 'status' => 'open'],
        ['direction' => 'outgoing', 'source' => 'base', 'target' => 'website', 'status' => 'failed_reprocess'],
        ['direction' => 'incoming', 'source' => 'base', 'target' => 'website', 'status' => 'open'],
        ['direction' => 'outgoing', 'source' => 'base', 'target' => 'website', 'status' => 'resolved'],
    ]);

    $health = panelTimestampHealth();

    expect($health->lastMessageAt)->toBeInstanceOf(CarbonInterface::class)
        ->and($health->lastMessageAt->format('H:i:s'))->toBe('11:56:00')
        ->and($health->lastSuccessAt)->toBeInstanceOf(CarbonInterface::class)
        ->and($health->lastSuccessAt->format('H:i:s'))->toBe('11:51:00')
        ->and($health->lastFailureAt)->toBeInstanceOf(CarbonInterface::class)
        ->and($health->lastFailureAt->format('H:i:s'))->toBe('11:57:00')
        ->and($health->recentMessages)->toBe(4)
        ->and($health->recentFailures)->toBe(3)
        ->and($health->retryBacklog)->toBe(1)
        ->and($health->deadLetters)->toBe(2)
        ->and($health->warnings)->toBe(['dead_letters=2', 'retry_backlog=1', 'recent_failures=3'])
        ->and($health->checks)->toBe([
            'messages_table_exists' => true,
            'dead_letters_table_exists' => true,
            'window_minutes' => 60,
            'recent_successes' => 1,
            'recent_final_failures' => 1,
            'recent_retryable_failures' => 2,
        ])
        ->and($health->status)->toBe(TalktoPanelHealthStatus::Failing)
        ->and($health->toArray())->toMatchArray([
            'status' => 'failing',
            'last_message_at' => $health->lastMessageAt->toIso8601String(),
            'last_success_at' => $health->lastSuccessAt->toIso8601String(),
            'last_failure_at' => $health->lastFailureAt->toIso8601String(),
            'recent_messages' => 4,
            'recent_failures' => 3,
            'retry_backlog' => 1,
            'dead_letters' => 2,
            'warnings' => $health->warnings,
            'checks' => $health->checks,
        ]);
});

function panelTimestampInsert(array $attributes = []): void
{
    DB::table('talkto_messages')->insert(array_merge([
        'message_id' => (string) Str::uuid(),
        'direction' => 'outgoing',
        'source_service' => 'base',
        'target_service' => 'website',
        'command' => 'domain.sync',
        'overall_status' => 'queued',
        'payload' => '{}',
        'created_at' => '2026-10-26 11:40:00',
        'updated_at' => '2026-10-26 11:40:00',
    ], $attributes));
}

function panelTimestampHealth(string $direction = 'outgoing'): TalktoPanelConnectionHealth
{
    $registry = app(TalktoPanelConnectionRegistry::class);
    $connection = ($direction === 'outgoing' ? $registry->outgoing() : $registry->incoming())->firstWhere('service', 'website');

    return app(TalktoPanelConnectionHealthChecker::class)->check($connection);
}

function panelTimestampCapture(Closure $callback): array
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $result = $callback();
        // Include any full-row SELECT regression, not just queries already using MAX.
        $queries = array_values(array_filter($connection->getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select ')
            && str_contains($query['query'], 'from "talkto_messages"')
            && ! str_contains(strtolower($query['query']), 'count(')));

        return [$result, $queries];
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
}

function panelTimestampAssertAggregateQueries(array $queries, int $count): void
{
    expect($queries)->toHaveCount($count);

    foreach ($queries as $query) {
        $sql = strtolower($query['query']);

        expect($sql)->toMatch('/^select max\("(?:created_at|completed_at|failed_at|updated_at)"\) as "?aggregate"? from "talkto_messages" where /')
            ->not->toContain('select *', 'order by', 'limit', 'payload', 'last_response')
            ->toContain('"direction" = ?', '"source_service" = ?', '"target_service" = ?')
            ->and(array_slice($query['bindings'], 0, 3))->toBe(['outgoing', 'base', 'website']);
    }
}

class PanelTimestampImmutableMessage extends TalktoMessage
{
    protected function casts(): array
    {
        return array_fill_keys(['created_at', 'updated_at', 'completed_at', 'failed_at'], 'immutable_datetime');
    }
}

class PanelTimestampCustomCastMessage extends TalktoMessage
{
    protected function casts(): array
    {
        return array_fill_keys(['created_at', 'updated_at', 'completed_at', 'failed_at'], PanelTimestampCustomDateCast::class);
    }
}

class PanelTimestampCustomDateCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if (! $model->exists || $model->getRawOriginal($key) !== $value) {
            throw new RuntimeException('The custom date cast requires the same stored model state as Eloquent retrieval.');
        }

        return $value === null ? null : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'Asia/Tehran')->addMinutes(17);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}

class PanelTimestampNoHydrationMessage extends TalktoMessage
{
    public function newFromBuilder($attributes = [], $connection = null)
    {
        throw new RuntimeException('Timestamp checks must not hydrate message records.');
    }
}

class PanelTimestampNullAccessorMessage extends TalktoMessage
{
    public function getCompletedAtAttribute(mixed $value): ?CarbonInterface
    {
        return null;
    }
}

class PanelTimestampScopedMessage extends TalktoMessage
{
    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('visible', fn (Builder $query) => $query->where('business_key', 'visible'));
    }
}
