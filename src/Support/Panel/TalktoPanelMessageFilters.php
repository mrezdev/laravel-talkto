<?php

namespace Mrezdev\LaravelTalkto\Support\Panel;

class TalktoPanelMessageFilters
{
    public function __construct(
        public readonly ?string $direction = null,
        public readonly ?string $status = null,
        public readonly ?string $service = null,
        public readonly ?string $command = null,
        public readonly ?string $messageId = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $businessKey = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $createdFrom = null,
        public readonly ?string $createdTo = null,
    ) {
    }

    public static function fromArray(array $input): self
    {
        return new self(
            direction: self::stringOrNull($input['direction'] ?? null),
            status: self::stringOrNull($input['status'] ?? null),
            service: self::stringOrNull($input['service'] ?? null),
            command: self::stringOrNull($input['command'] ?? null),
            messageId: self::stringOrNull($input['message_id'] ?? $input['messageId'] ?? null),
            correlationId: self::stringOrNull($input['correlation_id'] ?? $input['correlationId'] ?? null),
            businessKey: self::stringOrNull($input['business_key'] ?? $input['businessKey'] ?? null),
            idempotencyKey: self::stringOrNull($input['idempotency_key'] ?? $input['idempotencyKey'] ?? null),
            createdFrom: self::stringOrNull($input['created_from'] ?? $input['createdFrom'] ?? null),
            createdTo: self::stringOrNull($input['created_to'] ?? $input['createdTo'] ?? null),
        );
    }

    public function toArray(): array
    {
        return [
            'direction' => $this->direction,
            'status' => $this->status,
            'service' => $this->service,
            'command' => $this->command,
            'message_id' => $this->messageId,
            'correlation_id' => $this->correlationId,
            'business_key' => $this->businessKey,
            'idempotency_key' => $this->idempotencyKey,
            'created_from' => $this->createdFrom,
            'created_to' => $this->createdTo,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
