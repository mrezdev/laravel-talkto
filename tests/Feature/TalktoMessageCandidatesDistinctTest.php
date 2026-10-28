<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoMessageCandidates;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    foreach ([3, 1, 2] as $id) {
        DB::table('talkto_messages')->insert([
            'id' => $id,
            'message_id' => 'distinct-candidate-'.$id,
            'direction' => 'outgoing',
            'source_service' => 'testing',
            'target_service' => 'peer',
            'command' => 'domain.sync',
            'overall_status' => 'failed_retryable',
            'payload' => json_encode(['body' => str_repeat('x', 256 * 1024)], JSON_THROW_ON_ERROR),
            'next_retry_at' => '2026-10-28 10:00:0'.$id,
            'locked_at' => '2026-10-28 10:00:0'.$id,
            'created_at' => '2026-10-28 10:00:0'.$id,
            'updated_at' => '2026-10-28 10:00:0'.$id,
            'retry_count' => $id,
        ]);
    }
});

test('default candidate projection stays key only with its original filters order and limit', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $candidates = TalktoMessageCandidates::select(TalktoMessage::query()
            ->where('direction', 'outgoing')->orderBy('next_retry_at')->limit(2));
        $queries = DB::getQueryLog();

        expect($queries)->toHaveCount(1)
            ->and($queries[0]['query'])->toBe('select "talkto_messages"."id" from "talkto_messages" where "direction" = ? order by "next_retry_at" asc limit 2')
            ->and($queries[0]['bindings'])->toBe(['outgoing'])
            ->and($candidates->count())->toBe(2)
            ->and(collect($candidates)->pluck('id')->all())->toBe([1, 2]);
    } finally {
        DB::disableQueryLog();
    }
});

test('distinct candidates retain scalar ordering columns without hydrating the selector', function (string $column, bool $qualified): void {
    DistinctCandidateMessage::$hydrations = 0;
    config(['talkto.models.message' => DistinctCandidateMessage::class]);
    $ordering = $qualified ? 'talkto_messages.'.$column : $column;
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $candidates = TalktoMessageCandidates::select(DistinctCandidateMessage::query()
            ->where('source_service', 'testing')->orderBy($ordering)->limit(2));
        $selector = DB::getQueryLog()[0];
        $projection = explode(' from ', $selector['query'])[0];

        expect(DistinctCandidateMessage::$hydrations)->toBe(0)
            ->and($candidates->count())->toBe(2)
            ->and($projection)->toContain('select distinct "talkto_messages"."id"')
            ->and($projection)->toContain('"talkto_messages"."'.$column.'"')
            ->and($projection)->not->toContain('*', 'payload', 'last_response', 'last_error')
            ->and($selector['query'])->toContain('where "source_service" = ?', 'order by "'.str_replace('.', '"."', $ordering).'" asc limit 2')
            ->and($selector['bindings'])->toBe(['testing']);

        $messages = iterator_to_array($candidates);
        expect(array_map(fn (TalktoMessage $message): int => $message->id, $messages))->toBe([1, 2])
            ->and(DistinctCandidateMessage::$hydrations)->toBe(2)
            ->and(strlen($messages[0]->payload['body']))->toBe(256 * 1024);

        foreach (array_slice(DB::getQueryLog(), 1) as $query) {
            expect($query['query'])->not->toContain('order by');
        }
    } finally {
        DB::disableQueryLog();
    }
})->with(['id', 'next_retry_at', 'locked_at', 'created_at', 'updated_at', 'retry_count'])->with([false, true]);

test('distinct duplicate base rows stay unique when safe ordering fields are projected', function (): void {
    DB::statement('create table distinct_tags (message_key integer, label varchar(30))');
    DB::table('distinct_tags')->insert([
        ['message_key' => 1, 'label' => 'first'],
        ['message_key' => 1, 'label' => 'second'],
        ['message_key' => 2, 'label' => 'third'],
    ]);

    $query = TalktoMessage::query()->join('distinct_tags', 'distinct_tags.message_key', '=', 'talkto_messages.id')
        ->distinct()->orderBy('talkto_messages.next_retry_at')->limit(2);
    $candidates = TalktoMessageCandidates::select($query);

    expect($candidates->count())->toBe(2)
        ->and(collect($candidates)->pluck('id')->all())->toBe([1, 2]);
});

test('distinct selector never broadens to heavy columns for unsupported ordering', function (): void {
    // SQLite permits this shape; the real MySQL regression records its 3065
    // compatibility boundary. The helper must never hide it by selecting payload.
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        TalktoMessageCandidates::select(TalktoMessage::query()->distinct()->orderBy('payload')->limit(1));
        $projection = explode(' from ', DB::getQueryLog()[0]['query'])[0];

        expect($projection)->toBe('select distinct "talkto_messages"."id"');
    } finally {
        DB::disableQueryLog();
    }
});

class DistinctCandidateMessage extends TalktoMessage
{
    public static int $hydrations = 0;

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('distinct', fn (Builder $query) => $query->distinct());
    }

    public function newFromBuilder($attributes = [], $connection = null)
    {
        self::$hydrations++;

        return parent::newFromBuilder($attributes, $connection);
    }
}
