<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Services\TalktoStaleMessageRecoveryService;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Mrezdev\LaravelTalkto\Support\TalktoRetryDecision;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    expect($this->artisan('migrate')->run())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'));
    TalktoDispatchTestHooks::reset();
    OpqhRecordingRetryPolicy::$observed = [];
    OpqhTrackedMessage::$hydrations = [];

    config([
        'talkto.service' => 'testing',
        'talkto.retry.enabled' => true,
        'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.incoming_enabled' => true,
        'talkto.retry.max_attempts' => 5,
        'talkto.retry.backoff_seconds' => [10, 30, 60],
        'talkto.retry.retryable_statuses' => ['failed_retryable'],
        'talkto.dead_letter.enabled' => true,
        'talkto.dead_letter.auto_store_on_final_failure' => true,
    ]);
});

afterEach(function (): void {
    TalktoDispatchTestHooks::reset();
    $this->travelBack();
});

test('retry candidate filters and processing order retain the due direction and service contract', function (string $direction): void {
    Queue::fake();
    app()->instance(TalktoRetryPolicy::class, new OpqhRecordingRetryPolicy);
    $outLater = opqhInsert(['next_retry_at' => '2026-10-28 11:50:00']);
    $inEarlier = opqhInsert(['direction' => 'incoming', 'next_retry_at' => '2026-10-28 11:30:00']);
    $outEarlier = opqhInsert(['next_retry_at' => '2026-10-28 11:40:00']);
    $foreign = opqhInsert(['source_service' => 'foreign', 'next_retry_at' => '2026-10-28 11:55:00']);
    opqhInsert(['next_retry_at' => null]);
    opqhInsert(['next_retry_at' => '2026-10-28 12:00:01']);
    opqhInsert(['overall_status' => 'completed']);
    opqhInsert(['overall_status' => 'succeeded']);
    opqhInsert(['overall_status' => 'failed_final']);
    $expected = match ($direction) {
        'incoming' => [$inEarlier],
        'outgoing' => [$outEarlier, $outLater, $foreign],
        'all' => [$inEarlier, $outEarlier, $outLater, $foreign],
    };
    $skipped = $direction === 'incoming' ? 0 : 1;

    [$output, $queries] = opqhCapture(fn () => opqhRetry(['--direction' => $direction, '--dry-run' => true]));

    expect(array_column(OpqhRecordingRetryPolicy::$observed, 'key'))->toBe($expected)
        ->and(trim($output))->toBe('scanned='.count($expected).' eligible='.(count($expected) - $skipped).' dispatched=0 skipped='.$skipped.' dry_run=true direction='.$direction.' skip_reasons='.($skipped ? '{"wrong_service":1}' : '[]'));
    opqhAssertSelector($queries[0], 'next_retry_at', 100);
    expect($queries[0]['query'])->toContain('"overall_status" in (?)', '"next_retry_at" is not null', '"next_retry_at" <= ?');

    if ($direction !== 'all') {
        expect($queries[0]['query'])->toContain('"direction" = ?');
    }

    Queue::assertNothingPushed();
    expect(TalktoEvent::query()->count())->toBe(0);
})->with(['incoming', 'outgoing', 'all']);

test('retry maximum candidate count is fixed once and full-row queries use bounded batches', function (int $limit): void {
    config(['talkto.models.message' => OpqhTrackedMessage::class]);
    app()->instance(TalktoRetryPolicy::class, new OpqhRecordingRetryPolicy);

    foreach (range(1, $limit + 2) as $index) {
        opqhInsert(['next_retry_at' => now()->subSeconds($index)->toDateTimeString()]);
    }

    [$output, $queries] = opqhCapture(fn () => opqhRetry(['--limit' => $limit, '--dry-run' => true]));

    expect($output)->toContain('scanned='.$limit.' eligible='.$limit.' dispatched=0 skipped=0')
        ->and(OpqhTrackedMessage::$hydrations)->toHaveCount($limit)
        ->and($queries)->toHaveCount(1 + (int) ceil($limit / 10));
    opqhAssertSelector($queries[0], 'next_retry_at', $limit);

    foreach (array_slice($queries, 1) as $query) {
        expect($query['query'])->not->toContain('order by', '"next_retry_at" <=', '"overall_status" in')
            ->toContain('"talkto_messages"."id" in (');
        preg_match('/ in \(([^)]+)\)/', $query['query'], $keys);
        expect(count(explode(',', $keys[1])))->toBeLessThanOrEqual(10);
    }

    // IDs were inserted in the opposite order from their retry times.
    expect(array_column(OpqhRecordingRetryPolicy::$observed, 'key'))->toBe(range($limit + 2, 3));
})->with([1, 10, 1000]);

test('retry preserves timestamp ties without introducing a new secondary sort', function (): void {
    app()->instance(TalktoRetryPolicy::class, new OpqhRecordingRetryPolicy);
    $ids = [opqhInsert(), opqhInsert(), opqhInsert()];
    [$output, $queries] = opqhCapture(fn () => opqhRetry(['--limit' => 2, '--dry-run' => true]));

    opqhAssertSelector($queries[0], 'next_retry_at', 2);
    expect(OpqhRecordingRetryPolicy::$observed)->toHaveCount(2)
        ->and(array_diff(array_column(OpqhRecordingRetryPolicy::$observed, 'key'), $ids))->toBe([])
        ->and($output)->toContain('scanned=2 eligible=2');
});

test('retry empty candidate selection runs no hydration query', function (): void {
    [$output, $queries] = opqhCapture(fn () => opqhRetry(['--dry-run' => true]));

    expect(trim($output))->toBe('scanned=0 eligible=0 dispatched=0 skipped=0 dry_run=true direction=all skip_reasons=[]')
        ->and($queries)->toHaveCount(1);
    opqhAssertSelector($queries[0], 'next_retry_at', 100);
});

test('operational command invalid limits remain rejected before any candidate query', function (string $command, mixed $limit): void {
    [$exit, $queries] = opqhCapture(fn () => Artisan::call($command, ['--limit' => $limit, '--dry-run' => true]));

    expect($exit)->toBe(1)->and($queries)->toBe([])
        ->and(Artisan::output())->toContain('Invalid --limit. Use a value between 1 and 1000.');
})->with(['talkto:retry-failed', 'talkto:recover-stale'])->with([0, -1, 1001, 'invalid']);

test('custom retry statuses policy and job dispatch keep original counters schedules and events', function (): void {
    Queue::fake();
    config([
        'talkto.retry.retryable_statuses' => ['waiting_custom'],
        'talkto.retry.commands.domain.sync.backoff_seconds' => [19, 37, 91],
        'talkto.jobs.send_message' => OpqhSendJob::class,
    ]);
    $id = opqhInsert(['overall_status' => 'waiting_custom', 'retry_count' => 2, 'attempts' => 3]);
    opqhInsert(['overall_status' => 'failed_retryable']);
    $before = TalktoMessage::query()->findOrFail($id);
    $expectedDelay = app(TalktoRetryPolicy::class)->backoffSeconds(2, $before);

    $output = opqhRetry();
    $after = $before->fresh();

    Queue::assertPushed(OpqhSendJob::class, 1);
    expect($expectedDelay)->toBe(91)
        ->and($output)->toContain('scanned=1 eligible=1 dispatched=1 skipped=0')
        ->and($after->retry_count)->toBe(2)
        ->and($after->attempts)->toBe(3)
        ->and($after->overall_status)->toBe('waiting_to_send')
        ->and($after->locked_by)->toStartWith('dispatch-claim:')
        ->and(TalktoEvent::query()->where('message_id', $before->message_id)->pluck('event_type')->all())->toBe(['retry_dispatched']);
    opqhRetry();
    Queue::assertPushed(OpqhSendJob::class, 1);
});

test('retry dispatch exceptions retain compensation and failure event semantics', function (): void {
    config(['talkto.jobs.send_message' => OpqhFailingSendJob::class]);
    $id = opqhInsert(['retry_count' => 2]);
    $before = TalktoMessage::query()->findOrFail($id);
    $output = opqhRetry();
    $after = $before->fresh();

    expect($output)->toContain('scanned=1 eligible=1 dispatched=0 skipped=1', '"dispatch_failed":1')
        ->and($after->overall_status)->toBe($before->overall_status)
        ->and($after->retry_count)->toBe(2)
        ->and($after->next_retry_at->equalTo($before->next_retry_at))->toBeTrue()
        ->and($after->locked_by)->toBeNull()
        ->and(TalktoEvent::query()->where('message_id', $before->message_id)->pluck('event_type')->all())->toBe(['retry_dispatch_failed']);
});

test('retry materialization never dispatches changed deleted hidden or already claimed candidates', function (string $mutation): void {
    Queue::fake();
    if ($mutation === 'soft delete') {
        Schema::table('talkto_messages', fn (Blueprint $table) => $table->softDeletes());
        config(['talkto.models.message' => OpqhSoftMessage::class]);
    }
    $first = opqhInsert(['next_retry_at' => '2026-10-28 11:00:00']);
    $outsideLimit = opqhInsert(['next_retry_at' => '2026-10-28 11:30:00']);
    opqhAfterSelector('next_retry_at', function () use ($mutation, $first): void {
        if ($mutation === 'delete') {
            DB::table('talkto_messages')->where('id', $first)->delete();

            return;
        }
        $attributes = match ($mutation) {
            'soft delete' => ['deleted_at' => now()],
            'terminal' => ['overall_status' => 'completed', 'transport_status' => 'completed'],
            'not due' => ['next_retry_at' => now()->addHour()],
            'wrong service' => ['source_service' => 'foreign'],
            'claimed' => ['overall_status' => 'waiting_to_send', 'transport_status' => 'pending', 'locked_at' => now(), 'locked_by' => 'dispatch-claim:other'],
        };
        DB::table('talkto_messages')->where('id', $first)->update($attributes);
    });

    opqhRetry(['--limit' => 1]);

    Queue::assertNothingPushed();
    expect(DB::table('talkto_messages')->where('id', $outsideLimit)->value('overall_status'))->toBe('failed_retryable')
        ->and(TalktoEvent::query()->where('event_type', 'retry_dispatched')->count())->toBe(0);
})->with(['delete', 'soft delete', 'terminal', 'not due', 'wrong service', 'claimed']);

test('retry processes selected IDs in order across batches without replenishing a disappeared row', function (): void {
    app()->instance(TalktoRetryPolicy::class, new OpqhRecordingRetryPolicy);
    foreach (range(1, 23) as $index) {
        opqhInsert(['next_retry_at' => now()->subSeconds($index)->toDateTimeString()]);
    }
    opqhAfterSelector('next_retry_at', fn () => DB::table('talkto_messages')->where('id', 12)->delete());
    [$output, $queries] = opqhCapture(fn () => opqhRetry(['--limit' => 22, '--dry-run' => true]));
    $expected = array_values(array_diff(range(23, 2), [12]));

    expect(array_column(OpqhRecordingRetryPolicy::$observed, 'key'))->toBe($expected)
        ->and($output)->toContain('scanned=21 eligible=21')
        ->and($queries)->toHaveCount(4);
});

test('retry locked claims revalidate changes made after complete batch rows were read', function (string $mutation): void {
    Queue::fake();
    $id = opqhInsert();
    $changed = false;
    DB::listen(function (QueryExecuted $query) use ($id, $mutation, &$changed): void {
        if ($changed || ! str_starts_with($query->sql, 'select * from "talkto_messages"') || ! str_contains($query->sql, ' in (')) {
            return;
        }

        // The SELECT already returned its row snapshot. Mutate storage before
        // that snapshot reaches claimRetry(), which must reload current state.
        $changed = true;
        DB::table('talkto_messages')->where('id', $id)->update(match ($mutation) {
            'terminal' => ['overall_status' => 'completed', 'transport_status' => 'completed'],
            'not due' => ['next_retry_at' => now()->addHour()],
            'wrong service' => ['source_service' => 'foreign'],
            'other claim' => ['overall_status' => 'waiting_to_send', 'transport_status' => 'pending', 'locked_by' => 'dispatch-claim:other', 'locked_at' => now()],
        });
    });

    $output = opqhRetry();

    Queue::assertNothingPushed();
    expect($changed)->toBeTrue()
        ->and($output)->toContain('scanned=1 eligible=0 dispatched=0 skipped=1')
        ->and(TalktoEvent::query()->where('event_type', 'retry_dispatched')->count())->toBe(0);
})->with(['terminal', 'not due', 'wrong service', 'other claim']);

test('recovery selectors retain all four state branches cutoff direction and order', function (?string $direction): void {
    $ids = [];
    foreach (['outgoing', 'incoming', 'orphan outgoing', 'orphan incoming'] as $index => $kind) {
        $ids[$kind] = opqhStale($kind, ['locked_at' => now()->subMinutes(40 - $index)->toDateTimeString()]);
    }
    opqhStale('outgoing', ['locked_at' => now()->subMinute()->toDateTimeString()]);
    opqhStale('incoming', ['locked_at' => null]);
    opqhStale('outgoing', ['overall_status' => 'completed']);
    opqhStale('incoming', ['destination_action_status' => 'queued']);
    opqhStale('orphan outgoing', ['locked_by' => 'ordinary-worker']);
    opqhStale('orphan incoming', ['locked_by' => 'ordinary-worker']);
    $expected = array_values(array_filter($ids, fn (int $id, string $kind): bool => $direction === null || str_ends_with($kind, $direction), ARRAY_FILTER_USE_BOTH));

    [$summary, $queries] = opqhCapture(fn () => app(TalktoStaleMessageRecoveryService::class)->recover($direction, 15, 100, true));

    expect(array_column($summary['messages'], 'id'))->toBe($expected)
        ->and(array_keys($summary))->toBe(['candidates', 'recovered', 'stale_processing_recovered', 'orphaned_dispatch_claims_recovered', 'failed', 'skipped', 'dispatch_claims_skipped', 'claim_changed', 'dispatched', 'dry_run', 'messages'])
        ->and($summary)->toMatchArray(['candidates' => count($expected), 'recovered' => 0, 'failed' => 0, 'skipped' => 0, 'dispatched' => 0, 'dry_run' => true]);
    opqhAssertSelector($queries[0], 'locked_at', 100);
    expect($queries[0]['query'])->toContain('"locked_at" is not null', '"locked_at" <= ?', '"locked_by" like ?', '"transport_status" = ?', '"destination_action_status" = ?')
        ->and($queries[0]['bindings'])->toContain('sending', 'processing', 'dispatch-claim:%', 'waiting_to_send', 'pending', 'queued');
})->with([null, 'incoming', 'outgoing']);

test('recovery limit ties and bounded hydration preserve only the original selected set', function (int $limit): void {
    foreach (range(1, $limit + 2) as $index) {
        opqhStale('outgoing', ['locked_at' => now()->subMinutes(30)->subSeconds($index)->toDateTimeString()]);
    }
    [$summary, $queries] = opqhCapture(fn () => app(TalktoStaleMessageRecoveryService::class)->recover('outgoing', 15, $limit, true));

    expect($summary['candidates'])->toBe($limit)
        ->and(array_column($summary['messages'], 'id'))->toBe(range($limit + 2, 3))
        ->and($queries)->toHaveCount(1 + (int) ceil($limit / 10));
    opqhAssertSelector($queries[0], 'locked_at', $limit);
    foreach (array_slice($queries, 1) as $query) {
        expect($query['query'])->not->toContain('order by', '"locked_at" <=', '"transport_status" =');
    }
})->with([1, 10, 1000]);

test('recovery timestamp ties add no id ordering and empty scans hydrate nothing', function (): void {
    [$summary, $queries] = opqhCapture(fn () => app(TalktoStaleMessageRecoveryService::class)->recover(null, 15, 10, true));
    expect($summary['candidates'])->toBe(0)->and($summary['messages'])->toBe([])->and($queries)->toHaveCount(1);
    opqhStale('outgoing');
    opqhStale('incoming');
    [$summary, $queries] = opqhCapture(fn () => app(TalktoStaleMessageRecoveryService::class)->recover(null, 15, 1, true));
    expect($summary['candidates'])->toBe(1)->and($summary['messages'])->toHaveCount(1);
    opqhAssertSelector($queries[0], 'locked_at', 1);
});

test('recovery processing preserves normal and orphan claim outcomes events and duplicate protection', function (string $kind): void {
    Queue::fake();
    $id = opqhStale($kind);
    $orphan = str_starts_with($kind, 'orphan');
    $summary = app(TalktoStaleMessageRecoveryService::class)->recover(null, 15, 10, false);
    $second = app(TalktoStaleMessageRecoveryService::class)->recover(null, 15, 10, false);

    expect($summary)->toMatchArray(['candidates' => 1, 'recovered' => 1, 'dispatched' => 1, 'failed' => 0, 'skipped' => 0, 'stale_processing_recovered' => $orphan ? 0 : 1, 'orphaned_dispatch_claims_recovered' => $orphan ? 1 : 0])
        ->and($second['recovered'])->toBe(0)
        ->and(TalktoEvent::query()->pluck('event_type')->all())->toBe([$orphan ? 'orphaned_dispatch_claim_recovered' : 'stale_lock_recovered']);
    Queue::assertPushed(str_ends_with($kind, 'incoming') ? ProcessIncomingTalktoMessage::class : SendTalktoMessage::class, 1);
    expect(DB::table('talkto_messages')->where('id', $id)->value('locked_by'))->toStartWith('dispatch-claim:');
})->with(['outgoing', 'incoming', 'orphan outgoing', 'orphan incoming']);

test('recovery final failure and dispatch compensation remain unchanged', function (bool $exhausted): void {
    Queue::fake();
    if (! $exhausted) {
        config(['talkto.jobs.send_message' => OpqhFailingSendJob::class]);
    }
    $id = opqhStale('outgoing', ['attempts' => $exhausted ? 5 : 1]);
    $before = TalktoMessage::query()->findOrFail($id);
    $summary = app(TalktoStaleMessageRecoveryService::class)->recover('outgoing', 15, 10, false);
    $after = $before->fresh();

    Queue::assertNothingPushed();
    expect($summary)->toMatchArray(['candidates' => 1, 'recovered' => 0, 'failed' => 1, 'dispatched' => 0]);
    if ($exhausted) {
        expect($after->overall_status)->toBe('failed_final')
            ->and($after->locked_at)->toBeNull()
            ->and(TalktoDeadLetter::query()->where('message_id', $before->message_id)->exists())->toBeTrue()
            ->and(TalktoEvent::query()->pluck('event_type')->all())->toContain('stale_lock_recovery_exhausted');
    } else {
        expect($after->overall_status)->toBe('sending')
            ->and($after->locked_by)->toBe($before->locked_by)
            ->and($after->locked_at->equalTo($before->locked_at))->toBeTrue()
            ->and(TalktoEvent::query()->pluck('event_type')->all())->toContain('stale_lock_recovery_dispatch_failed');
    }
})->with([true, false]);

test('recovery never dispatches candidates deleted completed refreshed hidden or moved after selection', function (string $mutation): void {
    Queue::fake();
    if ($mutation === 'soft delete') {
        Schema::table('talkto_messages', fn (Blueprint $table) => $table->softDeletes());
        config(['talkto.models.message' => OpqhSoftMessage::class]);
    }
    $id = opqhStale('outgoing', ['locked_at' => '2026-10-28 10:00:00']);
    $outside = opqhStale('outgoing', ['locked_at' => '2026-10-28 11:00:00']);
    opqhAfterSelector('locked_at', function () use ($mutation, $id): void {
        if ($mutation === 'delete') {
            DB::table('talkto_messages')->where('id', $id)->delete();

            return;
        }
        DB::table('talkto_messages')->where('id', $id)->update(match ($mutation) {
            'soft delete' => ['deleted_at' => now()],
            'completed' => ['overall_status' => 'completed', 'transport_status' => 'completed'],
            'fresh lock' => ['locked_at' => now(), 'locked_by' => 'sender:active'],
            'new claim' => ['overall_status' => 'waiting_to_send', 'transport_status' => 'pending', 'locked_at' => now(), 'locked_by' => 'dispatch-claim:active'],
            'wrong service' => ['source_service' => 'foreign'],
        });
    });

    $summary = app(TalktoStaleMessageRecoveryService::class)->recover('outgoing', 15, 1, false);

    Queue::assertNothingPushed();
    expect($summary['recovered'])->toBe(0)
        ->and(DB::table('talkto_messages')->where('id', $outside)->value('overall_status'))->toBe('sending');
})->with(['delete', 'soft delete', 'completed', 'fresh lock', 'new claim', 'wrong service']);

test('claim revalidation still defeats a fresh lock changed after batch hydration', function (): void {
    Queue::fake();
    $id = opqhStale('outgoing');
    TalktoDispatchTestHooks::set('recovery.before_claim', function () use ($id): void {
        TalktoDispatchTestHooks::reset('recovery.before_claim');
        DB::table('talkto_messages')->where('id', $id)->update(['locked_at' => now(), 'locked_by' => 'sender:active']);
    });

    $summary = app(TalktoStaleMessageRecoveryService::class)->recover(null, 15, 10, false);

    Queue::assertNothingPushed();
    expect($summary)->toMatchArray(['candidates' => 1, 'recovered' => 0, 'skipped' => 1, 'claim_changed' => 1]);
});

test('large payload batches retain native attributes while selectors never hydrate models', function (int $bytes): void {
    config(['talkto.models.message' => OpqhTrackedMessage::class]);
    app()->instance(TalktoRetryPolicy::class, new OpqhRecordingRetryPolicy);
    $payload = json_encode(['body' => str_repeat('x', $bytes)], JSON_THROW_ON_ERROR);
    foreach (range(1, 21) as $index) {
        opqhInsert(['payload' => $payload, 'last_response' => 'response-'.$index]);
    }
    unset($payload);

    [$output, $queries] = opqhCapture(fn () => opqhRetry(['--dry-run' => true]));

    opqhAssertSelector($queries[0], 'next_retry_at', 100);
    expect($queries)->toHaveCount(4)
        ->and(OpqhTrackedMessage::$hydrations)->toHaveCount(21)
        ->and(array_unique(array_column(OpqhRecordingRetryPolicy::$observed, 'payload_bytes')))->toBe([$bytes])
        ->and($output)->toContain('scanned=21 eligible=21');
    foreach (OpqhTrackedMessage::$hydrations as $hydration) {
        expect($hydration)->toContain('payload', 'last_response', 'overall_status');
    }
})->with([256 * 1024, 1024 * 1024, (int) (2.4 * 1024 * 1024)]);

test('configured custom model scopes raw string keys table connection and date casts survive both stages', function (): void {
    config([
        'database.connections.opqh_custom' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        'talkto.database.connection' => 'opqh_custom',
        'talkto.database.tables.messages' => 'opqh_messages',
        'talkto.models.message' => OpqhCustomMessage::class,
    ]);
    DB::purge('opqh_custom');
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migration) {
        (require $migration)->up();
    }
    Schema::connection('opqh_custom')->table('opqh_messages', function (Blueprint $table): void {
        $table->string('candidate_key')->nullable()->unique();
        $table->softDeletes();
    });
    app()->instance(TalktoRetryPolicy::class, new OpqhRecordingRetryPolicy);
    foreach ([['z', '11:30:00', null, 'visible'], ['0', '11:10:00', null, 'visible'], ['a', '11:20:00', null, 'visible'], ['hidden', '11:00:00', null, 'hidden'], ['deleted', '10:59:00', '2026-10-28 11:59:00', 'visible']] as [$key, $due, $deleted, $business]) {
        opqhInsert(['candidate_key' => $key, 'next_retry_at' => '2026-10-28 '.$due, 'deleted_at' => $deleted, 'business_key' => $business]);
    }

    try {
        [$output, $queries] = opqhCapture(fn () => opqhRetry(['--dry-run' => true]), 'opqh_custom', 'opqh_messages');

        expect(array_column(OpqhRecordingRetryPolicy::$observed, 'key'))->toBe(['0', 'a', 'z'])
            ->and(array_column(OpqhRecordingRetryPolicy::$observed, 'class'))->toBe(array_fill(0, 3, OpqhCustomMessage::class))
            ->and(array_column(OpqhRecordingRetryPolicy::$observed, 'immutable'))->toBe([true, true, true])
            ->and(array_column(OpqhRecordingRetryPolicy::$observed, 'scoped_payload'))->toBe(['{"body":"fixture"}', '{"body":"fixture"}', '{"body":"fixture"}'])
            ->and($output)->toContain('scanned=3 eligible=3')
            ->and($queries)->toHaveCount(2)
            ->and($queries[0]['query'])->toStartWith('select "opqh_messages"."candidate_key" from "opqh_messages"')
            ->toContain('order by "next_retry_at" asc, "id" desc')
            ->not->toContain('payload', 'select *')
            ->and($queries[1]['query'])->toContain('"candidate_key" in', '"opqh_messages".*', '"payload" as "scoped_payload"')
            ->not->toContain('order by');
        foreach ($queries as $query) {
            expect($query['query'])->toContain('"business_key" = ?', '"opqh_messages"."deleted_at" is null');
        }
    } finally {
        DB::purge('opqh_custom');
    }
});

function opqhInsert(array $attributes = []): int
{
    $direction = $attributes['direction'] ?? 'outgoing';
    $model = new TalktoMessage;

    return $model->getConnection()->table($model->getTable())->insertGetId(array_merge([
        'message_id' => (string) Str::uuid(),
        'direction' => $direction,
        'source_service' => $direction === 'incoming' ? 'peer' : 'testing',
        'target_service' => $direction === 'incoming' ? 'testing' : 'peer',
        'command' => 'domain.sync',
        'overall_status' => 'failed_retryable',
        'transport_status' => 'failed',
        'destination_action_status' => $direction === 'incoming' ? 'failed_retryable' : null,
        'retry_count' => 1,
        'attempts' => 1,
        'max_attempts' => 5,
        'next_retry_at' => '2026-10-28 11:59:00',
        'next_attempt_at' => '2026-10-28 11:59:00',
        'payload' => '{"body":"fixture"}',
        'created_at' => '2026-10-28 10:00:00',
        'updated_at' => '2026-10-28 11:59:00',
    ], $attributes));
}

function opqhStale(string $kind, array $attributes = []): int
{
    $incoming = str_ends_with($kind, 'incoming');
    $orphan = str_starts_with($kind, 'orphan');

    return opqhInsert(array_merge([
        'direction' => $incoming ? 'incoming' : 'outgoing',
        'overall_status' => $incoming ? ($orphan ? 'queued' : 'processing') : ($orphan ? 'waiting_to_send' : 'sending'),
        'transport_status' => $incoming ? null : ($orphan ? 'pending' : 'sending'),
        'destination_action_status' => $incoming ? ($orphan ? 'queued' : 'processing') : null,
        'locked_at' => '2026-10-28 11:30:00',
        'locked_by' => $orphan ? 'dispatch-claim:abandoned' : 'worker:abandoned',
    ], $attributes));
}

function opqhRetry(array $options = []): string
{
    expect(Artisan::call('talkto:retry-failed', $options))->toBe(0);

    return Artisan::output();
}

function opqhCapture(Closure $callback, ?string $connectionName = null, string $table = 'talkto_messages'): array
{
    $db = DB::connection($connectionName);
    $db->flushQueryLog();
    $db->enableQueryLog();
    try {
        $result = $callback();
        $queries = array_values(array_filter($db->getQueryLog(), fn (array $query): bool => str_starts_with($query['query'], 'select ')
            && str_contains($query['query'], 'from "'.$table.'"')));

        return [$result, $queries];
    } finally {
        $db->disableQueryLog();
        $db->flushQueryLog();
    }
}

function opqhAssertSelector(array $query, string $order, int $limit): void
{
    expect($query['query'])->toStartWith('select "talkto_messages"."id" from "talkto_messages"')
        ->toEndWith('order by "'.$order.'" asc limit '.$limit)
        ->not->toContain('select *', 'payload', 'last_response', 'last_error', '"id" asc');
}

function opqhAfterSelector(string $order, Closure $callback): void
{
    $changed = false;
    DB::listen(function (QueryExecuted $query) use ($order, $callback, &$changed): void {
        if (! $changed && str_starts_with($query->sql, 'select "talkto_messages"."id" from') && str_contains($query->sql, 'order by "'.$order.'" asc')) {
            $changed = true;
            $callback();
        }
    });
}

class OpqhRecordingRetryPolicy extends TalktoRetryPolicy
{
    public static array $observed = [];

    public function decisionFor(TalktoMessage $message, array $context = []): TalktoRetryDecision
    {
        static::$observed[] = [
            'key' => $message->getRawOriginal($message->getKeyName()),
            'class' => $message::class,
            'payload_bytes' => strlen($message->payload['body'] ?? ''),
            'immutable' => $message->next_retry_at instanceof CarbonImmutable,
            'scoped_payload' => $message->getAttributes()['scoped_payload'] ?? null,
        ];

        return parent::decisionFor($message, $context);
    }
}

class OpqhTrackedMessage extends TalktoMessage
{
    public static array $hydrations = [];

    public function newFromBuilder($attributes = [], $connection = null)
    {
        static::$hydrations[] = array_keys((array) $attributes);

        return parent::newFromBuilder($attributes, $connection);
    }
}

class OpqhSoftMessage extends TalktoMessage
{
    use SoftDeletes;
}

class OpqhCustomMessage extends TalktoMessage
{
    use SoftDeletes;

    protected $primaryKey = 'candidate_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['next_retry_at' => 'immutable_datetime'];
    }

    public function getCandidateKeyAttribute(mixed $value): string
    {
        return 'accessor:'.$value;
    }

    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('visible', function (Builder $query): void {
            $query->where('business_key', 'visible')->addSelect($query->getModel()->getTable().'.*')->addSelect('payload as scoped_payload')->orderByDesc('id');
        });
    }
}

class OpqhSendJob extends SendTalktoMessage {}

class OpqhFailingSendJob extends SendTalktoMessage
{
    public static function dispatch(...$arguments): mixed
    {
        throw new RuntimeException('Synthetic operational queue failure.');
    }
}
