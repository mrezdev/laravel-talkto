<?php

namespace Mrezdev\LaravelTalkto\Services;

use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

/**
 * Creates durable outgoing callback messages without sending them.
 */
class TalktoResultCallbackMessageFactory
{
    public function __construct(
        private readonly TalktoOutgoingMessageFactory $outgoingMessages
    ) {}

    public function createForIncomingResult(
        TalktoMessage $incomingMessage,
        IncomingCommandResultContract $result
    ): TalktoMessage {
        if (! $incomingMessage->isIncoming()) {
            throw new InvalidArgumentException('Talkto result callback messages can only be created for incoming messages.');
        }

        $callback = TalktoResultCallbackData::fromIncomingMessageResult($incomingMessage, $result);

        return $this->outgoingMessages->create(
            target: $callback->target,
            command: $callback->command,
            payload: $callback->toPayload(),
            options: [
                'message_id' => $callback->callbackMessageId,
                'correlation_id' => $callback->correlationId,
                'parent_message_id' => $callback->parentMessageId,
                'business_key' => $callback->businessKey,
                'idempotency_key' => $this->idempotencyKey($callback->originalMessageId, $callback->status),
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
