<?php

namespace Mrezdev\LaravelTalkto\Services;

use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoModelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;

/**
 * Creates durable outgoing callback messages without sending them.
 */
class TalktoResultCallbackMessageFactory
{
    public function __construct(
        private readonly TalktoOutgoingMessageFactory $outgoingMessages
    ) {}

    /** @internal Actual injected dependency used by reliable callback preflight. */
    public function reliableOutgoingFactory(): TalktoOutgoingMessageFactory
    {
        return $this->outgoingMessages;
    }

    public function createForIncomingResult(
        TalktoMessage $incomingMessage,
        IncomingCommandResultContract $result,
        array $options = []
    ): TalktoMessage {
        if (! $incomingMessage->isIncoming()) {
            throw new InvalidArgumentException('Talkto result callback messages can only be created for incoming messages.');
        }

        $callback = TalktoResultCallbackData::fromIncomingMessageResult($incomingMessage, $result, $options);

        $existing = $this->existingReliableCallback($incomingMessage, $callback, $options);
        if ($existing !== null) {
            return $existing;
        }

        return $this->persistCallback($incomingMessage, $callback);
    }

    /** @internal Reuse one frozen callback representation inside result finalization. */
    public function createPreparedForReliableResult(TalktoMessage $incomingMessage, TalktoResultCallbackData $callback): TalktoMessage
    {
        return $this->persistPreparedCallback($incomingMessage, $callback);
    }

    /** @internal Persist a later processing attempt under its fixed idempotency key. */
    public function createPreparedForReliableAttempt(TalktoMessage $incomingMessage, TalktoResultCallbackData $callback, string $idempotencyKey): TalktoMessage
    {
        return $this->persistPreparedCallback($incomingMessage, $callback, $idempotencyKey);
    }

    private function existingReliableCallback(TalktoMessage $incomingMessage, TalktoResultCallbackData $expected, array $options): ?TalktoMessage
    {
        if ((int) ($incomingMessage->getAttributes()['attempts'] ?? 0) < 2) {
            return null;
        }
        $models = app(TalktoModelResolver::class);
        $messageClass = $models->message();
        $eventClass = $models->event();
        $eventKey = (new $eventClass)->getKeyName();
        $events = $eventClass::query()->where('talkto_message_id', $incomingMessage->getKey())
            ->where('message_id', $incomingMessage->message_id)->where('event_type', 'result_callback_persisted')
            ->where('meta->callback_status', $expected->status);
        $latestId = (clone $events)->max($eventKey);
        if ($latestId === null) {
            return null;
        }
        TalktoModelConnection::assertSameConnection($incomingMessage, $messageClass, $eventClass);
        $connection = $incomingMessage->getConnection();
        if ((new $messageClass)->getConnection() !== $connection || (new $eventClass)->getConnection() !== $connection) {
            throw new InvalidArgumentException('Reliable callback evidence must use the incoming message actual connection.');
        }
        $event = $events->whereKey($latestId)->first([$eventKey, 'service_name', 'meta']);
        $meta = $event?->meta;
        $source = $this->sourceServiceFor($incomingMessage);
        if (! $event instanceof TalktoEvent || ! is_array($meta) || ($meta['reliable'] ?? null) !== true
            || ($meta['callback_status'] ?? null) !== $expected->status || $event->service_name !== $source
            || ! is_string($meta['callback_message_id'] ?? null) || $meta['callback_message_id'] === ''
            || (! is_int($meta['callback_message_db_id'] ?? null) && ! is_string($meta['callback_message_db_id'] ?? null))
            || ! ctype_digit((string) $meta['callback_message_db_id']) || (int) $meta['callback_message_db_id'] < 1) {
            throw new InvalidArgumentException('Reliable callback coordination evidence is invalid; a replacement callback is unsafe.');
        }
        $callback = $messageClass::query()->whereKey($meta['callback_message_db_id'])
            ->where('message_id', $meta['callback_message_id'])->first();
        if (! $callback instanceof TalktoMessage) {
            throw new InvalidArgumentException('The reliable callback record is missing; historical callbacks are not regenerated.');
        }
        $customId = $options['callback_message_id'] ?? null;
        if (is_string($customId) && $customId !== '' && $customId !== $callback->message_id) {
            throw new InvalidArgumentException('A custom callback id cannot replace an existing reliable callback.');
        }
        $target = config('talkto.aliases.'.$expected->target);
        $target = is_string($target) && $target !== '' ? $target : $expected->target;
        $key = $callback->idempotency_key;
        $attemptDigest = is_string($key) && str_starts_with($key, 'talkto:callback:attempt:') ? substr($key, strlen('talkto:callback:attempt:')) : '';
        $validKey = $key === $this->idempotencyKey($expected->originalMessageId, $expected->status)
            || (preg_match('/^[a-f0-9]{64}$/D', $attemptDigest) === 1 && $callback->message_id === 'cb-a-'.$attemptDigest);
        $hash = app(TalktoPayloadHasher::class)->hash($expected->toPayload());
        if (! $validKey || $callback->direction !== 'outgoing' || $callback->parent_message_id !== $expected->parentMessageId
            || $callback->correlation_id !== $expected->correlationId || $callback->business_key !== $expected->businessKey
            || $callback->source_service !== $source || $callback->target_service !== $target || $callback->command !== $expected->command
            || (int) $callback->schema_version !== 1 || ! is_array($callback->payload)
            || $callback->payload_hash !== $hash || app(TalktoPayloadHasher::class)->hash($callback->payload) !== $hash
            || $callback->idempotency_fingerprint !== $messageClass::idempotencyFingerprint('outgoing', $source, $target, $expected->command, $key)) {
            throw new InvalidArgumentException('The existing reliable callback does not match this immutable result.');
        }

        return $callback;
    }

    private function persistPreparedCallback(TalktoMessage $incomingMessage, TalktoResultCallbackData $callback, ?string $idempotencyKey = null): TalktoMessage
    {
        if (! $incomingMessage->isIncoming() || $callback->originalMessageId !== $incomingMessage->message_id) {
            throw new InvalidArgumentException('A prepared reliable callback must match its incoming message.');
        }

        return $this->persistCallback($incomingMessage, $callback, $idempotencyKey);
    }

    private function persistCallback(TalktoMessage $incomingMessage, TalktoResultCallbackData $callback, ?string $idempotencyKey = null): TalktoMessage
    {
        return $this->outgoingMessages->create(
            target: $callback->target,
            command: $callback->command,
            payload: $callback->toPayload(),
            options: [
                'message_id' => $callback->callbackMessageId,
                'correlation_id' => $callback->correlationId,
                'parent_message_id' => $callback->parentMessageId,
                'business_key' => $callback->businessKey,
                'idempotency_key' => $idempotencyKey ?? $this->idempotencyKey($callback->originalMessageId, $callback->status),
                'source_service' => $this->sourceServiceFor($incomingMessage),
            ]
        );
    }

    private function idempotencyKey(string $originalMessageId, string $status): string
    {
        return sprintf('talkto:callback:%s:%s', $originalMessageId, $status);
    }

    private function sourceServiceFor(TalktoMessage $incomingMessage): string
    {
        $sourceService = (string) $incomingMessage->target_service;

        return $sourceService !== '' ? $sourceService : (string) config('talkto.service', 'app');
    }
}
