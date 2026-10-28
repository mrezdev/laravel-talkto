<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Services\TalktoCallbackStatusInspector;
use Mrezdev\LaravelTalkto\Services\TalktoTraceReporter;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);
});

test('dead letter detail preserves each identifier match and complete attributes', function (string $match): void {
    $message = pdlQueryMessage();
    $attributes = match ($match) {
        'database id' => ['talkto_message_id' => $message->getKey(), 'message_id' => 'different-message'],
        'message id' => ['talkto_message_id' => null, 'message_id' => $message->message_id],
        'both' => ['talkto_message_id' => $message->getKey(), 'message_id' => $message->message_id],
    };
    $id = pdlQueryInsert($attributes);
    pdlQueryInsert(['message_id' => 'unrelated', 'created_at' => '2026-10-28 23:59:59']);

    $expected = TalktoDeadLetter::query()->findOrFail($id);
    [$actual, $queries] = pdlQueryCapture(fn () => app(TalktoPanelMessageQuery::class)->deadLetterFor($message));

    expect($actual)->toBeInstanceOf(TalktoDeadLetter::class)
        ->and($actual->getRawOriginal())->toBe($expected->getRawOriginal())
        ->and($actual->getCasts())->toBe($expected->getCasts());
    pdlQueryAssertTwoSteps($queries, $message, $id);
})->with(['database id', 'message id', 'both']);

test('dead letter identity equals original ordering for two distinct identifier matches', function (?string $firstCreated, ?string $secondCreated, bool $secondWins): void {
    $message = pdlQueryMessage();
    $first = pdlQueryInsert(['talkto_message_id' => $message->getKey(), 'message_id' => 'different', 'created_at' => $firstCreated]);
    $second = pdlQueryInsert(['message_id' => $message->message_id, 'created_at' => $secondCreated]);

    // The former single-query implementation is an identity/attribute oracle.
    $expected = TalktoDeadLetter::query()
        ->where(fn (Builder $query) => $query->where('talkto_message_id', $message->getKey())->orWhere('message_id', $message->message_id))
        ->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
    [$actual, $queries] = pdlQueryCapture(fn () => app(TalktoPanelMessageQuery::class)->deadLetterFor($message));

    expect($actual->getKey())->toBe($secondWins ? $second : $first)
        ->and($actual->getRawOriginal())->toBe($expected->getRawOriginal());
    pdlQueryAssertTwoSteps($queries, $message, $actual->getKey());
})->with([
    'newer FK match wins even with smaller id' => ['2026-10-28 12:00:00', '2026-10-28 11:00:00', false],
    'newer message id wins' => ['2026-10-28 11:00:00', '2026-10-28 12:00:00', true],
    'same timestamp chooses larger id' => ['2026-10-28 12:00:00', '2026-10-28 12:00:00', true],
    'NULL FK timestamp sorts last' => [null, '2026-10-28 12:00:00', true],
    'NULL message timestamp sorts last' => ['2026-10-28 12:00:00', null, false],
    'both timestamps NULL retain id tie break' => [null, null, true],
]);

test('empty and unmatched dead letter lookups do not fetch full rows', function (bool $withUnrelatedRow): void {
    $message = pdlQueryMessage();

    if ($withUnrelatedRow) {
        pdlQueryInsert(['message_id' => 'unrelated']);
    }

    [$actual, $queries] = pdlQueryCapture(fn () => app(TalktoPanelMessageQuery::class)->deadLetterFor($message));

    expect($actual)->toBeNull()
        ->and($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toStartWith('select "talkto_dead_letters"."id" from "talkto_dead_letters"')
        ->not->toContain('select *', 'payload', 'headers');
})->with([false, true]);

test('missing dead letter table preserves null without a data query', function (): void {
    $message = pdlQueryMessage();
    Schema::drop('talkto_dead_letters');

    [$actual, $queries] = pdlQueryCapture(fn () => app(TalktoPanelMessageQuery::class)->deadLetterFor($message));

    expect($actual)->toBeNull()->and($queries)->toBe([]);
});

test('null message database key retains original IS NULL matching behavior', function (): void {
    $message = (new TalktoMessage)->setRawAttributes(['id' => null, 'message_id' => 'unpersisted-message']);
    pdlQueryInsert(['message_id' => 'older', 'created_at' => '2026-10-28 11:00:00']);
    $newest = pdlQueryInsert(['message_id' => 'newer', 'created_at' => '2026-10-28 12:00:00']);

    [$actual, $queries] = pdlQueryCapture(fn () => app(TalktoPanelMessageQuery::class)->deadLetterFor($message));

    expect($actual->getKey())->toBe($newest)
        ->and($queries)->toHaveCount(2)
        ->and($queries[0]['query'])->toContain('"talkto_message_id" is null or "message_id" = ?');
});

test('large dead letter detail sorts only raw identity and hydrates the final complete model once', function (): void {
    config(['talkto.models.dead_letter' => PdlCountingDeadLetter::class]);
    PdlCountingDeadLetter::$hydrated = [];
    PdlCountingDeadLetter::$retrieved = 0;
    $message = pdlQueryMessage();
    $large = str_repeat('x', 256 * 1024);
    $id = pdlQueryInsert([
        'message_id' => $message->message_id,
        'payload' => json_encode(['body' => $large], JSON_THROW_ON_ERROR),
        'headers' => json_encode(['diagnostic' => $large], JSON_THROW_ON_ERROR),
        'failure_reason' => $large,
        'exception_message' => $large,
    ]);

    [$actual, $queries] = pdlQueryCapture(fn () => app(TalktoPanelMessageQuery::class)->deadLetterFor($message));

    pdlQueryAssertTwoSteps($queries, $message, $id);
    expect($actual)->toBeInstanceOf(PdlCountingDeadLetter::class)
        ->and($actual->payload)->toBe(['body' => $large])
        ->and($actual->headers)->toBe(['diagnostic' => $large])
        ->and($actual->failure_reason)->toBe($large)
        ->and($actual->exception_message)->toBe($large)
        ->and(PdlCountingDeadLetter::$hydrated)->toHaveCount(1)
        ->and(PdlCountingDeadLetter::$hydrated[0])->toContain('payload', 'headers', 'exception_message')
        ->and(PdlCountingDeadLetter::$retrieved)->toBe(1);
});

test('custom primary key raw value survives accessor and retains historical id tie ordering on custom connection', function (string $winningKey): void {
    $message = pdlQueryMessage();
    config([
        'database.connections.pdl_custom' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        'talkto.database.connection' => 'pdl_custom',
        'talkto.database.tables.dead_letters' => 'custom_pdl_ledger',
        'talkto.models.dead_letter' => PdlCustomKeyDeadLetter::class,
    ]);
    DB::purge('pdl_custom');
    Schema::connection('pdl_custom')->create('custom_pdl_ledger', function (Blueprint $table): void {
        $table->string('record_key')->primary();
        $table->unsignedBigInteger('id');
        $table->unsignedBigInteger('talkto_message_id')->nullable()->unique();
        $table->string('message_id')->nullable()->unique();
        $table->string('status');
        $table->json('payload')->nullable();
        $table->timestamps();
    });
    $db = DB::connection('pdl_custom');
    $db->table('custom_pdl_ledger')->insert([
        ['record_key' => 'zzz-first', 'id' => 10, 'talkto_message_id' => $message->getKey(), 'message_id' => 'different', 'status' => 'open', 'payload' => '{"one":1}', 'created_at' => '2026-10-28 12:00:00'],
        ['record_key' => $winningKey, 'id' => 20, 'talkto_message_id' => null, 'message_id' => $message->message_id, 'status' => 'open', 'payload' => '{"two":2}', 'created_at' => '2026-10-28 12:00:00'],
    ]);

    try {
        [$actual, $queries] = pdlQueryCapture(fn () => app(TalktoPanelMessageQuery::class)->deadLetterFor($message), 'pdl_custom', 'custom_pdl_ledger');

        expect($actual)->toBeInstanceOf(PdlCustomKeyDeadLetter::class)
            ->and($actual->getConnection()->getName())->toBe('pdl_custom')
            ->and($actual->getTable())->toBe('custom_pdl_ledger')
            ->and($actual->getRawOriginal('record_key'))->toBe($winningKey)
            ->and($actual->getKey())->toBe('accessor:'.$winningKey)
            ->and($actual->id)->toBe(20)
            ->and($actual->payload)->toBe(['two' => 2])
            ->and($queries)->toHaveCount(2)
            ->and($queries[0]['query'])->toStartWith('select "custom_pdl_ledger"."record_key" from "custom_pdl_ledger"')
            ->toContain('order by "created_at" desc, "id" desc limit 1')
            ->not->toContain('payload', 'select *')
            ->and($queries[1]['query'])->toContain('"custom_pdl_ledger"."record_key" = ?')
            ->not->toContain('order by')
            ->and($queries[1]['bindings'])->toBe([$message->getKey(), $message->message_id, $winningKey]);
    } finally {
        DB::purge('pdl_custom');
    }
})->with(['aaa-second', '0']);

test('global scopes soft deletes and scope-added projections apply correctly to both reads', function (): void {
    Schema::table('talkto_dead_letters', fn (Blueprint $table) => $table->softDeletes());
    config(['talkto.models.dead_letter' => PdlScopedDeadLetter::class]);
    $message = pdlQueryMessage();
    $visible = pdlQueryInsert(['talkto_message_id' => $message->getKey(), 'message_id' => 'visible', 'direction' => 'outgoing', 'payload' => '{"visible":true}']);
    pdlQueryInsert(['message_id' => $message->message_id, 'direction' => 'outgoing', 'created_at' => '2026-10-28 23:00:00', 'deleted_at' => '2026-10-28 23:01:00']);

    $expected = PdlScopedDeadLetter::query()->where('talkto_message_id', $message->getKey())->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
    [$actual, $queries] = pdlQueryCapture(fn () => app(TalktoPanelMessageQuery::class)->deadLetterFor($message));

    expect($actual->getKey())->toBe($visible)
        ->and($actual->getRawOriginal())->toBe($expected->getRawOriginal())
        ->and($actual->scoped_payload)->toBe('{"visible":true}')
        ->and($queries)->toHaveCount(2)
        ->and($queries[0]['query'])->toStartWith('select "talkto_dead_letters"."id" from "talkto_dead_letters"')
        ->not->toContain('payload', 'scoped_payload', 'select *')
        ->and($queries[1]['query'])->toContain('"talkto_dead_letters".*', '"payload" as "scoped_payload"')
        ->not->toContain('order by');

    foreach ($queries as $query) {
        expect($query['query'])->toContain('"talkto_dead_letters"."deleted_at" is null', '"direction" = ?')
            ->and($query['bindings'])->toContain('outgoing');
    }
});

test('a filtering model scope excludes a newer matching row', function (): void {
    Schema::table('talkto_dead_letters', fn (Blueprint $table) => $table->softDeletes());
    config(['talkto.models.dead_letter' => PdlScopedDeadLetter::class]);
    $message = pdlQueryMessage();
    $visible = pdlQueryInsert(['talkto_message_id' => $message->getKey(), 'message_id' => 'visible', 'direction' => 'outgoing']);
    pdlQueryInsert(['message_id' => $message->message_id, 'direction' => 'incoming', 'created_at' => '2026-10-28 23:59:59']);

    expect(app(TalktoPanelMessageQuery::class)->deadLetterFor($message)->getKey())->toBe($visible);
});

test('row removal or unlinking between read steps returns null without another fallback query', function (string $change): void {
    $message = pdlQueryMessage();
    pdlQueryInsert(['talkto_message_id' => $message->getKey(), 'message_id' => 'older', 'created_at' => '2026-10-28 11:00:00']);
    $selected = pdlQueryInsert(['message_id' => $message->message_id, 'created_at' => '2026-10-28 12:00:00']);
    $changed = false;
    DB::listen(function (QueryExecuted $query) use (&$changed, $selected, $change): void {
        if ($changed || ! str_starts_with($query->sql, 'select "talkto_dead_letters"."id" from')) {
            return;
        }

        $changed = true;

        if ($change === 'delete') {
            DB::table('talkto_dead_letters')->where('id', $selected)->delete();
        } else {
            DB::table('talkto_dead_letters')->where('id', $selected)->update(['talkto_message_id' => null, 'message_id' => 'now-unrelated']);
        }
    });

    [$actual, $queries] = pdlQueryCapture(fn () => app(TalktoPanelMessageQuery::class)->deadLetterFor($message));

    expect($changed)->toBeTrue()->and($actual)->toBeNull();
    pdlQueryAssertTwoSteps($queries, $message, $selected);
})->with(['delete', 'unlink']);

test('attempt and event detail histories preserve full attributes descending order and no truncation', function (): void {
    $message = pdlQueryMessage();
    $large = str_repeat('m', 256 * 1024);

    foreach (range(1, 7) as $index) {
        $identifiers = $index % 2 === 0
            ? ['talkto_message_id' => $message->getKey(), 'message_id' => 'db-link-'.$index]
            : ['talkto_message_id' => null, 'message_id' => $message->message_id];
        DB::table('talkto_attempts')->insert([...$identifiers, 'stage' => 'transport', 'status' => 'failed', 'attempt_no' => $index, 'request_excerpt' => $large, 'response_excerpt' => $large, 'meta' => json_encode(['body' => $large], JSON_THROW_ON_ERROR), 'created_at' => '2026-10-28 12:00:00']);
        DB::table('talkto_events')->insert([...$identifiers, 'event_type' => 'history-'.$index, 'service_name' => 'testing', 'meta' => json_encode(['body' => $large], JSON_THROW_ON_ERROR), 'created_at' => '2026-10-28 12:00:00']);
    }
    DB::table('talkto_events')->insert(['message_id' => 'unrelated', 'service_name' => 'testing', 'event_type' => 'unrelated']);
    $service = app(TalktoPanelMessageQuery::class);
    $attempts = $service->attemptsFor($message);
    $events = $service->eventsFor($message);

    expect($attempts)->toHaveCount(7)->and($events)->toHaveCount(7)
        ->and($attempts->pluck('attempt_no')->all())->toBe([7, 6, 5, 4, 3, 2, 1])
        ->and($events->pluck('event_type')->all())->toBe(['history-7', 'history-6', 'history-5', 'history-4', 'history-3', 'history-2', 'history-1']);

    foreach ($attempts as $attempt) {
        expect($attempt)->toBeInstanceOf(TalktoAttempt::class)
            ->and($attempt->request_excerpt)->toBe($large)
            ->and($attempt->response_excerpt)->toBe($large)
            ->and($attempt->meta)->toBe(['body' => $large]);
    }

    foreach ($events as $event) {
        expect($event)->toBeInstanceOf(TalktoEvent::class)->and($event->meta)->toBe(['body' => $large]);
    }
});

test('attempt and event details retain empty collection behavior', function (): void {
    $message = pdlQueryMessage();
    $service = app(TalktoPanelMessageQuery::class);

    expect($service->attemptsFor($message)->all())->toBe([])
        ->and($service->eventsFor($message)->all())->toBe([]);
});

test('large trace payloads retain hidden key names visible redaction and history limits', function (): void {
    $message = pdlQueryMessage();
    $large = str_repeat('v', 256 * 1024);
    $message->forceFill(['payload' => ['large_body' => $large, 'token' => 'private-value']])->save();
    pdlQueryInsert(['message_id' => $message->message_id, 'payload' => json_encode(['large_body' => $large, 'token' => 'private-value'], JSON_THROW_ON_ERROR), 'headers' => '{"Authorization":"private-value"}']);

    foreach (range(1, 7) as $index) {
        DB::table('talkto_events')->insert(['message_id' => $message->message_id, 'service_name' => 'testing', 'event_type' => 'trace-'.$index, 'meta' => '{}', 'created_at' => '2026-10-28 12:00:00']);
    }
    $reporter = app(TalktoTraceReporter::class);
    $hidden = $reporter->traceByMessageId($message->message_id, 4, false)->toArray();
    $visible = $reporter->traceByMessageId($message->message_id, 4, true)->toArray();

    expect(array_keys($visible))->toBe(array_keys($hidden))
        ->and($hidden['anchor_message']['payload'])->toBe(['redacted' => true, 'keys' => ['large_body', 'token']])
        ->and($hidden['dead_letters'][0]['payload'])->toBe(['redacted' => true, 'keys' => ['large_body', 'token']])
        ->and($visible['anchor_message']['payload'])->toBe(['large_body' => $large, 'token' => '[redacted]'])
        ->and($visible['dead_letters'][0]['payload'])->toBe(['large_body' => $large, 'token' => '[redacted]'])
        ->and($hidden['dead_letters'][0]['headers'])->toBe('[redacted]')
        ->and($visible['dead_letters'][0]['headers'])->toBe('[redacted]')
        ->and($hidden['events'])->toHaveCount(4)
        ->and($visible['events'])->toBe($hidden['events'])
        ->and($hidden['timeline'])->toHaveCount(4)
        ->and($hidden['truncated'])->toBeTrue()
        ->and($visible['truncated'])->toBeTrue();
});

test('callback inspector dead letter identity and attributes preserve both identifiers', function (string $match): void {
    $message = pdlQueryMessage();
    $attributes = match ($match) {
        'database id' => ['talkto_message_id' => $message->id, 'message_id' => 'different-message'],
        'message id' => ['talkto_message_id' => null, 'message_id' => $message->message_id],
        'both' => ['talkto_message_id' => $message->id, 'message_id' => $message->message_id],
    };
    $id = pdlQueryInsert($attributes);
    pdlQueryInsert(['message_id' => 'unrelated']);
    $expected = TalktoDeadLetter::query()
        ->where(fn (Builder $query) => $query->where('talkto_message_id', $message->id)->orWhere('message_id', $message->message_id))
        ->orderByDesc('id')->firstOrFail();

    [$actual, $queries] = pdlQueryCapture(fn () => pdlCallbackDeadLetter($message));

    expect($actual->getRawOriginal())->toBe($expected->getRawOriginal());
    pdlCallbackAssertTwoSteps($queries, $message, $id);
})->with(['database id', 'message id', 'both']);

test('callback inspector retains id only ordering independently of created timestamps', function (?string $firstCreated, ?string $secondCreated): void {
    $message = pdlQueryMessage();
    pdlQueryInsert(['talkto_message_id' => $message->id, 'message_id' => 'different', 'created_at' => $firstCreated]);
    $highestId = pdlQueryInsert(['message_id' => $message->message_id, 'created_at' => $secondCreated]);

    [$actual, $queries] = pdlQueryCapture(fn () => pdlCallbackDeadLetter($message));

    expect($actual->getKey())->toBe($highestId);
    pdlCallbackAssertTwoSteps($queries, $message, $highestId);
})->with([
    'later id wins despite older created_at' => ['2026-10-28 23:59:59', '2026-10-28 01:00:00'],
    'null timestamps still order by id' => [null, null],
]);

test('callback inspector empty or unmatched dead letter lookup fetches only one scalar query', function (bool $unrelated): void {
    $message = pdlQueryMessage();

    if ($unrelated) {
        pdlQueryInsert(['message_id' => 'unrelated']);
    }

    [$actual, $queries] = pdlQueryCapture(fn () => pdlCallbackDeadLetter($message));

    expect($actual)->toBeNull()->and($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toBe('select "talkto_dead_letters"."id" from "talkto_dead_letters" where ("talkto_message_id" = ? or "message_id" = ?) order by "id" desc limit 1')
        ->and($queries[0]['bindings'])->toBe([$message->id, $message->message_id]);
})->with([false, true]);

test('callback inspector preserves full payload dependent model accessors and public dead letter state', function (): void {
    config(['talkto.models.dead_letter' => PdlPayloadStatusDeadLetter::class]);
    $message = pdlQueryMessage();
    $message->forceFill(['command' => 'talkto.result'])->save();
    $large = str_repeat('p', 256 * 1024);
    $id = pdlQueryInsert([
        'message_id' => $message->message_id,
        'payload' => json_encode(['body' => $large, 'reported_status' => 'custom-open', 'reported_failure' => 'custom-final'], JSON_THROW_ON_ERROR),
        'failure_reason' => $large,
    ]);
    PdlPayloadStatusDeadLetter::$hydrated = [];
    PdlPayloadStatusDeadLetter::$retrieved = 0;

    [$actual, $queries] = pdlQueryCapture(fn () => pdlCallbackDeadLetter($message));

    pdlCallbackAssertTwoSteps($queries, $message, $id);
    expect($actual)->toBeInstanceOf(PdlPayloadStatusDeadLetter::class)
        ->and($actual->status)->toBe('custom-open')
        ->and($actual->failed_status)->toBe('custom-final')
        ->and($actual->payload['body'])->toBe($large)
        ->and($actual->failure_reason)->toBe($large)
        ->and(PdlPayloadStatusDeadLetter::$hydrated)->toHaveCount(1)
        ->and(PdlPayloadStatusDeadLetter::$retrieved)->toBe(1);

    // inspect() historically calls the private lookup for state and summary.
    [$summary, $publicQueries] = pdlQueryCapture(fn () => app(TalktoCallbackStatusInspector::class)->inspect($message));

    expect($summary['applicable'])->toBeTrue()
        ->and($summary['context'])->toBe('outgoing_callback')
        ->and($summary['state'])->toBe('callback_dead_lettered')
        ->and($summary['dead_letter'])->toBe(['exists' => true, 'id' => $id, 'status' => 'custom-open', 'failed_status' => 'custom-final'])
        ->and($summary['attempts'])->toBe(['count' => 0, 'last_status' => null, 'last_http_status' => null, 'last_error' => null])
        ->and($publicQueries)->toHaveCount(4);
});

test('callback inspector scopes preserve visibility soft deletes and final projection while sorting only raw key', function (): void {
    Schema::table('talkto_dead_letters', fn (Blueprint $table) => $table->softDeletes());
    config(['talkto.models.dead_letter' => PdlScopedDeadLetter::class]);
    $message = pdlQueryMessage();
    $id = pdlQueryInsert(['talkto_message_id' => $message->id, 'message_id' => 'visible', 'direction' => 'outgoing', 'payload' => '{"kept":true}']);
    pdlQueryInsert(['message_id' => $message->message_id, 'direction' => 'outgoing', 'deleted_at' => '2026-10-28 13:00:00']);
    $expected = PdlScopedDeadLetter::query()
        ->where(fn (Builder $query) => $query->where('talkto_message_id', $message->id)->orWhere('message_id', $message->message_id))
        ->orderByDesc('id')->firstOrFail();

    [$actual, $queries] = pdlQueryCapture(fn () => pdlCallbackDeadLetter($message));

    expect($actual->getKey())->toBe($id)
        ->and($actual->getRawOriginal())->toBe($expected->getRawOriginal())
        ->and($actual->scoped_payload)->toBe('{"kept":true}')
        ->and($queries)->toHaveCount(2)
        ->and($queries[0]['query'])->toStartWith('select "talkto_dead_letters"."id" from "talkto_dead_letters"')
        ->not->toContain('payload', 'scoped_payload', 'select *', 'created_at')
        ->and($queries[1]['query'])->toContain('"talkto_dead_letters".*', '"payload" as "scoped_payload"')
        ->not->toContain('order by');

    foreach ($queries as $query) {
        expect($query['query'])->toContain('"talkto_dead_letters"."deleted_at" is null', '"direction" = ?');
    }
});

test('callback inspector supports raw string primary key and custom database table', function (): void {
    $message = pdlQueryMessage();
    config([
        'database.connections.pdl_callback_custom' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'talkto.database.connection' => 'pdl_callback_custom',
        'talkto.database.tables.dead_letters' => 'callback_custom_ledger',
        'talkto.models.dead_letter' => PdlCustomKeyDeadLetter::class,
    ]);
    DB::purge('pdl_callback_custom');
    Schema::connection('pdl_callback_custom')->create('callback_custom_ledger', function (Blueprint $table): void {
        $table->string('record_key')->primary();
        $table->unsignedBigInteger('id');
        $table->unsignedBigInteger('talkto_message_id')->nullable()->unique();
        $table->string('message_id')->nullable()->unique();
        $table->json('payload')->nullable();
        $table->timestamps();
    });
    DB::connection('pdl_callback_custom')->table('callback_custom_ledger')->insert([
        ['record_key' => 'zzz', 'id' => 10, 'talkto_message_id' => $message->id, 'message_id' => 'other', 'payload' => '{"old":1}', 'created_at' => '2026-10-28 23:59:59'],
        ['record_key' => '0', 'id' => 20, 'talkto_message_id' => null, 'message_id' => $message->message_id, 'payload' => '{"chosen":2}', 'created_at' => '2026-10-28 01:00:00'],
    ]);

    try {
        [$actual, $queries] = pdlQueryCapture(fn () => pdlCallbackDeadLetter($message), 'pdl_callback_custom', 'callback_custom_ledger');

        expect($actual)->toBeInstanceOf(PdlCustomKeyDeadLetter::class)
            ->and($actual->getConnection()->getName())->toBe('pdl_callback_custom')
            ->and($actual->getRawOriginal('record_key'))->toBe('0')
            ->and($actual->getKey())->toBe('accessor:0')
            ->and($actual->id)->toBe(20)
            ->and($actual->payload)->toBe(['chosen' => 2])
            ->and($queries)->toHaveCount(2)
            ->and($queries[0]['query'])->toStartWith('select "callback_custom_ledger"."record_key" from "callback_custom_ledger"')
            ->toContain('order by "id" desc limit 1')->not->toContain('created_at', 'payload', 'select *')
            ->and($queries[1]['bindings'])->toBe([$message->id, $message->message_id, '0']);
    } finally {
        DB::purge('pdl_callback_custom');
    }
});

test('callback inspector safely returns null when the selected record disappears between reads', function (): void {
    $message = pdlQueryMessage();
    pdlQueryInsert(['talkto_message_id' => $message->id, 'message_id' => 'older']);
    $id = pdlQueryInsert(['message_id' => $message->message_id]);
    $removed = false;
    DB::listen(function (QueryExecuted $query) use (&$removed, $id): void {
        if (! $removed && str_starts_with($query->sql, 'select "talkto_dead_letters"."id" from')) {
            $removed = true;
            DB::table('talkto_dead_letters')->where('id', $id)->delete();
        }
    });

    [$actual, $queries] = pdlQueryCapture(fn () => pdlCallbackDeadLetter($message));

    expect($removed)->toBeTrue()->and($actual)->toBeNull();
    pdlCallbackAssertTwoSteps($queries, $message, $id);
});

function pdlQueryMessage(): TalktoMessage
{
    return TalktoMessage::query()->create([
        'message_id' => (string) Str::uuid(),
        'direction' => 'outgoing',
        'source_service' => 'testing',
        'target_service' => 'peer',
        'command' => 'domain.sync',
        'overall_status' => 'failed_final',
        'payload' => ['id' => 1],
    ]);
}

function pdlQueryInsert(array $attributes = []): int
{
    return DB::table('talkto_dead_letters')->insertGetId(array_merge([
        'talkto_message_id' => null,
        'message_id' => null,
        'status' => 'open',
        'payload' => '{"id":1}',
        'headers' => '{"content-type":"application/json"}',
        'failed_status' => 'failed_final',
        'failure_reason' => 'synthetic failure',
        'created_at' => '2026-10-28 12:00:00',
        'updated_at' => '2026-10-28 12:01:00',
    ], $attributes));
}

function pdlQueryCapture(Closure $callback, ?string $connectionName = null, string $table = 'talkto_dead_letters'): array
{
    $connection = DB::connection($connectionName);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $result = $callback();
        $queries = array_values(array_filter($connection->getQueryLog(), fn (array $query): bool => str_starts_with($query['query'], 'select ')
            && str_contains($query['query'], 'from "'.$table.'"')));

        return [$result, $queries];
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
}

function pdlQueryAssertTwoSteps(array $queries, TalktoMessage $message, int $id): void
{
    expect($queries)->toHaveCount(2)
        ->and($queries[0]['query'])->toBe('select "talkto_dead_letters"."id" from "talkto_dead_letters" where ("talkto_message_id" = ? or "message_id" = ?) order by "created_at" desc, "id" desc limit 1')
        ->and($queries[0]['bindings'])->toBe([$message->getKey(), $message->message_id])
        ->and($queries[1]['query'])->toBe('select * from "talkto_dead_letters" where ("talkto_message_id" = ? or "message_id" = ?) and "talkto_dead_letters"."id" = ? limit 1')
        ->and($queries[1]['bindings'])->toBe([$message->getKey(), $message->message_id, $id]);
}

function pdlCallbackDeadLetter(TalktoMessage $message): ?TalktoDeadLetter
{
    return (new ReflectionMethod(TalktoCallbackStatusInspector::class, 'deadLetterFor'))
        ->invoke(app(TalktoCallbackStatusInspector::class), $message);
}

function pdlCallbackAssertTwoSteps(array $queries, TalktoMessage $message, int $id): void
{
    expect($queries)->toHaveCount(2)
        ->and($queries[0]['query'])->toBe('select "talkto_dead_letters"."id" from "talkto_dead_letters" where ("talkto_message_id" = ? or "message_id" = ?) order by "id" desc limit 1')
        ->and($queries[0]['bindings'])->toBe([$message->id, $message->message_id])
        ->and($queries[1]['query'])->toBe('select * from "talkto_dead_letters" where ("talkto_message_id" = ? or "message_id" = ?) and "talkto_dead_letters"."id" = ? limit 1')
        ->and($queries[1]['bindings'])->toBe([$message->id, $message->message_id, $id]);
}

class PdlCountingDeadLetter extends TalktoDeadLetter
{
    public static array $hydrated = [];

    public static int $retrieved = 0;

    protected static function booted(): void
    {
        static::retrieved(function (): void {
            static::$retrieved++;
        });
    }

    public function newFromBuilder($attributes = [], $connection = null)
    {
        static::$hydrated[] = array_keys((array) $attributes);

        return parent::newFromBuilder($attributes, $connection);
    }
}

class PdlCustomKeyDeadLetter extends TalktoDeadLetter
{
    protected $primaryKey = 'record_key';

    protected $keyType = 'string';

    public $incrementing = false;

    public function getRecordKeyAttribute(mixed $value): string
    {
        return 'accessor:'.$value;
    }
}

class PdlPayloadStatusDeadLetter extends PdlCountingDeadLetter
{
    public function getStatusAttribute(mixed $value): string
    {
        return $this->payload['reported_status'] ?? 'payload-missing';
    }

    public function getFailedStatusAttribute(mixed $value): string
    {
        return $this->payload['reported_failure'] ?? 'payload-missing';
    }
}

class PdlScopedDeadLetter extends TalktoDeadLetter
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope('visibility', function (Builder $query): void {
            $query->where('direction', 'outgoing')
                ->addSelect($query->getModel()->getTable().'.*')
                ->addSelect('payload as scoped_payload');
        });
    }
}
