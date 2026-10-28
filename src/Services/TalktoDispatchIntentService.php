<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDispatchIntent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use RuntimeException;
use Throwable;

/** @internal Opt-in initial-dispatch outbox; never backfills historical messages. */
class TalktoDispatchIntentService
{
    public function __construct(private readonly TalktoModelResolver $models, private readonly TalktoCurrentServiceGuard $guard) {}

    public function enabled(): bool
    {
        return config('talkto.dispatch_intents.enabled', false) === true;
    }

    public function assertReady(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Durable dispatch intents are disabled.');
        }
        $message = new ($this->models->message());
        $intent = new ($this->models->dispatchIntent());
        if ($intent->getKeyName() !== 'id' || $intent->getKeyType() !== 'int' || ! $intent->getIncrementing() || $intent->getGlobalScopes() !== []) {
            throw new RuntimeException('Dispatch intents require an unscoped model with the standard incrementing integer id.');
        }
        if ($message->getConnection() !== $intent->getConnection()) {
            throw new RuntimeException('Dispatch intents and messages must use the same actual database connection.');
        }
        $this->assertNoForeignTransactions($message->getConnection());
        $schema = $intent->getConnection()->getSchemaBuilder();
        if (! $schema->hasTable($intent->getTable())) {
            throw new RuntimeException('Durable dispatch intent table is missing; publish and run its opt-in migration first.');
        }
        $primary = array_values(array_filter($schema->getIndexes($intent->getTable()), fn (array $index): bool => $index['primary']));
        if (count($primary) !== 1 || $primary[0]['columns'] !== ['id']) {
            throw new RuntimeException('Dispatch intents require the migrated standard id primary key.');
        }
    }

    private function assertNoForeignTransactions(Connection $connection): void
    {
        foreach (DB::getConnections() as $other) {
            if ($other !== $connection && $other->transactionLevel() > 0) {
                throw new RuntimeException('Durable dispatch does not support an open transaction on another database connection.');
            }
        }
    }

    private function assertCapturedConnection(Connection $connection): void
    {
        if ((new ($this->models->message()))->getConnection() !== $connection
            || (new ($this->models->dispatchIntent()))->getConnection() !== $connection) {
            throw new RuntimeException('The durable model connection changed during the operation.');
        }
    }

    public function record(TalktoMessage $message): TalktoDispatchIntent
    {
        $this->assertReady();
        $connection = $message->getConnection();
        $intentClass = $this->models->dispatchIntent();
        $intentTable = (new $intentClass)->getTable();
        $resolvedMessage = new ($this->models->message());
        if ($message::class !== $resolvedMessage::class || $message->getTable() !== $resolvedMessage->getTable()
            || $message->getKeyName() !== $resolvedMessage->getKeyName() || $connection !== $resolvedMessage->getConnection()) {
            throw new RuntimeException('The message must use the currently configured durable model binding.');
        }
        if ($connection !== (new $intentClass)->getConnection() || $connection->transactionLevel() < 1 || ! $message->wasRecentlyCreated) {
            throw new RuntimeException('An initial intent must be recorded inside its newly created message transaction.');
        }
        if (! $this->initial($message) || ! $this->guard->owns($message)) {
            throw new RuntimeException('Only a new, owned initial message can receive a dispatch intent.');
        }
        $key = $this->compatibleMessageKey($message);
        if ($key === null) {
            throw new RuntimeException('Existing Talkto jobs require a plain nonsensitive integer primary key equal to the effective message id; other keys need a separate job adapter.');
        }
        $routing = $this->routing($message->direction, (int) $key);
        $this->assertCapturedConnection($connection);
        $context = ['message_id' => $message->message_id, 'message_db_key' => $key];
        TalktoDispatchTestHooks::fire('dispatch_intent.before_record', $context);
        $this->assertCapturedConnection($connection);
        $this->assertNoForeignTransactions($connection);
        $intent = $intentClass::create($routing + [
            'intent_key' => hash('sha256', json_encode([$message->getTable(), $message->getKeyName(), $key, (string) $message->message_id, 'initial'], JSON_THROW_ON_ERROR)),
            'message_class' => $message::class,
            'message_table' => $message->getTable(),
            'message_key_name' => $message->getKeyName(),
            'message_key' => $key,
            'message_id' => $message->message_id,
            'direction' => $message->direction,
            'owner' => $this->guard->currentService(),
            'purpose' => 'initial',
            'status' => 'ready',
        ]);
        $this->assertCapturedConnection($connection);
        $this->assertNoForeignTransactions($connection);
        TalktoDispatchTestHooks::fire('dispatch_intent.recorded', $context + ['intent_id' => $intent->id]);
        $this->assertCapturedConnection($connection);
        $this->assertNoForeignTransactions($connection);
        if (config('talkto.dispatch_intents.publish_after_commit', true)) {
            $id = (int) $intent->id;
            $intentKey = $intent->intent_key;
            $owner = $intent->owner;
            $connection->afterCommit(function () use ($id, $connection, $intentTable, $intentKey, $owner): void {
                // Publication failures must not escape a committed source action.
                try {
                    $this->assertCapturedConnection($connection);
                    $this->publish($id);
                } catch (Throwable) {
                    $this->markError($connection, $intentTable, $id, $intentKey, $owner, 'ready', 'post_commit_publication_deferred');
                }
            });
        }

        return $intent;
    }

    private function initial(TalktoMessage $message): bool
    {
        $attributes = $message->getAttributes();

        return (int) ($attributes['attempts'] ?? 0) === 0 && (int) ($attributes['retry_count'] ?? 0) === 0 && $message->last_attempted_at === null
            && $message->locked_at === null && $message->locked_by === null && $message->next_retry_at === null && $message->next_attempt_at === null
            && $message->sent_at === null && $message->processing_started_at === null && $message->completed_at === null && $message->failed_at === null
            && (($message->direction === 'outgoing' && $message->overall_status === 'waiting_to_send' && $message->transport_status === 'pending'
                && $message->received_at === null
                && in_array($message->source_action_status, ['succeeded', 'succeeded_assumed'], true))
                || ($message->direction === 'incoming' && $message->overall_status === 'queued' && $message->destination_action_status === 'queued'));
    }

    private function compatibleMessageKey(TalktoMessage $message): ?string
    {
        $name = $message->getKeyName();
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)
            || preg_match('/payload|secret|token|nonce|signature|header|password|credential|error|exception|response|result|locked_by/i', $name)) {
            return null;
        }
        $raw = $message->getRawOriginal($name);
        $legacy = $message->getAttribute('id');
        if ((! is_int($raw) && ! is_string($raw)) || (! is_int($legacy) && ! is_string($legacy))) {
            return null;
        }
        $key = (string) $raw;

        return (string) (int) $key === $key && strlen($key) <= 191 && (string) $legacy === $key ? $key : null;
    }

    private function jobClass(string $direction): string
    {
        $base = $direction === 'outgoing' ? SendTalktoMessage::class : ProcessIncomingTalktoMessage::class;
        $class = config('talkto.jobs.'.($direction === 'outgoing' ? 'send_message' : 'process_incoming'), $base);

        return is_string($class) && is_a($class, $base, true) ? $class : $base;
    }

    private function routing(string $direction, int $key, mixed $job = null): array
    {
        $class = $this->jobClass($direction);
        $job ??= new $class($key);
        if (method_exists($job, 'queue') || $job instanceof ShouldBeUnique
            || ! empty($job->chained) || ! empty($job->middleware) || ! empty($job->messageGroup) || ! empty($job->deduplicator)) {
            throw new RuntimeException('This custom job requires an unsupported durable dispatch adapter.');
        }
        $reflections = [new \ReflectionClass($class)];
        for ($parent = $reflections[0]->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            $reflections[] = $parent;
        }
        foreach (class_uses_recursive($class) as $trait) {
            $reflections[] = new \ReflectionClass($trait);
        }
        foreach ($reflections as $reflection) {
            foreach ($reflection->getAttributes() as $attribute) {
                if (str_starts_with($attribute->getName(), 'Illuminate\\Queue\\Attributes\\')) {
                    throw new RuntimeException('Queue routing attributes require a separate durable adapter.');
                }
            }
        }
        if (app()->bound('queue.routes')) {
            $routes = app('queue.routes');
            // The native registry exists only on newer supported Laravel versions.
            if ($routes::class !== 'Illuminate\\Queue\\QueueRoutes' || call_user_func([$routes, 'getRoute'], $job) !== null) {
                throw new RuntimeException('Queue route mappings require a separate durable adapter.');
            }
        }
        $connection = $job->connection ?? config('queue.default');
        $driver = is_string($connection) ? config('queue.connections.'.$connection.'.driver') : null;
        if (! is_string($connection) || ! is_string($driver) || ! in_array($driver, ['database', 'redis', 'sqs'], true)) {
            throw new RuntimeException('Durable dispatch supports database, Redis, and SQS routing only; sync and other drivers are unsupported.');
        }
        $queue = $job->queue ?? config('queue.connections.'.$connection.'.queue', 'default');
        if (! is_string($queue) || $queue === '' || strlen($queue) > 191 || strlen($connection) > 100) {
            throw new RuntimeException('Invalid durable queue routing.');
        }
        $delay = $job->delay ?? null;
        if ($delay instanceof \DateInterval) {
            $available = now()->add($delay);
        } elseif ($delay instanceof \DateTimeInterface) {
            $available = Carbon::instance($delay);
        } elseif ($delay === null || (is_int($delay) && $delay >= 0)) {
            $available = $delay ? now()->addSeconds($delay) : null;
        } else {
            throw new RuntimeException('Unsupported durable job delay.');
        }

        return ['job_class' => $class, 'queue_connection' => $connection, 'queue_name' => $queue, 'queue_driver' => $driver, 'available_at' => $available];
    }

    private function boundMessage(TalktoDispatchIntent $intent): ?TalktoMessage
    {
        $class = $this->models->message();
        $model = new $class;
        if ($intent->message_class !== $class || $intent->message_table !== $model->getTable() || $intent->message_key_name !== $model->getKeyName()) {
            return null;
        }
        $message = $class::query()->whereKey($intent->message_key)->lockForUpdate()->first();
        if (! $message || (string) $message->message_id !== (string) $intent->message_id || $message->direction !== $intent->direction
            || $intent->owner !== $this->guard->currentService() || ! $this->guard->owns($message)
            || $this->compatibleMessageKey($message) !== $intent->message_key) {
            return null;
        }

        return $message;
    }

    private function sameBinding(TalktoDispatchIntent $intent, TalktoDispatchIntent $snapshot): bool
    {
        if ($intent->owner !== $this->guard->currentService() || $intent->purpose !== 'initial') {
            return false;
        }
        foreach (['intent_key', 'message_class', 'message_table', 'message_key_name', 'message_key', 'message_id', 'direction', 'owner', 'purpose',
            'job_class', 'queue_connection', 'queue_name', 'queue_driver', 'available_at'] as $column) {
            if ($intent->getRawOriginal($column) !== $snapshot->getRawOriginal($column)) {
                return false;
            }
        }

        return hash_equals(hash('sha256', json_encode([$intent->message_table, $intent->message_key_name, $intent->message_key, (string) $intent->message_id, 'initial'], JSON_THROW_ON_ERROR)), $intent->intent_key);
    }

    private function result(int $id, string $reason, bool $published = false): array
    {
        return ['intent_id' => $id, 'published' => $published, 'reason' => $reason];
    }

    public function publish(int $id): array
    {
        $this->assertReady();
        $class = $this->models->dispatchIntent();
        $connection = (new $class)->getConnection();
        $intentTable = (new $class)->getTable();
        if ($connection->transactionLevel() !== 0) {
            return $this->result($id, 'transaction_not_committed');
        }
        TalktoDispatchTestHooks::fire('dispatch_intent.before_claim', ['intent_id' => $id]);
        $this->assertPublicationBoundary($connection);
        $snapshot = $class::query()->find($id);
        if (! $snapshot || $snapshot->owner !== $this->guard->currentService() || $snapshot->purpose !== 'initial') {
            return $this->result($id, 'not_owned_or_missing');
        }
        // Resolve/connect before a claim. An exception here cannot publish a job.
        try {
            if ($snapshot->job_class !== $this->jobClass($snapshot->direction)
                || config('queue.connections.'.$snapshot->queue_connection.'.driver') !== $snapshot->queue_driver) {
                return $this->result($id, 'routing_configuration_changed');
            }
            $queue = Queue::connection($snapshot->queue_connection);
            $adapter = match ($snapshot->queue_driver) {
                'database' => DatabaseQueue::class,
                'redis' => RedisQueue::class,
                'sqs' => SqsQueue::class,
                default => null,
            };
            if ($adapter === null || ! $queue instanceof $adapter) {
                throw new RuntimeException('The resolved queue backend requires a separate durable adapter.');
            }
            $job = new ($snapshot->job_class)((int) $snapshot->message_key);
            $this->routing($snapshot->direction, (int) $snapshot->message_key, $job);
            $job->onConnection($snapshot->queue_connection)->onQueue($snapshot->queue_name)->beforeCommit();
            $this->assertPublicationBoundary($connection);
        } catch (Throwable) {
            $this->markError($connection, $intentTable, $id, $snapshot->intent_key, $snapshot->owner, 'ready', 'queue_preflight_failed');

            return $this->result($id, 'queue_preflight_failed');
        }
        $token = (string) Str::uuid();
        $claimed = $connection->transaction(function () use ($class, $connection, $id, $snapshot, $token): bool {
            $this->assertCapturedConnection($connection);
            $intent = $class::query()->whereKey($id)->lockForUpdate()->first();
            if (! $intent || ! $this->sameBinding($intent, $snapshot)) {
                return false;
            }
            $message = $this->boundMessage($intent);
            if (! $message || ! $this->initial($message)) {
                if (in_array($intent->status, ['ready', 'claimed'], true)) {
                    $intent->forceFill(['status' => 'cancelled', 'last_error_code' => 'message_not_eligible'])->save();
                }

                return false;
            }
            if ($intent->status !== 'ready' && ! ($intent->status === 'claimed' && $intent->claim_expires_at !== null && $intent->claim_expires_at->lte(now()))) {
                return false;
            }
            $seconds = max(10, min(3600, (int) config('talkto.dispatch_intents.lease_seconds', 60)));
            $intent->forceFill(['status' => 'claimed', 'claim_token' => $token, 'claimed_at' => now(), 'claim_expires_at' => now()->addSeconds($seconds),
                'last_error_code' => null])->save();

            return true;
        });
        if (! $claimed) {
            return $this->result($id, 'not_eligible_or_claimed');
        }
        TalktoDispatchTestHooks::fire('dispatch_intent.claimed', ['intent_id' => $id, 'claim_token' => $token]);
        $this->assertPublicationBoundary($connection);
        TalktoDispatchTestHooks::fire('dispatch_intent.before_publish', ['intent_id' => $id, 'claim_token' => $token]);
        $this->assertPublicationBoundary($connection);
        $atomic = get_class($queue) === DatabaseQueue::class && $queue->getDatabase() === $connection;
        $started = false;
        try {
            if ($atomic) {
                return $connection->transaction(function () use ($class, $connection, $id, $snapshot, $token, $queue, $job, &$started): array {
                    if (! $this->beginPublication($connection, $class, $snapshot, $id, $token)) {
                        return $this->result($id, 'fenced_or_message_advanced');
                    }
                    $started = true;
                    $this->assertCapturedConnection($connection);
                    $this->assertNoForeignTransactions($connection);
                    $ack = $this->push($queue, $job, $snapshot);
                    TalktoDispatchTestHooks::fire('dispatch_intent.after_queue', ['intent_id' => $id]);
                    if (! is_int($ack) && (! is_string($ack) || $ack === '')) {
                        throw new RuntimeException('Queue acknowledgement unavailable.');
                    }
                    TalktoDispatchTestHooks::fire('dispatch_intent.before_ack', ['intent_id' => $id]);
                    $this->assertCapturedConnection($connection);
                    $this->assertNoForeignTransactions($connection);
                    if ($this->outcome($connection, $class, $id, $token, 'published', null) !== 1) {
                        throw new RuntimeException('Atomic publication acknowledgement lost its fence.');
                    }

                    return $this->result($id, 'published_atomic_database', true);
                });
            }
            $started = $connection->transaction(fn (): bool => $this->beginPublication($connection, $class, $snapshot, $id, $token));
            if (! $started) {
                return $this->result($id, 'fenced_or_message_advanced');
            }
            TalktoDispatchTestHooks::fire('dispatch_intent.publishing', ['intent_id' => $id]);
            // No message/intent transaction remains open during external I/O.
            $this->assertPublicationBoundary($connection);
            $ack = $this->push($queue, $job, $snapshot);
            TalktoDispatchTestHooks::fire('dispatch_intent.after_queue', ['intent_id' => $id]);
            if (! is_int($ack) && (! is_string($ack) || $ack === '')) {
                throw new RuntimeException('Queue acknowledgement unavailable.');
            }
            TalktoDispatchTestHooks::fire('dispatch_intent.before_ack', ['intent_id' => $id]);
            $this->assertPublicationBoundary($connection);
            $updated = $connection->transaction(fn (): int => $this->outcome($connection, $class, $id, $token, 'published', null));

            return $this->result($id, $updated ? 'published' : 'fenced_acknowledgement', $updated > 0);
        } catch (Throwable) {
            if (! $atomic && $started) {
                try {
                    $this->assertPublicationBoundary($connection);
                    $connection->transaction(fn (): int => $this->outcome($connection, $class, $id, $token, 'unknown', 'publication_outcome_unknown'));
                } catch (Throwable) {
                    // A publishing row is also never recycled on lease expiry.
                }
            } elseif ($atomic) {
                $this->markError($connection, $intentTable, $id, $snapshot->intent_key, $snapshot->owner, 'claimed', 'atomic_enqueue_rolled_back', $token);
            }

            return $this->result($id, $atomic ? 'atomic_enqueue_rolled_back' : 'publication_outcome_unknown');
        }
    }

    private function beginPublication(Connection $connection, string $class, TalktoDispatchIntent $snapshot, int $id, string $token): bool
    {
        $this->assertCapturedConnection($connection);
        $this->assertNoForeignTransactions($connection);
        $intent = $class::query()->whereKey($id)->lockForUpdate()->first();
        if (! $intent || ! $this->sameBinding($intent, $snapshot) || $intent->status !== 'claimed' || $intent->claim_token !== $token || $intent->claim_expires_at === null || $intent->claim_expires_at->lte(now())) {
            return false;
        }
        $message = $this->boundMessage($intent);
        if (! $message || ! $this->initial($message)) {
            $intent->forceFill(['status' => 'cancelled', 'last_error_code' => 'message_advanced_before_publication'])->save();

            return false;
        }
        $intent->forceFill(['status' => 'publishing', 'publication_started_at' => now(), 'publication_attempts' => (int) $intent->publication_attempts + 1])->save();

        return true;
    }

    private function assertPublicationBoundary(Connection $connection): void
    {
        $this->assertCapturedConnection($connection);
        $this->assertNoForeignTransactions($connection);
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Queue publication requires the actual message transaction to be fully committed.');
        }
    }

    private function push(mixed $queue, mixed $job, TalktoDispatchIntent $intent): mixed
    {
        return $intent->available_at !== null && $intent->available_at->gt(now())
            ? $queue->later($intent->available_at, $job, '', $intent->queue_name)
            : $queue->push($job, '', $intent->queue_name);
    }

    private function outcome(Connection $connection, string $class, int $id, string $token, string $status, ?string $error): int
    {
        $this->assertCapturedConnection($connection);
        $this->assertNoForeignTransactions($connection);

        return $class::query()->whereKey($id)->where('claim_token', $token)->where('status', 'publishing')
            ->update(['status' => $status, 'published_at' => $status === 'published' ? now() : null, 'last_error_code' => $error, 'updated_at' => now()]);
    }

    private function markError(Connection $connection, string $table, int $id, string $intentKey, string $owner, string $status, string $error, ?string $token = null): void
    {
        try {
            $this->assertPublicationBoundary($connection);
            if ($owner !== $this->guard->currentService()) {
                return;
            }
            $query = $connection->table($table)->where('id', $id)->where('intent_key', $intentKey)->where('owner', $owner)->where('status', $status);
            if ($token !== null) {
                $query->where('claim_token', $token);
            }
            $query->update(['last_error_code' => $error, 'updated_at' => now()]);
        } catch (Throwable) {
            // Keep the original ledger state when a safe error update is unavailable.
        }
    }

    public function inspect(?string $direction = null, ?string $messageId = null, int $minimumAgeMinutes = 30, int $limit = 100): array
    {
        return $this->collect($direction, $messageId, $minimumAgeMinutes, $limit, false);
    }

    private function collect(?string $direction, ?string $messageId, int $minimumAgeMinutes, int $limit, bool $eligibleOnly): array
    {
        $this->assertReady();
        if (($direction !== null && ! in_array($direction, ['outgoing', 'incoming'], true)) || $minimumAgeMinutes < 0 || $minimumAgeMinutes > 525600
            || $limit < 1 || $limit > 1000 || ($messageId !== null && ($messageId === '' || mb_strlen($messageId) > 100))) {
            throw new InvalidArgumentException('Invalid dispatch-intent filters.');
        }
        $class = $this->models->dispatchIntent();
        $query = $class::query()->where('owner', $this->guard->currentService())->where('created_at', '<=', now()->subMinutes($minimumAgeMinutes));
        if ($eligibleOnly) {
            $query->where(fn ($states) => $states->where('status', 'ready')->orWhere(fn ($expired) => $expired->where('status', 'claimed')->whereNotNull('claim_expires_at')->where('claim_expires_at', '<=', now())));
        }
        if ($direction !== null) {
            $query->where('direction', $direction);
        }
        if ($messageId !== null) {
            $query->where('message_id', $messageId);
        }
        $rows = $query->toBase()->reorder()->select(['id', 'message_id', 'direction', 'owner', 'status', 'claim_expires_at', 'publication_attempts', 'created_at', 'last_error_code'])
            ->orderBy('created_at')->orderBy('id')->limit($limit)->get();
        $candidates = [];
        foreach ($rows as $row) {
            if ($row->owner !== $this->guard->currentService()) {
                continue;
            }
            $eligible = $row->status === 'ready' || ($row->status === 'claimed' && $row->claim_expires_at !== null && Carbon::parse($row->claim_expires_at)->lte(now()));
            $candidates[] = ['intent_id' => (int) $row->id, 'message_id' => $this->safe($row->message_id), 'direction' => $this->safe($row->direction),
                'status' => $this->safe($row->status), 'publication_attempts' => (int) $row->publication_attempts,
                'recovery_eligible' => $eligible, 'reason' => $eligible ? 'ready_for_locked_revalidation' : 'not_safely_recoverable', 'last_error_code' => $this->safe($row->last_error_code)];
        }

        return ['mode' => 'read_only', 'dry_run' => true, 'count' => count($candidates), 'candidates' => $candidates, 'dispatched' => 0, 'skipped' => 0];
    }

    public function execute(?string $direction = null, ?string $messageId = null, int $minimumAgeMinutes = 30, int $limit = 100): array
    {
        if (! config('talkto.dispatch_intents.recovery_enabled', false)) {
            throw new RuntimeException('Dispatch-intent recovery is disabled.');
        }
        $report = $this->collect($direction, $messageId, $minimumAgeMinutes, $limit, true);
        $report['mode'] = 'execute';
        $report['dry_run'] = false;
        foreach ($report['candidates'] as &$candidate) {
            if (! $candidate['recovery_eligible']) {
                $report['skipped']++;

                continue;
            }
            try {
                $result = $this->publish($candidate['intent_id']);
            } catch (Throwable) {
                $result = $this->result($candidate['intent_id'], 'publication_deferred');
            }
            $candidate['result'] = $result;
            $report[$result['published'] ? 'dispatched' : 'skipped']++;
        }

        return $report;
    }

    private function safe(mixed $value): ?string
    {
        return $value === null ? null : mb_substr(preg_replace('/\p{C}/u', '', mb_scrub((string) $value, 'UTF-8')) ?? '', 0, 191);
    }
}
