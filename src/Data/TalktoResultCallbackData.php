<?php

namespace Mrezdev\LaravelTalkto\Data;

use Illuminate\Database\Eloquent\Model;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Services\TalktoEnvelopeFieldValidator;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadFreezer;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;

/**
 * Immutable public snapshot of a signed result callback envelope.
 */
final readonly class TalktoResultCallbackData
{
    private array $frozenPayload;

    private string $createdAt;

    public function __construct(
        public string $callbackMessageId,
        public string $originalMessageId,
        public string $originalCommand,
        public ?string $correlationId,
        public ?string $parentMessageId,
        public string $source,
        public string $target,
        public string $command,
        public ?string $businessKey,
        public ?string $idempotencyKey,
        public string $status,
        public TalktoIncomingCommandResultData $resultData,
        ?array $frozenPayload = null,
        ?string $createdAt = null,
    ) {
        app(TalktoEnvelopeFieldValidator::class)->validateIdentifiers([
            'callback_message_id' => $this->callbackMessageId,
            'original_message_id' => $this->originalMessageId,
            'original_command' => $this->originalCommand,
            'correlation_id' => $this->correlationId,
            'parent_message_id' => $this->parentMessageId,
            'source_service' => $this->source,
            'target_service' => $this->target,
            'command' => $this->command,
        ]);

        $payload = $frozenPayload ?? $this->rawPayload();

        $this->frozenPayload = self::freezePayloadSnapshot($payload);
        $this->createdAt = $createdAt ?? now()->toIso8601String();
    }

    public static function fromIncomingMessageResult(Model $message, IncomingCommandResultContract $result, array $options = []): self
    {
        $resultData = TalktoIncomingCommandResultData::fromResult($result);
        $status = self::statusFromResultData($resultData);
        $source = (string) config('talkto.service', 'app');
        $target = (string) $message->source_service;
        $command = self::optionString($options, 'command') ?? (string) config('talkto.callbacks.command', 'talkto.result');
        $originalMessageId = (string) $message->message_id;
        $callbackMessageId = self::optionString($options, 'callback_message_id')
            ?? self::deterministicCallbackMessageId($originalMessageId, $status);

        return new self(
            mb_substr($callbackMessageId, 0, 100),
            $originalMessageId,
            (string) $message->command,
            self::nullableString($message->correlation_id ?? null),
            $originalMessageId,
            $source,
            $target,
            $command !== '' ? $command : 'talkto.result',
            self::nullableString($message->business_key ?? null),
            self::nullableString($message->idempotency_key ?? null),
            $status,
            $resultData,
            null,
            now()->toIso8601String(),
        );
    }

    public static function fromEnvelope(array $envelope): self
    {
        $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];
        $resultData = TalktoIncomingCommandResultData::fromArray([
            'succeeded' => $payload['succeeded'] ?? false,
            'retryable' => $payload['retryable'] ?? false,
            'skipped' => $payload['skipped'] ?? false,
            'error_class' => $payload['error_class'] ?? null,
            'error_message' => $payload['error_message'] ?? null,
            'result' => $payload['result'] ?? [],
            'meta' => $payload['meta'] ?? [],
        ]);

        return new self(
            (string) ($envelope['message_id'] ?? ''),
            (string) ($payload['original_message_id'] ?? ''),
            (string) ($payload['original_command'] ?? ''),
            self::nullableString($envelope['correlation_id'] ?? null),
            self::nullableString($envelope['parent_message_id'] ?? null),
            (string) ($envelope['source'] ?? ''),
            (string) ($envelope['target'] ?? ''),
            (string) ($envelope['command'] ?? ''),
            self::nullableString($envelope['business_key'] ?? null),
            self::nullableString($envelope['idempotency_key'] ?? null),
            (string) ($payload['status'] ?? self::statusFromResultData($resultData)),
            $resultData,
            $payload,
            self::nullableString($envelope['created_at'] ?? null),
        );
    }

    public function toPayload(): array
    {
        return $this->frozenPayload;
    }

    private function rawPayload(): array
    {
        $result = $this->resultData->toArray();

        return [
            'original_message_id' => $this->originalMessageId,
            'original_command' => $this->originalCommand,
            'status' => $this->status,
            'succeeded' => $result['succeeded'],
            'retryable' => $result['retryable'],
            'skipped' => $result['skipped'],
            'error_class' => $result['error_class'],
            'error_message' => $result['error_message'],
            'result' => $result['result'],
            'meta' => $result['meta'],
        ];
    }

    public function toEnvelope(): array
    {
        $payload = $this->frozenPayload;

        return [
            'protocol_version' => 2,
            'message_id' => $this->callbackMessageId,
            'correlation_id' => $this->correlationId,
            'parent_message_id' => $this->parentMessageId,
            'source' => $this->source,
            'target' => $this->target,
            'command' => $this->command,
            'business_key' => $this->businessKey,
            'idempotency_key' => $this->idempotencyKey,
            'schema_version' => 1,
            'created_at' => $this->createdAt,
            'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
            'payload' => $payload,
        ];
    }

    private static function statusFromResultData(TalktoIncomingCommandResultData $resultData): string
    {
        if ($resultData->skipped) {
            return 'skipped';
        }

        if ($resultData->succeeded) {
            return 'succeeded';
        }

        return $resultData->retryable ? 'failed_retryable' : 'failed_final';
    }

    private static function deterministicCallbackMessageId(string $originalMessageId, string $status): string
    {
        return 'cb-'.sha1($originalMessageId.'|'.$status);
    }

    private static function optionString(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function freezePayloadSnapshot(array $payload): array
    {
        return app(TalktoPayloadFreezer::class)->freezePayload($payload) ?? [];
    }
}
