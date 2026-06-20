<?php

use Illuminate\Database\Migrations\Migration;
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

test('talkto migrations create default tables on default connection', function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0)
        ->and(Schema::hasTable('talkto_messages'))->toBeTrue()
        ->and(Schema::hasTable('talkto_attempts'))->toBeTrue()
        ->and(Schema::hasTable('talkto_events'))->toBeTrue()
        ->and(Schema::hasTable('talkto_dead_letters'))->toBeTrue()
        ->and(Schema::hasTable('talkto_nonces'))->toBeTrue();
});

test('talkto migrations create custom tables on configured connection', function (): void {
    talktoStorageUseTestingConnection();
    talktoStorageUseCustomTables();

    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0)
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_messages'))->toBeTrue()
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_attempts'))->toBeTrue()
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_events'))->toBeTrue()
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_dead_letters'))->toBeTrue()
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_nonces'))->toBeTrue()
        ->and(Schema::hasTable('custom_talkto_messages'))->toBeFalse()
        ->and(Schema::hasTable('custom_talkto_attempts'))->toBeFalse()
        ->and(Schema::hasTable('custom_talkto_events'))->toBeFalse()
        ->and(Schema::hasTable('custom_talkto_dead_letters'))->toBeFalse()
        ->and(Schema::hasTable('custom_talkto_nonces'))->toBeFalse();
});

test('talkto migrations and models work together on configured connection and tables', function (): void {
    talktoStorageUseTestingConnection();
    talktoStorageUseCustomTables();

    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    $message = TalktoMessage::query()->create([
        'message_id' => 'storage-migration-custom',
        'direction' => 'incoming',
        'source_service' => 'source',
        'target_service' => 'testing',
        'command' => 'domain.command',
        'payload' => ['id' => 'storage-migration-custom'],
        'payload_hash' => 'hash',
        'schema_version' => 1,
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'queued',
        'received_at' => now(),
    ]);

    TalktoAttempt::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'stage' => 'destination_processor',
        'attempt_no' => 1,
        'status' => 'started',
    ]);

    TalktoEvent::query()->create([
        'talkto_message_id' => $message->id,
        'message_id' => $message->message_id,
        'service_name' => 'testing',
        'event_type' => 'message_received',
        'new_status' => 'queued',
        'meta' => ['source' => 'source'],
    ]);

    TalktoDeadLetter::query()->create([
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

    TalktoNonce::query()->create([
        'nonce_hash' => str_repeat('a', 64),
        'source_service' => 'source',
        'target_service' => 'testing',
        'message_id' => $message->message_id,
        'signature_version' => 'v2',
        'signed_timestamp' => now()->toIso8601String(),
        'used_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);

    expect(DB::connection('talkto_testing')->table('custom_talkto_messages')->count())->toBe(1)
        ->and(DB::connection('talkto_testing')->table('custom_talkto_attempts')->count())->toBe(1)
        ->and(DB::connection('talkto_testing')->table('custom_talkto_events')->count())->toBe(1)
        ->and(DB::connection('talkto_testing')->table('custom_talkto_dead_letters')->count())->toBe(1)
        ->and(DB::connection('talkto_testing')->table('custom_talkto_nonces')->count())->toBe(1);
});

test('nonces migration includes hashed nonce ledger columns and indexes', function (): void {
    $migration = file_get_contents(__DIR__.'/../../database/migrations/2026_06_20_000001_create_talkto_nonces_table.php') ?: '';

    expect($migration)->toContain("\$table->string('nonce_hash', 64)->unique();")
        ->and($migration)->toContain("\$table->string('source_service', 80)->index();")
        ->and($migration)->toContain("\$table->string('target_service', 80)->index();")
        ->and($migration)->toContain("\$table->string('message_id', 100)->nullable()->index();")
        ->and($migration)->not->toContain('payload')
        ->and($migration)->not->toContain('response');
});

test('messages migration includes idempotency fingerprint and query indexes', function (): void {
    $migration = file_get_contents(__DIR__.'/../../database/migrations/2026_06_13_000001_create_talkto_messages_table.php') ?: '';

    expect($migration)->toContain("\$table->string('idempotency_fingerprint', 64)->nullable()->unique();")
        ->and($migration)->toContain("'talkto_messages_retry_lookup_idx'")
        ->and($migration)->toContain("'talkto_messages_target_status_idx'")
        ->and($migration)->toContain("'talkto_messages_service_command_time_idx'")
        ->and($migration)->toContain("'talkto_messages_correlation_time_idx'");
});

test('max attempts default is consistent between config and messages migration', function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    $message = TalktoMessage::query()->create([
        'message_id' => 'storage-default-max-attempts',
        'direction' => 'incoming',
        'source_service' => 'source',
        'target_service' => 'testing',
        'command' => 'domain.command',
        'payload' => ['id' => 'storage-default-max-attempts'],
        'payload_hash' => 'hash',
        'schema_version' => 1,
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'queued',
        'received_at' => now(),
    ]);

    expect(config('talkto.retry.max_attempts'))->toBe(5)
        ->and((int) $message->fresh()->max_attempts)->toBe(5);
});

test('talkto migration down methods drop custom tables from configured connection', function (): void {
    talktoStorageUseTestingConnection();
    talktoStorageUseCustomTables();

    $migrations = talktoStorageMigrationInstances();

    foreach ($migrations as $migration) {
        $migration->up();
    }

    expect(Schema::connection('talkto_testing')->hasTable('custom_talkto_messages'))->toBeTrue()
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_attempts'))->toBeTrue()
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_events'))->toBeTrue()
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_dead_letters'))->toBeTrue()
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_nonces'))->toBeTrue();

    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
    }

    expect(Schema::connection('talkto_testing')->hasTable('custom_talkto_messages'))->toBeFalse()
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_attempts'))->toBeFalse()
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_events'))->toBeFalse()
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_dead_letters'))->toBeFalse()
        ->and(Schema::connection('talkto_testing')->hasTable('custom_talkto_nonces'))->toBeFalse();
});

test('dead letter migration keeps legacy table fallback and database table priority', function (): void {
    config([
        'talkto.database.tables.dead_letters' => null,
        'talkto.dead_letter.table' => 'legacy_dead_letters',
    ]);

    $migration = talktoStorageDeadLetterMigration();
    $migration->up();

    expect(Schema::hasTable('legacy_dead_letters'))->toBeTrue();

    $migration->down();

    expect(Schema::hasTable('legacy_dead_letters'))->toBeFalse();

    config([
        'talkto.database.tables.dead_letters' => 'custom_dead_letters_priority',
        'talkto.dead_letter.table' => 'legacy_dead_letters',
    ]);

    $migration = talktoStorageDeadLetterMigration();
    $migration->up();

    expect(Schema::hasTable('custom_dead_letters_priority'))->toBeTrue()
        ->and(Schema::hasTable('legacy_dead_letters'))->toBeFalse();

    $migration->down();
});

function talktoStorageUseTestingConnection(): void
{
    config([
        'database.connections.talkto_testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'talkto.database.connection' => 'talkto_testing',
    ]);

    DB::purge('talkto_testing');
}

function talktoStorageUseCustomTables(): void
{
    config([
        'talkto.database.tables.messages' => 'custom_talkto_messages',
        'talkto.database.tables.attempts' => 'custom_talkto_attempts',
        'talkto.database.tables.events' => 'custom_talkto_events',
        'talkto.database.tables.dead_letters' => 'custom_talkto_dead_letters',
        'talkto.database.tables.nonces' => 'custom_talkto_nonces',
    ]);
}

/**
 * @return array<int, Migration>
 */
function talktoStorageMigrationInstances(): array
{
    return [
        include __DIR__.'/../../database/migrations/2026_06_13_000001_create_talkto_messages_table.php',
        include __DIR__.'/../../database/migrations/2026_06_13_000002_create_talkto_attempts_table.php',
        include __DIR__.'/../../database/migrations/2026_06_13_000003_create_talkto_events_table.php',
        include __DIR__.'/../../database/migrations/2026_06_19_000002_create_talkto_dead_letters_table.php',
        include __DIR__.'/../../database/migrations/2026_06_20_000001_create_talkto_nonces_table.php',
    ];
}

function talktoStorageDeadLetterMigration(): Migration
{
    return include __DIR__.'/../../database/migrations/2026_06_19_000002_create_talkto_dead_letters_table.php';
}
