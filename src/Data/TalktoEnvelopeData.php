<?php

namespace Mrezdev\LaravelTalkto\Data;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class TalktoEnvelopeData
{
    public function __construct(
        public int $protocolVersion,
        public string $messageId,
        public ?string $correlationId,
        public ?string $parentMessageId,
        public string $source,
        public string $target,
        public string $command,
        public ?string $businessKey,
        public ?string $idempotencyKey,
        public int $schemaVersion,
        public ?string $createdAt,
        public string $payloadHash,
        public ?array $payload,
    ) {}

    public static function fromArray(array $envelope): self
    {
        foreach (['message_id', 'source', 'target', 'command', 'payload_hash'] as $field) {
            if (! array_key_exists($field, $envelope) || $envelope[$field] === null || $envelope[$field] === '') {
                throw new InvalidArgumentException("Talkto envelope field [{$field}] is required.");
            }
        }

        return new self(
            self::positiveIntOrDefault($envelope['protocol_version'] ?? null, 2),
            (string) $envelope['message_id'],
            self::nullableString($envelope['correlation_id'] ?? null),
            self::nullableString($envelope['parent_message_id'] ?? null),
            (string) $envelope['source'],
            (string) $envelope['target'],
            (string) $envelope['command'],
            self::nullableString($envelope['business_key'] ?? null),
            self::nullableString($envelope['idempotency_key'] ?? null),
            self::positiveIntOrDefault($envelope['schema_version'] ?? null, 1),
            self::nullableString($envelope['created_at'] ?? null),
            (string) $envelope['payload_hash'],
            is_array($envelope['payload'] ?? null) ? $envelope['payload'] : null,
        );
    }

    public static function fromMessage(Model $message): self
    {
        return new self(
            2,
            (string) $message->message_id,
            self::nullableString($message->correlation_id ?? null),
            self::nullableString($message->parent_message_id ?? null),
            (string) $message->source_service,
            (string) $message->target_service,
            (string) $message->command,
            self::nullableString($message->business_key ?? null),
            self::nullableString($message->idempotency_key ?? null),
            self::positiveIntOrDefault($message->schema_version ?? null, 1),
            self::dateString($message->created_at ?? null),
            (string) $message->payload_hash,
            is_array($message->payload ?? null) ? $message->payload : null,
        );
    }

    public function toArray(): array
    {
        return [
            'protocol_version' => $this->protocolVersion,
            'message_id' => $this->messageId,
            'correlation_id' => $this->correlationId,
            'parent_message_id' => $this->parentMessageId,
            'source' => $this->source,
            'target' => $this->target,
            'command' => $this->command,
            'business_key' => $this->businessKey,
            'idempotency_key' => $this->idempotencyKey,
            'schema_version' => $this->schemaVersion,
            'created_at' => $this->createdAt,
            'payload_hash' => $this->payloadHash,
            'payload' => $this->payload,
        ];
    }

    public function requiredSignatureFields(): array
    {
        return [
            'message_id' => $this->messageId,
            'source' => $this->source,
            'target' => $this->target,
            'command' => $this->command,
            'payload_hash' => $this->payloadHash,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function positiveIntOrDefault(mixed $value, int $default): int
    {
        if ($value === null || $value === '' || $value === false) {
            return $default;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : $default;
    }

    private static function dateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }
}
