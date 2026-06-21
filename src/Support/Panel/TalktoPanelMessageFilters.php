<?php

namespace Mrezdev\LaravelTalkto\Support\Panel;

use Illuminate\Support\Carbon;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;

/**
 * @internal Optional panel implementation detail.
 */
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
    ) {}

    public static function fromArray(array $input): self
    {
        $directions = array_map(
            static fn (TalktoMessageDirection $direction): string => $direction->value,
            TalktoMessageDirection::cases()
        );
        $statuses = array_map(
            static fn (TalktoMessageStatus $status): string => $status->value,
            TalktoMessageStatus::cases()
        );

        return new self(
            self::allowedStringOrNull($input['direction'] ?? null, $directions),
            self::allowedStringOrNull($input['status'] ?? null, $statuses),
            self::stringOrNull($input['service'] ?? null),
            self::stringOrNull($input['command'] ?? null),
            self::stringOrNull($input['message_id'] ?? $input['messageId'] ?? null),
            self::stringOrNull($input['correlation_id'] ?? $input['correlationId'] ?? null),
            self::stringOrNull($input['business_key'] ?? $input['businessKey'] ?? null),
            self::stringOrNull($input['idempotency_key'] ?? $input['idempotencyKey'] ?? null),
            self::dateTimeOrNull($input['created_from'] ?? $input['createdFrom'] ?? null),
            self::dateTimeOrNull($input['created_to'] ?? $input['createdTo'] ?? null),
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

    /**
     * @param  array<int, string>  $allowed
     */
    private static function allowedStringOrNull(mixed $value, array $allowed): ?string
    {
        $value = self::stringOrNull($value);

        return $value !== null && in_array($value, $allowed, true) ? $value : null;
    }

    private static function dateTimeOrNull(mixed $value): ?string
    {
        $value = self::stringOrNull($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
