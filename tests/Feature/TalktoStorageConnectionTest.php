<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoNonce;

beforeEach(function (): void {
    config([
        'talkto.database.connection' => null,
        'talkto.database.tables.messages' => 'talkto_messages',
        'talkto.database.tables.attempts' => 'talkto_attempts',
        'talkto.database.tables.events' => 'talkto_events',
        'talkto.database.tables.dead_letters' => 'talkto_dead_letters',
        'talkto.database.tables.nonces' => 'talkto_nonces',
        'talkto.dead_letter.table' => 'talkto_dead_letters',
    ]);
});

test('talkto models use default connection when storage connection is null', function (): void {
    expect((new TalktoMessage)->getConnectionName())->toBeNull()
        ->and((new TalktoAttempt)->getConnectionName())->toBeNull()
        ->and((new TalktoEvent)->getConnectionName())->toBeNull()
        ->and((new TalktoDeadLetter)->getConnectionName())->toBeNull()
        ->and((new TalktoNonce)->getConnectionName())->toBeNull();
});

test('talkto models use configured storage connection', function (): void {
    config(['talkto.database.connection' => 'talkto_testing']);

    expect((new TalktoMessage)->getConnectionName())->toBe('talkto_testing')
        ->and((new TalktoAttempt)->getConnectionName())->toBe('talkto_testing')
        ->and((new TalktoEvent)->getConnectionName())->toBe('talkto_testing')
        ->and((new TalktoDeadLetter)->getConnectionName())->toBe('talkto_testing')
        ->and((new TalktoNonce)->getConnectionName())->toBe('talkto_testing');
});

test('talkto models use default table names', function (): void {
    expect((new TalktoMessage)->getTable())->toBe('talkto_messages')
        ->and((new TalktoAttempt)->getTable())->toBe('talkto_attempts')
        ->and((new TalktoEvent)->getTable())->toBe('talkto_events')
        ->and((new TalktoDeadLetter)->getTable())->toBe('talkto_dead_letters')
        ->and((new TalktoNonce)->getTable())->toBe('talkto_nonces');
});

test('talkto models use configured table names', function (): void {
    config([
        'talkto.database.tables.messages' => 'custom_talkto_messages',
        'talkto.database.tables.attempts' => 'custom_talkto_attempts',
        'talkto.database.tables.events' => 'custom_talkto_events',
        'talkto.database.tables.dead_letters' => 'custom_talkto_dead_letters',
        'talkto.database.tables.nonces' => 'custom_talkto_nonces',
    ]);

    expect((new TalktoMessage)->getTable())->toBe('custom_talkto_messages')
        ->and((new TalktoAttempt)->getTable())->toBe('custom_talkto_attempts')
        ->and((new TalktoEvent)->getTable())->toBe('custom_talkto_events')
        ->and((new TalktoDeadLetter)->getTable())->toBe('custom_talkto_dead_letters')
        ->and((new TalktoNonce)->getTable())->toBe('custom_talkto_nonces');
});

test('talkto message queries use configured connection and table', function (): void {
    config([
        'database.connections.talkto_testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
        'talkto.database.connection' => 'talkto_testing',
        'talkto.database.tables.messages' => 'custom_talkto_messages',
    ]);

    DB::purge('talkto_testing');

    Schema::connection('talkto_testing')->create('custom_talkto_messages', function (Blueprint $table): void {
        $table->id();
        $table->string('message_id')->unique();
        $table->string('direction');
        $table->string('source_service');
        $table->string('target_service');
        $table->string('command');
        $table->string('idempotency_fingerprint', 64)->nullable()->unique();
        $table->json('payload')->nullable();
        $table->string('payload_hash');
        $table->unsignedInteger('schema_version')->default(1);
        $table->string('overall_status')->nullable();
        $table->timestamps();
    });

    TalktoMessage::query()->create([
        'message_id' => 'storage-custom-connection',
        'direction' => 'incoming',
        'source_service' => 'source',
        'target_service' => 'testing',
        'command' => 'domain.command',
        'payload' => ['id' => 'storage-custom-connection'],
        'payload_hash' => 'hash',
        'schema_version' => 1,
        'overall_status' => 'queued',
    ]);

    expect(TalktoMessage::query()->count())->toBe(1)
        ->and(DB::connection('talkto_testing')->table('custom_talkto_messages')->count())->toBe(1);
});

test('dead letter table keeps legacy config fallback with database config priority', function (): void {
    config([
        'talkto.database.tables.dead_letters' => null,
        'talkto.dead_letter.table' => 'legacy_dead_letters',
    ]);

    expect((new TalktoDeadLetter)->getTable())->toBe('legacy_dead_letters');

    config(['talkto.database.tables.dead_letters' => 'custom_talkto_dead_letters']);

    expect((new TalktoDeadLetter)->getTable())->toBe('custom_talkto_dead_letters');
});

test('talkto model relations keep resolving on default storage tables', function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    $message = TalktoMessage::query()->create([
        'message_id' => 'storage-relations-message',
        'direction' => 'incoming',
        'source_service' => 'source',
        'target_service' => 'testing',
        'command' => 'domain.command',
        'payload' => ['id' => 'storage-relations-message'],
        'payload_hash' => 'hash',
        'schema_version' => 1,
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'queued',
        'received_at' => now(),
    ]);

    $attempt = TalktoAttempt::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'stage' => 'destination_processor',
        'attempt_no' => 1,
        'status' => 'started',
    ]);

    $event = TalktoEvent::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'service_name' => 'testing',
        'event_type' => 'message_received',
        'new_status' => 'queued',
        'meta' => ['source' => 'source'],
    ]);

    $deadLetter = TalktoDeadLetter::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'direction' => $message->direction,
        'source' => $message->source_service,
        'target' => $message->target_service,
        'command' => $message->command,
        'payload' => $message->payload,
        'failed_status' => 'failed_final',
        'status' => 'open',
    ]);

    expect($message->attempts()->first()?->is($attempt))->toBeTrue()
        ->and($message->events()->first()?->is($event))->toBeTrue()
        ->and($attempt->message?->is($message))->toBeTrue()
        ->and($event->message?->is($message))->toBeTrue()
        ->and($deadLetter->message?->is($message))->toBeTrue();
});

test('panel message query remains model based for storage connection compatibility', function (): void {
    $query = file_get_contents(__DIR__.'/../../src/Services/Panel/TalktoPanelMessageQuery.php') ?: '';

    expect($query)->toContain('$this->messageModelClass()::query()')
        ->and($query)->not->toContain("DB::table('talkto_messages')")
        ->and($query)->not->toContain('DB::table("talkto_messages")');
});
