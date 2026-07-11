<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Exceptions\TalktoException;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;

beforeEach(function (): void {
    connectionAwareTransactionsConfigureConnections();
    connectionAwareTransactionsMigrate([null, 'talkto_testing', 'custom_model_talkto']);
    connectionAwareTransactionsResetObservedEvent();

    config([
        'talkto.service' => 'target-service',
        'talkto.database.connection' => null,
        'talkto.database.tables.messages' => 'talkto_messages',
        'talkto.database.tables.attempts' => 'talkto_attempts',
        'talkto.database.tables.events' => 'talkto_events',
        'talkto.database.tables.dead_letters' => 'talkto_dead_letters',
        'talkto.database.tables.nonces' => 'talkto_nonces',
        'talkto.dead_letter.table' => 'talkto_dead_letters',
        'talkto.models.message' => TalktoMessage::class,
        'talkto.models.event' => ConnectionAwareObservedTalktoEvent::class,
        'talkto.callbacks.enabled' => true,
        'talkto.callbacks.auto_dispatch' => true,
        'talkto.outgoing.peer' => [
            'url' => 'https://peer.test',
            'secret' => 'shared-secret',
        ],
        'talkto.outgoing.source-service' => [
            'url' => 'https://source.test',
            'secret' => 'shared-callback-secret',
            'callback_endpoint' => '/callbacks/talkto',
        ],
    ]);
});

test('outgoing message and event use the default connection transaction and rollback together', function (): void {
    $message = app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', ['amount' => 12.34], [
        'message_id' => 'connection-default-outgoing',
    ]);

    $created = connectionAwareTransactionsObservation('message_created');

    expect($message->message_id)->toBe('connection-default-outgoing')
        ->and($message->overall_status)->toBe('waiting_to_send')
        ->and(DB::table('talkto_messages')->where('message_id', 'connection-default-outgoing')->count())->toBe(1)
        ->and(DB::table('talkto_events')->where('message_id', 'connection-default-outgoing')->count())->toBe(1)
        ->and($created['model_connection'])->toBe('sqlite')
        ->and($created['model_transaction_level'])->toBeGreaterThan(0);

    ConnectionAwareObservedTalktoEvent::$failOnCreate = true;

    expect(fn () => app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', [], [
        'message_id' => 'connection-default-rollback',
    ]))->toThrow(RuntimeException::class, 'Observed event creation failed.');

    expect(DB::table('talkto_messages')->where('message_id', 'connection-default-rollback')->count())->toBe(0)
        ->and(DB::table('talkto_events')->where('message_id', 'connection-default-rollback')->count())->toBe(0);
});

test('outgoing message and event use the separate talkto connection transaction and rollback together', function (): void {
    config(['talkto.database.connection' => 'talkto_testing']);

    $message = app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', ['amount' => 56.78], [
        'message_id' => 'connection-separate-outgoing',
    ]);

    $created = connectionAwareTransactionsObservation('message_created');

    expect($message->getConnection()->getName())->toBe('talkto_testing')
        ->and(DB::connection('talkto_testing')->table('talkto_messages')->where('message_id', 'connection-separate-outgoing')->count())->toBe(1)
        ->and(DB::connection('talkto_testing')->table('talkto_events')->where('message_id', 'connection-separate-outgoing')->count())->toBe(1)
        ->and(DB::table('talkto_messages')->where('message_id', 'connection-separate-outgoing')->count())->toBe(0)
        ->and(DB::table('talkto_events')->where('message_id', 'connection-separate-outgoing')->count())->toBe(0)
        ->and($created['model_connection'])->toBe('talkto_testing')
        ->and($created['model_transaction_level'])->toBeGreaterThan(0)
        ->and($created['default_transaction_level'])->toBe(0);

    ConnectionAwareObservedTalktoEvent::$failOnCreate = true;

    expect(fn () => app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', [], [
        'message_id' => 'connection-separate-rollback',
    ]))->toThrow(RuntimeException::class, 'Observed event creation failed.');

    expect(DB::connection('talkto_testing')->table('talkto_messages')->where('message_id', 'connection-separate-rollback')->count())->toBe(0)
        ->and(DB::connection('talkto_testing')->table('talkto_events')->where('message_id', 'connection-separate-rollback')->count())->toBe(0)
        ->and(DB::table('talkto_messages')->where('message_id', 'connection-separate-rollback')->count())->toBe(0);
});

test('callback dispatch decision locks and records queued event on the talkto connection', function (): void {
    config(['talkto.database.connection' => 'talkto_testing']);
    Bus::fake();

    $incoming = connectionAwareTransactionsIncomingMessage('connection-callback-lock');
    $result = TalktoIncomingCommandResult::succeeded(['processed' => true]);

    $first = app(ResultCallbackSenderContract::class)->sendResult($incoming, $result);
    $second = app(ResultCallbackSenderContract::class)->sendResult($incoming->fresh(), $result);
    $queued = connectionAwareTransactionsObservation('result_callback_queued');

    expect($first['queued'])->toBeTrue()
        ->and($first['status'])->toBe('queued')
        ->and($second['queued'])->toBeFalse()
        ->and($second['duplicate'])->toBeTrue()
        ->and(DB::connection('talkto_testing')->table('talkto_events')->where('message_id', 'connection-callback-lock')->where('event_type', 'result_callback_queued')->count())->toBe(1)
        ->and(DB::table('talkto_events')->where('message_id', 'connection-callback-lock')->count())->toBe(0)
        ->and($queued['model_connection'])->toBe('talkto_testing')
        ->and($queued['model_transaction_level'])->toBeGreaterThan(0)
        ->and($queued['default_transaction_level'])->toBe(0);

    Bus::assertDispatched(SendTalktoMessage::class, 1);
});

test('custom message model connection is used instead of only the talkto config connection', function (): void {
    config([
        'talkto.database.connection' => 'talkto_testing',
        'talkto.models.message' => ConnectionAwareCustomTalktoMessage::class,
        'talkto.models.event' => ConnectionAwareCustomTalktoEvent::class,
    ]);

    $message = app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', [], [
        'message_id' => 'connection-custom-model',
    ]);

    $created = connectionAwareTransactionsObservation('message_created');

    expect($message)->toBeInstanceOf(ConnectionAwareCustomTalktoMessage::class)
        ->and($message->getConnection()->getName())->toBe('custom_model_talkto')
        ->and(DB::connection('custom_model_talkto')->table('talkto_messages')->where('message_id', 'connection-custom-model')->count())->toBe(1)
        ->and(DB::connection('custom_model_talkto')->table('talkto_events')->where('message_id', 'connection-custom-model')->count())->toBe(1)
        ->and(DB::connection('talkto_testing')->table('talkto_messages')->where('message_id', 'connection-custom-model')->count())->toBe(0)
        ->and(DB::table('talkto_messages')->where('message_id', 'connection-custom-model')->count())->toBe(0)
        ->and($created['model_connection'])->toBe('custom_model_talkto')
        ->and($created['model_transaction_level'])->toBeGreaterThan(0)
        ->and($created['talkto_transaction_level'])->toBe(0);
});

test('incompatible custom event connection fails before creating a partial message', function (): void {
    config([
        'talkto.database.connection' => 'talkto_testing',
        'talkto.models.event' => ConnectionAwareCustomTalktoEvent::class,
    ]);

    expect(fn () => app(TalktoOutgoingMessageFactory::class)->create('peer', 'domain.command', [], [
        'message_id' => 'connection-incompatible-event',
    ]))->toThrow(TalktoException::class, 'must use the same database connection');

    expect(DB::connection('talkto_testing')->table('talkto_messages')->where('message_id', 'connection-incompatible-event')->count())->toBe(0)
        ->and(DB::connection('custom_model_talkto')->table('talkto_events')->where('message_id', 'connection-incompatible-event')->count())->toBe(0);
});

function connectionAwareTransactionsConfigureConnections(): void
{
    config([
        'database.connections.talkto_testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'database.connections.custom_model_talkto' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge('talkto_testing');
    DB::purge('custom_model_talkto');
}

/**
 * @param  list<string|null>  $connections
 */
function connectionAwareTransactionsMigrate(array $connections): void
{
    $migrations = connectionAwareTransactionsMigrationInstances();

    foreach ($connections as $connection) {
        config(['talkto.database.connection' => $connection]);

        foreach ($migrations as $migration) {
            $migration->up();
        }
    }
}

/**
 * @return array<int, Migration>
 */
function connectionAwareTransactionsMigrationInstances(): array
{
    return [
        include __DIR__.'/../../database/migrations/2026_06_13_000001_create_talkto_messages_table.php',
        include __DIR__.'/../../database/migrations/2026_06_13_000002_create_talkto_attempts_table.php',
        include __DIR__.'/../../database/migrations/2026_06_13_000003_create_talkto_events_table.php',
        include __DIR__.'/../../database/migrations/2026_06_19_000002_create_talkto_dead_letters_table.php',
        include __DIR__.'/../../database/migrations/2026_06_20_000001_create_talkto_nonces_table.php',
    ];
}

function connectionAwareTransactionsResetObservedEvent(): void
{
    ConnectionAwareObservedTalktoEvent::$failOnCreate = false;
    ConnectionAwareObservedTalktoEvent::$observations = [];
}

function connectionAwareTransactionsObservation(string $eventType): array
{
    $matches = array_values(array_filter(
        ConnectionAwareObservedTalktoEvent::$observations,
        static fn (array $observation): bool => $observation['event_type'] === $eventType
    ));

    return $matches[count($matches) - 1] ?? [];
}

function connectionAwareTransactionsIncomingMessage(string $messageId): TalktoMessage
{
    return TalktoMessage::query()->create([
        'message_id' => $messageId,
        'correlation_id' => 'correlation-'.$messageId,
        'direction' => 'incoming',
        'source_service' => 'source-service',
        'target_service' => 'target-service',
        'command' => 'domain.command',
        'business_key' => 'business-'.$messageId,
        'payload' => ['id' => $messageId],
        'payload_hash' => 'hash-'.$messageId,
        'schema_version' => 1,
        'destination_receive_status' => 'received',
        'destination_action_status' => 'queued',
        'overall_status' => 'queued',
        'received_at' => now(),
    ]);
}

class ConnectionAwareObservedTalktoEvent extends TalktoEvent
{
    public static bool $failOnCreate = false;

    public static array $observations = [];

    protected static function booted(): void
    {
        static::creating(function (TalktoEvent $event): void {
            self::$observations[] = [
                'event_type' => (string) $event->event_type,
                'model_connection' => $event->getConnection()->getName(),
                'model_transaction_level' => $event->getConnection()->transactionLevel(),
                'default_transaction_level' => DB::connection()->transactionLevel(),
                'talkto_transaction_level' => DB::connection('talkto_testing')->transactionLevel(),
                'custom_transaction_level' => DB::connection('custom_model_talkto')->transactionLevel(),
            ];

            if (self::$failOnCreate) {
                throw new RuntimeException('Observed event creation failed.');
            }
        });
    }
}

class ConnectionAwareCustomTalktoMessage extends TalktoMessage
{
    public function getConnectionName()
    {
        return 'custom_model_talkto';
    }
}

class ConnectionAwareCustomTalktoEvent extends ConnectionAwareObservedTalktoEvent
{
    public function getConnectionName()
    {
        return 'custom_model_talkto';
    }
}
