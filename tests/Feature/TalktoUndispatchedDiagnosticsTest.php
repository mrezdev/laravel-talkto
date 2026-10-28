<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoUndispatchedInspector;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    expect($this->artisan('migrate')->run())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'));
    config([
        'talkto.service' => 'testing',
        'talkto.storage.enforce_current_service' => true,
        'talkto.outgoing.peer' => ['url' => 'https://peer.test', 'secret' => 'synthetic-diagnostics-secret'],
    ]);
    Queue::fake();
    UndispatchedScopedMessage::$hydrated = 0;
});

afterEach(function (): void {
    DB::disableQueryLog();
    DB::purge('diagnostics_custom');
    $this->travelBack();
});

test('undispatched diagnostics are read only and never enable a recovery path', function (): void {
    undispatchedDiagnosticInsert();
    $before = undispatchedDiagnosticSnapshot();
    $first = app(TalktoUndispatchedInspector::class)->inspect();
    $second = app(TalktoUndispatchedInspector::class)->inspect();

    expect($first)->toMatchArray(['mode' => 'diagnostic_only', 'recovery_enabled' => false, 'execution_blocked' => true, 'service' => 'testing', 'count' => 1])
        ->and($second)->toBe($first)
        ->and($first['candidates'][0])->toMatchArray(['dispatch_intent' => 'unproven', 'publication' => 'unknown', 'recovery_eligible' => false])
        ->and(undispatchedDiagnosticSnapshot())->toBe($before);
    Queue::assertNothingPushed();
});

test('factory only persistence has no asserted missing job or recovery eligibility', function (): void {
    $message = app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', ['private' => 'factory-payload']);
    DB::table('talkto_messages')->where('id', $message->id)->update(['created_at' => '2026-10-28 10:00:00']);
    $before = undispatchedDiagnosticSnapshot();

    $report = app(TalktoUndispatchedInspector::class)->inspect();

    expect($report['count'])->toBe(1)
        ->and($report['candidates'][0])->toMatchArray(['dispatch_intent' => 'unproven', 'publication' => 'unknown', 'recovery_eligible' => false])
        ->and(undispatchedDiagnosticSnapshot())->toBe($before);
    Queue::assertNothingPushed();
});

test('historical initial rows and legitimate queued work remain ambiguous without more publication', function (): void {
    $historical = undispatchedDiagnosticInsert();
    $queued = undispatchedDiagnosticInsert();
    SendTalktoMessage::dispatch($queued)->delay(now()->addHours(2));
    TalktoEvent::create(['talkto_message_id' => $queued, 'message_id' => 'diagnostic-'.$queued,
        'service_name' => 'testing', 'event_type' => 'retry_dispatched', 'meta' => ['diagnostic' => 'not a broker acknowledgement']]);
    $before = undispatchedDiagnosticSnapshot();

    $report = app(TalktoUndispatchedInspector::class)->inspect();

    expect($report['count'])->toBe(2);
    foreach ($report['candidates'] as $candidate) {
        expect($candidate)->toMatchArray(['dispatch_intent' => 'unproven', 'publication' => 'unknown', 'recovery_eligible' => false]);
    }
    expect(undispatchedDiagnosticSnapshot())->toBe($before);
    Queue::assertPushed(SendTalktoMessage::class, 1);
    expect($historical)->not->toBe($queued);
});

test('diagnostics require current service ownership even when job storage enforcement is disabled', function (): void {
    config(['talkto.storage.enforce_current_service' => false]);
    undispatchedDiagnosticInsert();
    undispatchedDiagnosticInsert(['source_service' => 'other']);
    undispatchedDiagnosticInsert(['direction' => 'incoming', 'overall_status' => 'queued', 'transport_status' => null,
        'destination_action_status' => 'queued', 'source_service' => 'peer', 'target_service' => 'testing']);
    undispatchedDiagnosticInsert(['direction' => 'incoming', 'overall_status' => 'queued', 'transport_status' => null,
        'destination_action_status' => 'queued', 'source_service' => 'peer', 'target_service' => 'other']);

    $inspector = app(TalktoUndispatchedInspector::class);
    expect($inspector->inspect()['count'])->toBe(2)
        ->and($inspector->inspect('outgoing')['count'])->toBe(1)
        ->and($inspector->inspect('incoming')['count'])->toBe(1);
    Queue::assertNothingPushed();
});

test('diagnostics filter public message id and age and enforce a fixed candidate limit', function (): void {
    undispatchedDiagnosticInsert(['message_id' => 'diagnostic-first', 'created_at' => '2026-10-28 10:00:00']);
    undispatchedDiagnosticInsert(['message_id' => 'diagnostic-cutoff', 'created_at' => '2026-10-28 11:30:00']);
    undispatchedDiagnosticInsert(['message_id' => 'diagnostic-young', 'created_at' => '2026-10-28 11:31:00']);
    $inspector = app(TalktoUndispatchedInspector::class);

    expect($inspector->inspect()['count'])->toBe(2)
        ->and($inspector->inspect(null, null, 30, 1)['count'])->toBe(1)
        ->and($inspector->inspect(null, 'diagnostic-cutoff', 30, 10)['count'])->toBe(1)
        ->and($inspector->inspect(null, 'diagnostic-young', 30, 10)['count'])->toBe(0)
        ->and($inspector->inspect(null, 'diagnostic-young', 0, 10)['count'])->toBe(1)
        ->and($inspector->inspect(null, 'missing', 0, 10)['count'])->toBe(0);
});

test('diagnostics accept a valid multibyte public message id within the existing character limit', function (): void {
    $messageId = str_repeat('م', 100);
    undispatchedDiagnosticInsert(['message_id' => $messageId]);
    $report = app(TalktoUndispatchedInspector::class)->inspect(null, $messageId);

    expect($report['count'])->toBe(1)
        ->and($report['candidates'][0]['message_id'])->toBe($messageId);
});

test('grouped having and union model scopes fail closed before SQL execution', function (string $mode): void {
    config(['talkto.models.message' => UndispatchedUnsupportedScopeMessage::class, 'diagnostics.scope' => $mode]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        expect(fn () => app(TalktoUndispatchedInspector::class)->inspect())->toThrow(InvalidArgumentException::class);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
    Queue::assertNothingPushed();
})->with(['group', 'having', 'union']);

test('specific terminal and advanced messages remain unchanged and are never recoverable', function (string $status): void {
    undispatchedDiagnosticInsert(['message_id' => 'diagnostic-state', 'overall_status' => $status,
        'destination_action_status' => $status === 'completed' ? 'succeeded' : null,
        'completed_at' => in_array($status, ['completed', 'succeeded'], true) ? '2026-10-28 11:00:00' : null,
        'failed_at' => $status === 'failed_final' ? '2026-10-28 10:59:00' : null,
        'last_error' => 'protected old error', 'last_http_status' => 202, 'last_response' => 'protected response',
        'retry_count' => 2, 'next_retry_at' => '2026-10-28 13:00:00', 'next_attempt_at' => '2026-10-28 13:00:00',
        'locked_at' => '2026-10-28 11:00:00', 'locked_by' => 'sender:private-lock-token']);
    $before = undispatchedDiagnosticSnapshot();

    $report = app(TalktoUndispatchedInspector::class)->inspect(null, 'diagnostic-state', 0, 10);

    expect($report['count'])->toBe(1)
        ->and($report['candidates'][0]['recovery_eligible'])->toBeFalse()
        ->and(undispatchedDiagnosticSnapshot())->toBe($before);
    Queue::assertNothingPushed();
})->with(['completed', 'succeeded', 'failed_final', 'sent', 'destination_received', 'sending', 'processing', 'cancelled', 'skipped']);

test('active claims future retries and inconsistent initial states cannot gain recovery eligibility', function (array $overrides): void {
    undispatchedDiagnosticInsert($overrides);
    $before = undispatchedDiagnosticSnapshot();
    $report = app(TalktoUndispatchedInspector::class)->inspect();

    expect($report['count'])->toBe(1)
        ->and($report['candidates'][0]['recovery_eligible'])->toBeFalse()
        ->and(undispatchedDiagnosticSnapshot())->toBe($before);
    Queue::assertNothingPushed();
})->with([
    'active dispatch claim' => [['locked_at' => '2026-10-28 11:55:00', 'locked_by' => 'dispatch-claim:retry-command:private-token']],
    'stale dispatch claim' => [['locked_at' => '2026-10-28 10:00:00', 'locked_by' => 'dispatch-claim:stale-recovery:private-token']],
    'unpaired lock token' => [['locked_at' => null, 'locked_by' => 'sender:private-token']],
    'future retry' => [['next_retry_at' => '2026-10-28 13:00:00', 'next_attempt_at' => '2026-10-28 13:00:00']],
    'inconsistent transport' => [['transport_status' => 'sent']],
]);

test('large payload diagnostics execute one narrow query without hydrating Eloquent models or events', function (): void {
    config(['talkto.models.message' => UndispatchedScopedMessage::class]);
    foreach (range(1, 12) as $id) {
        undispatchedDiagnosticInsert(['business_key' => 'visible', 'payload' => json_encode(['private' => str_repeat('p', 256 * 1024)], JSON_THROW_ON_ERROR),
            'last_response' => str_repeat('r', 60 * 1024), 'last_error' => str_repeat('e', 16 * 1024)]);
    }
    undispatchedDiagnosticInsert(['business_key' => 'hidden']);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $started = hrtime(true);
    $beforeMemory = memory_get_usage(true);
    $report = app(TalktoUndispatchedInspector::class)->inspect(null, null, 30, 10);
    $elapsed = (hrtime(true) - $started) / 1000000;
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($report['count'])->toBe(10)
        ->and(UndispatchedScopedMessage::$hydrated)->toBe(0)
        ->and($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toContain('select distinct', 'limit 10', 'business_key')
        ->not->toContain('payload', 'last_response', 'last_error', 'select *', 'talkto_events')
        ->and(json_encode($report, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('p', 100), str_repeat('r', 100), str_repeat('e', 100));
    if ($path = getenv('TALKTO_DIAGNOSTICS_FEATURE_EVIDENCE_PATH')) {
        file_put_contents($path, json_encode(['php' => PHP_VERSION, 'laravel' => app()->version(), 'database' => 'SQLite',
            'fixture' => '13 initial rows,12visible,256KiB payload+60KiB response+16KiB error each', 'selected' => $report['count'],
            'query_count' => count($queries), 'sql' => $queries[0]['query'], 'elapsed_ms' => $elapsed,
            'allocated_change_bytes' => memory_get_usage(true) - $beforeMemory, 'peak_was_not_reset' => true,
            'model_hydrations' => UndispatchedScopedMessage::$hydrated], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    }
});

test('custom message event table raw key and named connection remain supported by diagnostics', function (string $key, string $rawKey, string $class): void {
    config(['database.connections.diagnostics_custom' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    Schema::connection('diagnostics_custom')->create('diagnostic_custom_messages', function (Blueprint $table) use ($key): void {
        $table->string($key)->primary();
        $table->string('message_id');
        $table->string('direction');
        $table->string('source_service');
        $table->string('target_service');
        $table->string('overall_status');
        $table->string('transport_status')->nullable();
        $table->string('destination_action_status')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('locked_at')->nullable();
        $table->string('locked_by')->nullable();
        $table->timestamp('next_retry_at')->nullable();
        $table->timestamp('next_attempt_at')->nullable();
    });
    DB::connection('diagnostics_custom')->table('diagnostic_custom_messages')->insert([
        $key => $rawKey, 'message_id' => 'custom-public-message', 'direction' => 'outgoing',
        'source_service' => 'testing', 'target_service' => 'peer', 'overall_status' => 'waiting_to_send',
        'transport_status' => 'pending', 'created_at' => '2026-10-28 10:00:00',
    ]);
    config(['talkto.models.message' => $class, 'talkto.models.event' => UndispatchedUnusedEvent::class]);

    $report = app(TalktoUndispatchedInspector::class)->inspect(null, 'custom-public-message');

    expect($report['count'])->toBe(1)
        ->and($report['candidates'][0]['message_id'])->toBe('custom-public-message')
        ->and($report['candidates'][0]['database_id'])->toBe($rawKey)
        ->and($report['candidates'][0]['recovery_eligible'])->toBeFalse()
        ->and(TalktoMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'raw string zero' => ['candidate_key', '0', UndispatchedCustomKeyMessage::class],
    'lock alias collision' => ['_talkto_lock_present', 'custom-key-17', UndispatchedLockKeyMessage::class],
    'claim alias collision' => ['_talkto_claim_present', 'custom-key-18', UndispatchedClaimKeyMessage::class],
]);

test('diagnostic command defaults to read only sanitized JSON and blocks execute before querying', function (): void {
    undispatchedDiagnosticInsert(['message_id' => "diagnostic-\x1b[31m\nmessage", 'source_service' => 'testing',
        'target_service' => "peer\r\nheader", 'payload' => '{"secret":"never-print-private-payload"}',
        'last_response' => 'never-print-response', 'last_error' => 'never-print-error', 'locked_by' => 'never-print-private-token']);
    $before = undispatchedDiagnosticSnapshot();
    expect(Artisan::call('talkto:recover-undispatched', ['--json' => true]))->toBe(0);
    $output = Artisan::output();
    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['count'])->toBe(1)
        ->and($output)->not->toContain("\x1b", 'never-print-private-payload', 'never-print-response', 'never-print-error', 'never-print-private-token')
        ->and($decoded['candidates'][0])->not->toHaveKey('locked_by')
        ->and($decoded['candidates'][0]['message_id'])->not->toContain("\x1b", "\n")
        ->and($decoded['candidates'][0]['target_service'])->not->toContain("\r", "\n");
    DB::flushQueryLog();
    DB::enableQueryLog();
    expect(Artisan::call('talkto:recover-undispatched', ['--execute' => true, '--message-id' => 'specific-id']))->toBe(1);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toBe([])
        ->and(undispatchedDiagnosticSnapshot())->toBe($before);
    Queue::assertNothingPushed();
});

test('diagnostic command rejects invalid bounded options without mutating messages', function (array $options): void {
    undispatchedDiagnosticInsert();
    $before = undispatchedDiagnosticSnapshot();
    expect(Artisan::call('talkto:recover-undispatched', $options))->toBe(1)
        ->and(undispatchedDiagnosticSnapshot())->toBe($before);
    Queue::assertNothingPushed();
})->with([
    [['--direction' => 'all']], [['--limit' => '0']], [['--limit' => '1001']], [['--limit' => 'unbounded']],
    [['--min-age' => '-1']], [['--min-age' => '525601']], [['--min-age' => 'invalid']],
]);

test('diagnostic command does not expose scope exceptions SQL or bindings in its errors', function (bool $invalidArgument): void {
    config(['talkto.models.message' => UndispatchedThrowingScopeMessage::class, 'diagnostics.invalid_argument' => $invalidArgument]);
    $before = undispatchedDiagnosticSnapshot();
    expect(Artisan::call('talkto:recover-undispatched', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    expect($output)->not->toContain('synthetic-password', 'private-sql', 'private-binding', 'select payload')
        ->and(undispatchedDiagnosticSnapshot())->toBe($before);
    Queue::assertNothingPushed();
})->with([true, false]);

test('sensitive wide or nonplain configured primary keys fail closed before querying or exposing data', function (string $key): void {
    undispatchedDiagnosticInsert([
        'payload' => '{"secret":"private-diagnostics-secret"}',
        'last_response' => 'private-diagnostics-response', 'last_error' => 'private-diagnostics-error',
        'locked_by' => 'private-diagnostics-token', 'payload_hash' => 'private-diagnostics-hash',
        'idempotency_key' => 'private-diagnostics-idempotency', 'idempotency_fingerprint' => 'private-diagnostics-fingerprint',
        'business_key' => 'private-diagnostics-business',
    ]);
    config(['talkto.models.message' => UndispatchedSensitiveKeyMessage::class, 'diagnostics.sensitive_key' => $key]);
    UndispatchedSensitiveKeyMessage::$hydrated = 0;
    $before = undispatchedDiagnosticSnapshot();
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        expect(fn () => app(TalktoUndispatchedInspector::class)->inspect())->toThrow(InvalidArgumentException::class);
        expect(Artisan::call('talkto:recover-undispatched', ['--json' => true]))->toBe(1);
        expect(Artisan::output())->not->toContain('private-diagnostics-', 'select ', 'bindings')
            ->and(DB::getQueryLog())->toBe([])
            ->and(UndispatchedSensitiveKeyMessage::$hydrated)->toBe(0);
    } finally {
        DB::disableQueryLog();
    }
    expect(undispatchedDiagnosticSnapshot())->toBe($before);
    Queue::assertNothingPushed();
})->with([
    'payload', 'last_response', 'last_error', 'locked_by', 'payload_hash', 'idempotency_key', 'idempotency_fingerprint', 'business_key',
    'PAYLOAD', 'LAST_RESPONSE', 'LAST_ERROR', 'LOCKED_BY', 'PAYLOAD_HASH', 'IDEMPOTENCY_KEY', 'IDEMPOTENCY_FINGERPRINT', 'BUSINESS_KEY',
    'talkto_messages.locked_by', 'locked_by as safe_key', 'locked_by->token',
]);

test('case insensitive database matches never weaken exact current service or direction ownership', function (): void {
    config(['database.connections.diagnostics_custom' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'talkto.models.message' => UndispatchedCustomKeyMessage::class]);
    $db = DB::connection('diagnostics_custom');
    $db->statement('CREATE TABLE diagnostic_custom_messages (
        candidate_key TEXT PRIMARY KEY, message_id TEXT, direction TEXT COLLATE NOCASE,
        source_service TEXT COLLATE NOCASE, target_service TEXT COLLATE NOCASE,
        overall_status TEXT, transport_status TEXT, destination_action_status TEXT,
        created_at TEXT, locked_at TEXT, locked_by TEXT, next_retry_at TEXT, next_attempt_at TEXT
    )');
    $rows = [
        ['z-valid-out', 'outgoing', 'testing', 'peer'], ['a-foreign-source', 'outgoing', 'TESTING', 'peer'],
        ['b-foreign-direction', 'OUTGOING', 'testing', 'peer'], ['z-valid-in', 'incoming', 'peer', 'testing'],
        ['c-foreign-target', 'incoming', 'peer', 'TESTING'], ['d-foreign-in-direction', 'INCOMING', 'peer', 'testing'],
    ];
    foreach ($rows as [$key, $direction, $source, $target]) {
        $incoming = strtolower($direction) === 'incoming';
        $db->table('diagnostic_custom_messages')->insert([
            'candidate_key' => $key, 'message_id' => $key, 'direction' => $direction,
            'source_service' => $source, 'target_service' => $target,
            'overall_status' => $incoming ? 'queued' : 'waiting_to_send',
            'transport_status' => $incoming ? null : 'pending', 'destination_action_status' => $incoming ? 'queued' : null,
            'created_at' => '2026-10-28 10:00:00',
        ]);
    }
    $snapshot = fn (): array => $db->table('diagnostic_custom_messages')->orderBy('candidate_key')->get()->map(fn ($row): array => (array) $row)->all();
    $before = $snapshot();
    $report = app(TalktoUndispatchedInspector::class)->inspect();
    $underfilled = app(TalktoUndispatchedInspector::class)->inspect(null, null, 30, 1);

    expect($report['count'])->toBe(2)
        ->and(array_column($report['candidates'], 'database_id'))->toBe(['z-valid-in', 'z-valid-out'])
        ->and($underfilled['count'])->toBe(0)
        ->and($snapshot())->toBe($before);
    Queue::assertNothingPushed();
});

function undispatchedDiagnosticInsert(array $overrides = []): int
{
    $id = (int) DB::table('talkto_messages')->insertGetId(array_merge([
        'message_id' => Str::uuid()->toString(), 'direction' => 'outgoing', 'source_service' => 'testing',
        'target_service' => 'peer', 'command' => 'domain.command', 'overall_status' => 'waiting_to_send',
        'transport_status' => 'pending', 'payload' => '{"private":"synthetic"}', 'retry_count' => 0,
        'created_at' => '2026-10-28 10:00:00', 'updated_at' => '2026-10-28 10:00:00',
    ], $overrides));

    return $id;
}

function undispatchedDiagnosticSnapshot(): array
{
    $result = [];
    foreach (['talkto_messages', 'talkto_attempts', 'talkto_events', 'talkto_dead_letters', 'talkto_nonces'] as $table) {
        $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }

    return $result;
}

class UndispatchedScopedMessage extends TalktoMessage
{
    public static int $hydrated = 0;

    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('diagnostic-visible', fn (Builder $query) => $query->distinct()->where('business_key', 'visible'));
        static::retrieved(function (): void {
            self::$hydrated++;
        });
    }
}

class UndispatchedCustomKeyMessage extends TalktoMessage
{
    protected $connection = 'diagnostics_custom';

    protected $primaryKey = 'candidate_key';

    protected $keyType = 'string';

    public $incrementing = false;

    public function getTable()
    {
        return 'diagnostic_custom_messages';
    }
}

class UndispatchedUnusedEvent extends TalktoEvent
{
    protected static function booted(): void
    {
        throw new RuntimeException('Read-only diagnostics must not instantiate or mutate an event model.');
    }
}

class UndispatchedUnsupportedScopeMessage extends TalktoMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('diagnostic-unsupported', function (Builder $query): void {
            match (config('diagnostics.scope')) {
                'group' => $query->groupBy('message_id'),
                'having' => $query->havingRaw('count(*) > 0'),
                'union' => $query->union(TalktoMessage::query()),
            };
        });
    }
}

class UndispatchedLockKeyMessage extends UndispatchedCustomKeyMessage
{
    protected $primaryKey = '_talkto_lock_present';
}

class UndispatchedClaimKeyMessage extends UndispatchedCustomKeyMessage
{
    protected $primaryKey = '_talkto_claim_present';
}

class UndispatchedThrowingScopeMessage extends TalktoMessage
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('diagnostic-throwing', function (): never {
            $message = 'synthetic-password private-sql select payload private-binding';
            throw config('diagnostics.invalid_argument') ? new InvalidArgumentException($message) : new RuntimeException($message);
        });
    }
}

class UndispatchedSensitiveKeyMessage extends TalktoMessage
{
    public static int $hydrated = 0;

    public function getKeyName()
    {
        return config('diagnostics.sensitive_key');
    }

    protected static function booted(): void
    {
        parent::booted();
        static::retrieved(function (): void {
            self::$hydrated++;
        });
    }
}
