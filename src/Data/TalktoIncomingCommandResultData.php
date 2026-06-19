<?php

namespace Mrezdev\LaravelTalkto\Data;

use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;

final readonly class TalktoIncomingCommandResultData
{
    public function __construct(
        public bool $succeeded,
        public bool $retryable,
        public bool $skipped,
        public ?string $errorClass,
        public ?string $errorMessage,
        public array $result,
        public array $meta,
    ) {}

    public static function fromResult(IncomingCommandResultContract $result): self
    {
        return new self(
            succeeded: $result->isSucceeded(),
            retryable: $result->isRetryable(),
            skipped: $result->isSkipped(),
            errorClass: $result->errorClass(),
            errorMessage: $result->errorMessage(),
            result: $result->result(),
            meta: $result->meta(),
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            succeeded: (bool) ($data['succeeded'] ?? false),
            retryable: (bool) ($data['retryable'] ?? false),
            skipped: (bool) ($data['skipped'] ?? false),
            errorClass: self::nullableString($data['error_class'] ?? null),
            errorMessage: self::nullableString($data['error_message'] ?? null),
            result: is_array($data['result'] ?? null) ? $data['result'] : [],
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    public function toArray(): array
    {
        return [
            'succeeded' => $this->succeeded,
            'retryable' => $this->retryable,
            'error_class' => $this->errorClass,
            'error_message' => $this->errorMessage,
            'result' => $this->result,
            'meta' => $this->meta,
            'skipped' => $this->skipped,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
