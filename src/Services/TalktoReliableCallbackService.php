<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Data\TalktoIncomingCommandResultData;
use Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData;
use Mrezdev\LaravelTalkto\Exceptions\TalktoReliableCallbackPersistenceException;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use Mrezdev\LaravelTalkto\Support\TalktoReliableCallbackContext;
use RuntimeException;
use Throwable;

/** @internal Atomic result/callback persistence; publication remains owned by dispatch intents. */
class TalktoReliableCallbackService
{
    public const LOCK_PREFIX = 'reliable-callback:';

    private static array $active = [];

    public function __construct(
        private readonly TalktoModelResolver $models,
        private readonly TalktoDispatchIntentService $intents,
        private readonly TalktoCurrentServiceGuard $guard,
    ) {}

    public static function required(): bool
    {
        return (bool) config('talkto.callbacks.reliable.enabled', false)
            && (bool) config('talkto.callbacks.enabled', true)
            && (bool) config('talkto.callbacks.auto_dispatch', true);
    }

    public static function isProtected(TalktoMessage $message): bool
    {
        return str_starts_with((string) $message->locked_by, self::LOCK_PREFIX)
            || str_starts_with((string) $message->getRawOriginal('locked_by'), self::LOCK_PREFIX);
    }

    private static function activeKey(TalktoMessage $message): string
    {
        return hash('sha256', json_encode([spl_object_id($message->getConnection()), $message->getTable(),
            $message->getRawOriginal($message->getKeyName()), $message->getRawOriginal('message_id')], JSON_THROW_ON_ERROR));
    }

    public static function isActive(TalktoMessage $message): bool
    {
        return self::$active !== [] && isset(self::$active[self::activeKey($message)]);
    }

    public function registerActive(TalktoMessage $message): string
    {
        $key = self::activeKey($message);
        self::$active[$key] = true;

        return $key;
    }

    public function unregisterActive(string $key): void
    {
        unset(self::$active[$key]);
    }

    public function preflight(TalktoMessage $message, bool $handlerBoundary = true, ?TalktoResultCallbackSender $sender = null): TalktoReliableCallbackContext
    {
        if (! config('talkto.callbacks.reliable.enabled', false) || ! config('talkto.callbacks.enabled', true)) {
            throw new RuntimeException('Reliable callbacks are disabled.');
        }
        foreach (['persist', 'persistForAttempt'] as $method) {
            $this->inherited($this, $method, self::class);
        }
        foreach (['assertReady', 'assertCallbackReady', 'recordCallback', 'publish'] as $method) {
            $this->inherited($this->intents, $method, TalktoDispatchIntentService::class);
        }
        $this->intents->assertReady();
        if ($message->direction !== 'incoming' || ! $this->guard->ownsIncoming($message)) {
            throw new RuntimeException('Reliable callbacks require an incoming message owned by the current service.');
        }
        $connection = $message->getConnection();
        if ($handlerBoundary && $connection->transactionLevel() !== 0) {
            throw new RuntimeException('Reliable incoming processing cannot start inside an existing transaction.');
        }
        $sender ??= app(ResultCallbackSenderContract::class);
        if (! $sender instanceof TalktoResultCallbackSender) {
            throw new RuntimeException('The custom callback sender requires a compatible reliable callback adapter.');
        }
        $this->inherited($sender, 'sendResult', TalktoResultCallbackSender::class);
        $this->inherited($sender, 'reliableCallbackFactory', TalktoResultCallbackSender::class);
        $factory = $sender->reliableCallbackFactory();
        $this->inherited($factory, 'createForIncomingResult', TalktoResultCallbackMessageFactory::class);
        $this->inherited($factory, 'createPreparedForReliableResult', TalktoResultCallbackMessageFactory::class);
        $this->inherited($factory, 'createPreparedForReliableAttempt', TalktoResultCallbackMessageFactory::class);
        $this->inherited($factory, 'reliableOutgoingFactory', TalktoResultCallbackMessageFactory::class);
        $outgoing = $factory->reliableOutgoingFactory();
        $this->inherited($outgoing, 'create', TalktoOutgoingMessageFactory::class);
        $this->inherited($outgoing, 'messageModelClass', TalktoOutgoingMessageFactory::class);
        $this->inherited($outgoing, 'eventModelClass', TalktoOutgoingMessageFactory::class);
        $this->inherited($outgoing, 'assertReliableCallbackTarget', TalktoOutgoingMessageFactory::class);
        $outgoing->assertReliableCallbackTarget((string) $message->source_service);
        $command = (string) config('talkto.callbacks.command', 'talkto.result');
        app(TalktoEnvelopeFieldValidator::class)->validateIdentifier('command', $command !== '' ? $command : 'talkto.result');
        // Success/skip finalization also checks existing dead-letter reprocessing.
        $classes = ['message' => $this->models->message(), 'attempt' => $this->models->attempt(), 'event' => $this->models->event(),
            'dispatch_intent' => $this->models->dispatchIntent(), 'dead_letter' => $this->models->deadLetter()];
        $bindings = [];
        foreach ($classes as $name => $class) {
            $model = new $class;
            if ($model->getConnection() !== $connection || ! $connection->getSchemaBuilder()->hasTable($model->getTable())) {
                throw new RuntimeException('Reliable callback message, attempt, event, intent and enabled dead-letter storage must exist on the same actual connection.');
            }
            $bindings[$name] = [$class, $model->getTable(), $model->getKeyName()];
        }
        if ($message::class !== $classes['message'] || $message->getTable() !== $bindings['message'][1] || $message->getKeyName() !== $bindings['message'][2]) {
            throw new RuntimeException('Reliable callbacks require the current configured message binding.');
        }
        $routing = $this->intents->assertCallbackReady($message);
        $identity = [];
        foreach (['id', 'message_id', 'direction', 'source_service', 'target_service', 'command', 'correlation_id', 'parent_message_id', 'business_key', 'idempotency_key', 'payload_hash', 'schema_version'] as $name) {
            $identity[$name] = $message->getRawOriginal($name);
        }
        $identity[$message->getKeyName()] = $message->getRawOriginal($message->getKeyName());
        $context = new TalktoReliableCallbackContext($connection, $classes, $bindings, $this->containerBindings(), $this->configuration($routing), $identity, $routing, $this->guard->currentService(), $sender, $factory, $outgoing);
        $this->assertContext($context);

        return $context;
    }

    private function inherited(object $instance, string $method, string $base): void
    {
        if ((new \ReflectionMethod($instance, $method))->getDeclaringClass()->getName() !== $base) {
            throw new RuntimeException('The custom callback sender or factory method '.$method.' requires a compatible reliable callback adapter.');
        }
    }

    private function containerBindings(): array
    {
        $watched = [ResultCallbackSenderContract::class, TalktoResultCallbackMessageFactory::class, TalktoOutgoingMessageFactory::class,
            TalktoModelResolver::class, TalktoDispatchIntentService::class, TalktoPayloadFreezer::class, TalktoPayloadHasher::class, TalktoEnvelopeFieldValidator::class];
        $shared = [];
        foreach ($watched as $abstract) {
            if (app()->isShared($abstract) && app()->resolved($abstract)) {
                $shared[$abstract] = app($abstract);
            }
        }

        return ['bindings' => array_intersect_key(app()->getBindings(), array_flip($watched)), 'shared' => $shared];
    }

    private function configuration(array $routing): array
    {
        return ['service' => config('talkto.service'), 'models' => config('talkto.models'), 'database' => config('talkto.database'),
            'enabled' => config('talkto.callbacks.reliable.enabled', false), 'callbacks_enabled' => config('talkto.callbacks.enabled', true),
            'auto_dispatch' => config('talkto.callbacks.auto_dispatch', true), 'command' => config('talkto.callbacks.command', 'talkto.result'),
            'outgoing' => config('talkto.outgoing'), 'aliases' => config('talkto.aliases'), 'send_job' => config('talkto.jobs.send_message'),
            'queue_default' => config('queue.default'), 'queue_connection' => config('queue.connections.'.$routing['queue_connection']),
            'dispatch_enabled' => config('talkto.dispatch_intents.enabled', false), 'retry' => config('talkto.retry'), 'dead_letter' => config('talkto.dead_letter')];
    }

    public function assertContext(TalktoReliableCallbackContext $context): void
    {
        if ($context->configuration !== $this->configuration($context->routing) || $context->containerBindings !== $this->containerBindings()
            || $this->guard->currentService() !== $context->owner) {
            throw new RuntimeException('Reliable callback configuration or bindings changed while processing.');
        }
        foreach ($context->modelBindings as $name => [$class, $table, $key]) {
            $current = match ($name) {
                'message' => $this->models->message(), 'attempt' => $this->models->attempt(), 'event' => $this->models->event(),
                'dispatch_intent' => $this->models->dispatchIntent(), 'dead_letter' => $this->models->deadLetter(),
                default => throw new RuntimeException('Unknown reliable callback model binding.'),
            };
            $model = new $current;
            if ($current !== $class || $model->getConnection() !== $context->connection || $model->getTable() !== $table || $model->getKeyName() !== $key) {
                throw new RuntimeException('The reliable callback model connection or binding changed while processing.');
            }
        }
        foreach (DB::getConnections() as $connection) {
            if ($connection !== $context->connection && $connection->transactionLevel() > 0) {
                throw new RuntimeException('Reliable callbacks cannot coordinate a transaction on another connection.');
            }
        }
    }

    public function freezeResult(IncomingCommandResultContract $result): IncomingCommandResultContract
    {
        $data = TalktoIncomingCommandResultData::fromResult($result);
        $payload = app(TalktoPayloadFreezer::class)->freezePayload(['result' => $data->result, 'meta' => $data->meta]);

        return new TalktoIncomingCommandResult($data->succeeded, $data->retryable, $data->errorClass, $data->errorMessage, $payload['result'], $payload['meta'], $data->skipped);
    }

    public function lockResult(TalktoReliableCallbackContext $context, TalktoMessage $message, TalktoAttempt $attempt): array
    {
        $this->assertContext($context);
        $class = $context->models['message'];
        $locked = $class::query()->whereKey($message->getKey())->lockForUpdate()->first();
        $attemptClass = $context->models['attempt'];
        $lockedAttempt = $attemptClass::query()->whereKey($attempt->getKey())->lockForUpdate()->first();
        if (! $locked instanceof TalktoMessage || ! $lockedAttempt instanceof TalktoAttempt || ! self::isProtected($locked)
            || $locked->locked_by !== $message->locked_by || $locked->overall_status !== 'processing' || $locked->destination_action_status !== 'processing'
            || $lockedAttempt->status !== 'processing' || (int) $lockedAttempt->talkto_message_id !== (int) $message->getKey()
            || (int) $lockedAttempt->attempt_no !== (int) $locked->getAttribute('attempts') || $lockedAttempt->message_id !== $message->message_id
            || ($lockedAttempt->meta['reliable_callback_claim'] ?? null) !== $message->locked_by) {
            throw new RuntimeException('The reliable callback processing result lost its ownership fence.');
        }
        $this->assertMessageIdentity($context, $locked);

        return [$locked, $lockedAttempt];
    }

    public function assertMessageIdentity(TalktoReliableCallbackContext $context, TalktoMessage $message): void
    {
        $this->assertContext($context);
        if (! $this->guard->ownsIncoming($message)) {
            throw new RuntimeException('Reliable processing lost current-service ownership.');
        }
        foreach ($context->identity as $name => $value) {
            if ($message->getRawOriginal($name) !== $value) {
                throw new RuntimeException('The incoming message identity changed while processing.');
            }
        }
    }

    public function assertPersistedResult(TalktoReliableCallbackContext $context, TalktoMessage $message, TalktoAttempt $attempt): void
    {
        $this->assertMessageIdentity($context, $message);
        $class = $context->models['attempt'];
        $persisted = $class::query()->whereKey($attempt->getKey())->first();
        if (! $persisted instanceof TalktoAttempt || $persisted->status !== $attempt->status
            || $message->overall_status !== $persisted->status
            || ($message->destination_action_status !== $persisted->status
                && ! ($message->overall_status === 'failed_retryable' && $message->destination_action_status === 'failed'
                    && $message->next_retry_at !== null && $message->next_attempt_at !== null))
            || in_array($message->overall_status, ['processing', 'queued', 'pending'], true)
            || $message->locked_at !== null || $message->locked_by !== null
            || $persisted->message_id !== $message->message_id || (int) $persisted->talkto_message_id !== (int) $message->getKey()
            || $persisted->error_class !== $attempt->error_class || $persisted->error_message !== $attempt->error_message
            || app(TalktoPayloadHasher::class)->hash($persisted->meta ?? []) !== app(TalktoPayloadHasher::class)->hash($attempt->meta ?? [])) {
            throw new RuntimeException('The reliable processing result or attempt was not durably saved.');
        }
    }

    public function persist(TalktoReliableCallbackContext $context, TalktoMessage $incoming, IncomingCommandResultContract $result, array $options = []): TalktoMessage
    {
        return $this->persistResult($context, $incoming, $result, $options);
    }

    /** @internal The pipeline supplies the attempt already locked and verified in the result transaction. */
    public function persistForAttempt(TalktoReliableCallbackContext $context, TalktoMessage $incoming, IncomingCommandResultContract $result, TalktoAttempt $attempt): TalktoMessage
    {
        return $this->persistResult($context, $incoming, $result, [], $attempt);
    }

    private function persistResult(TalktoReliableCallbackContext $context, TalktoMessage $incoming, IncomingCommandResultContract $result, array $options, ?TalktoAttempt $attempt = null): TalktoMessage
    {
        $this->assertContext($context);
        $attemptIdentity = $this->attemptIdentity($context, $incoming, $attempt);
        if ($attemptIdentity !== null) {
            $customId = $options['callback_message_id'] ?? null;
            if (is_string($customId) && $customId !== '' && $customId !== $attemptIdentity['message_id']) {
                throw new RuntimeException('A reliable processing attempt has a fixed callback identity; a custom callback id cannot replace it.');
            }
            $options['callback_message_id'] = $attemptIdentity['message_id'];
        }
        $expected = TalktoResultCallbackData::fromIncomingMessageResult($incoming, $result, $options);
        $command = (string) config('talkto.callbacks.command', 'talkto.result');
        if ($expected->command !== ($command !== '' ? $command : 'talkto.result')) {
            throw new RuntimeException('Reliable callbacks require the configured callback command; command overrides need a compatible adapter.');
        }
        $idempotencyKey = $attemptIdentity['idempotency_key'] ?? sprintf('talkto:callback:%s:%s', $expected->originalMessageId, $expected->status);
        $target = config('talkto.aliases.'.$expected->target);
        $target = is_string($target) && $target !== '' ? $target : $expected->target;
        $messageClass = $context->models['message'];
        if ($attemptIdentity !== null && $attempt === null) {
            // A later automatic result already committed its callback. A manual
            // resend may reuse that record, never recreate or backfill history.
            $callback = $messageClass::query()->where('message_id', $expected->callbackMessageId)->where('direction', 'outgoing')
                ->where('idempotency_fingerprint', $messageClass::idempotencyFingerprint('outgoing', $context->owner, $target, $expected->command, $idempotencyKey))
                ->lockForUpdate()->first();
            if (! $callback instanceof TalktoMessage) {
                throw new RuntimeException('The later processing attempt has no verified existing callback to resend; historical callbacks are not regenerated.');
            }
        } else {
            $callback = $attemptIdentity === null
                ? $context->factory->createPreparedForReliableResult($incoming, $expected)
                : $context->factory->createPreparedForReliableAttempt($incoming, $expected, $idempotencyKey);
        }
        $this->assertContext($context);
        $created = $callback->wasRecentlyCreated;
        $lockedCallback = $messageClass::query()->whereKey($callback->getKey())->lockForUpdate()->first();
        if (! $lockedCallback instanceof TalktoMessage || $lockedCallback->message_id !== $callback->message_id) {
            throw new RuntimeException('The reliable callback message could not be locked.');
        }
        $callback = $lockedCallback;
        $callback->wasRecentlyCreated = $created;
        $this->assertContext($context);
        $intentClass = $context->models['dispatch_intent'];
        if ($callback->wasRecentlyCreated) {
            $intent = $this->intents->recordCallback($callback, $incoming);
            if (! $intent->exists || $intent->getKey() === null) {
                throw new RuntimeException('The reliable callback intent was not persisted.');
            }
            $intent = $intentClass::query()->whereKey($intent->getKey())->first();
        } else {
            $intent = $intentClass::query()->where('message_id', $callback->message_id)->where('purpose', 'result_callback')->first();
        }
        if (! $intent || $intent->purpose !== 'result_callback' || $intent->message_class !== $callback::class || $intent->message_table !== $callback->getTable()
                || $intent->message_key_name !== $callback->getKeyName() || $intent->message_key !== (string) $callback->getRawOriginal($callback->getKeyName())
                || $intent->owner !== $context->owner || $intent->direction !== 'outgoing'
                || $intent->job_class !== $context->routing['job_class'] || $intent->queue_connection !== $context->routing['queue_connection']
                || $intent->queue_name !== $context->routing['queue_name'] || $intent->queue_driver !== $context->routing['queue_driver']
                || ! hash_equals(hash('sha256', json_encode([$callback->getTable(), $callback->getKeyName(), (string) $callback->getRawOriginal($callback->getKeyName()), (string) $callback->message_id, 'result_callback'], JSON_THROW_ON_ERROR)), $intent->intent_key)) {
            throw new RuntimeException('The callback lacks verified reliable delivery evidence; historical callbacks are not backfilled.');
        }
        $payload = $expected->toPayload();
        $hash = app(TalktoPayloadHasher::class)->hash($payload);
        if ($callback->direction !== 'outgoing' || $callback->message_id !== $expected->callbackMessageId || $callback->parent_message_id !== $expected->parentMessageId
            || $callback->correlation_id !== $expected->correlationId || $callback->command !== $expected->command
            || $callback->business_key !== $expected->businessKey || $callback->source_service !== $context->owner
            || $callback->target_service !== $target || (int) $callback->schema_version !== 1
            || $callback->idempotency_key !== $idempotencyKey
            || ! is_array($callback->payload) || app(TalktoPayloadHasher::class)->hash($callback->payload) !== $hash || $callback->payload_hash !== $hash) {
            throw new RuntimeException('The callback identity is already bound to a different immutable result; investigation is required.');
        }
        $eventClass = $context->models['event'];
        $event = $eventClass::query()->create(['talkto_message_id' => $incoming->id, 'message_id' => $incoming->message_id, 'service_name' => $context->owner,
            'event_type' => 'result_callback_persisted', 'old_status' => null, 'new_status' => 'persisted', 'meta' => [
                'callback_message_id' => $callback->message_id, 'callback_message_db_id' => $callback->id, 'intent_id' => $intent->id,
                'callback_status' => $expected->status, 'reliable' => true,
            ]]);
        if (! $event->exists || $event->getKey() === null || ! $eventClass::query()->whereKey($event->getKey())->exists()) {
            throw new RuntimeException('Reliable callback coordination evidence was not persisted.');
        }
        $this->assertContext($context);
        TalktoDispatchTestHooks::fire('reliable_callback.callback_persisted', $this->hookContext($incoming) + ['callback_message_id' => $callback->message_id, 'callback_db_id' => $callback->id, 'intent_id' => $intent->id]);
        $this->assertContext($context);

        return $callback;
    }

    private function attemptIdentity(TalktoReliableCallbackContext $context, TalktoMessage $incoming, ?TalktoAttempt $attempt): ?array
    {
        // Preserve the original first-result/manual identity. Later real attempts
        // need their own immutable callback even when they report the same status.
        if ((int) $incoming->getAttribute('attempts') < 2) {
            return null;
        }
        if ($attempt === null) {
            $class = $context->models['attempt'];
            $attempts = $class::query()->where('talkto_message_id', $incoming->getKey())
                ->where('message_id', $incoming->message_id)->where('stage', 'destination_processor')
                ->where('attempt_no', (int) $incoming->getAttribute('attempts'))->limit(2)->lockForUpdate()->get();
            if ($attempts->count() > 1) {
                throw new RuntimeException('The current processing attempt is ambiguous.');
            }
            $attempt = $attempts->first();
            if (! $attempt instanceof TalktoAttempt || ! str_starts_with((string) ($attempt->meta['reliable_callback_claim'] ?? ''), self::LOCK_PREFIX)) {
                throw new RuntimeException('A later reliable result requires retained processing-attempt evidence; manual fallback to a status identity is unsafe.');
            }
        }
        $binding = $context->modelBindings['attempt'];
        if (! $attempt->exists || $attempt->getKey() === null || $attempt::class !== $context->models['attempt']
            || $attempt->getConnection() !== $context->connection || $attempt->getTable() !== $binding[1] || $attempt->getKeyName() !== $binding[2]
            || $attempt->stage !== 'destination_processor' || $attempt->message_id !== $incoming->message_id
            || (int) $attempt->talkto_message_id !== (int) $incoming->getKey()
            || (int) $attempt->attempt_no !== (int) $incoming->getAttribute('attempts') || $attempt->status !== $incoming->overall_status
            || in_array($attempt->status, ['processing', 'queued', 'pending'], true)
            || ! str_starts_with((string) ($attempt->meta['reliable_callback_claim'] ?? ''), self::LOCK_PREFIX)) {
            throw new RuntimeException('The reliable callback requires its actual persisted processing attempt.');
        }
        $identity = hash('sha256', json_encode([
            $incoming->message_id, $incoming->getTable(), $incoming->getKeyName(), (string) $incoming->getKey(),
            $attempt->getTable(), $attempt->getKeyName(), (string) $attempt->getKey(), (int) $attempt->attempt_no,
        ], JSON_THROW_ON_ERROR));

        return ['message_id' => 'cb-a-'.$identity, 'idempotency_key' => 'talkto:callback:attempt:'.$identity];
    }

    public function sendResult(TalktoMessage $message, IncomingCommandResultContract $result, array $options, TalktoResultCallbackSender $sender): array
    {
        if (self::isProtected($message) || self::isActive($message)) {
            throw new TalktoReliableCallbackPersistenceException('A protected reliable result must be finalized atomically before a manual callback can be sent.');
        }
        $context = $this->preflight($message, false, $sender);
        try {
            $snapshot = $this->freezeResult($result);
            $callback = $context->connection->transaction(function () use ($context, $message, $snapshot, $options): TalktoMessage {
                $this->assertContext($context);
                $class = $context->models['message'];
                $incoming = $class::query()->whereKey($message->getKey())->lockForUpdate()->first();
                if (! $incoming instanceof TalktoMessage || $incoming->message_id !== $message->message_id || ! $this->guard->ownsIncoming($incoming)) {
                    throw new RuntimeException('The reliable callback incoming binding is unavailable.');
                }
                if (self::isProtected($incoming)) {
                    throw new TalktoReliableCallbackPersistenceException('The durable incoming processing claim has not committed a result; manual callback publication is unsafe.');
                }

                return $this->persist($context, $incoming, $snapshot, $options);
            });
            $intentClass = $context->models['dispatch_intent'];
            $intent = $intentClass::query()->where('message_id', $callback->message_id)->where('purpose', 'result_callback')->first();
            $published = $callback->wasRecentlyCreated && $intent?->status === 'published';

            return ['sent' => false, 'queued' => $published, 'status' => $published ? 'queued' : (string) $callback->overall_status,
                'message_id' => $callback->message_id, 'callback_message_id' => $callback->message_id, 'callback_message_db_id' => $callback->id,
                'original_message_id' => (string) $message->message_id, 'target' => $callback->target_service, 'command' => $callback->command,
                'duplicate' => ! $callback->wasRecentlyCreated];
        } catch (Throwable) {
            throw new TalktoReliableCallbackPersistenceException('Reliable callback delivery state requires investigation. Inspect existing records before attempting publication.');
        }
    }

    public function investigate(TalktoReliableCallbackContext $context, TalktoMessage $message, TalktoAttempt $attempt, Throwable $failure, bool $resultReturned): void
    {
        try {
            $this->assertContext($context);
            if ($context->connection->transactionLevel() !== 0) {
                return;
            }
            $context->connection->transaction(function () use ($context, $message, $attempt, $failure, $resultReturned): void {
                $row = $context->connection->table($context->modelBindings['message'][1])->where($message->getKeyName(), $message->getKey())
                    ->where('message_id', $message->message_id)->where('direction', 'incoming')->where('target_service', $context->owner)
                    ->where('locked_by', $message->locked_by)->where('overall_status', 'processing')->lockForUpdate()->first();
                if (! $row) {
                    return;
                }
                $context->connection->table($context->modelBindings['event'][1])->insert(['talkto_message_id' => $message->id, 'message_id' => $message->message_id,
                    'service_name' => $context->owner, 'event_type' => 'reliable_callback_investigation_required', 'old_status' => 'processing', 'new_status' => 'processing',
                    'meta' => json_encode(['attempt_id' => $attempt->getKey(), 'result_returned' => $resultReturned, 'error_class' => $failure::class,
                        'reason' => 'result_callback_transaction_not_committed'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            });
        } catch (Throwable) {
            // The committed protected processing marker remains the minimum evidence.
        }
    }

    public function hookContext(TalktoMessage $message, ?TalktoAttempt $attempt = null): array
    {
        return ['incoming_message_id' => $message->message_id, 'incoming_db_id' => $message->id, 'attempt_id' => $attempt?->getKey(), 'claim_token' => $message->locked_by];
    }
}
