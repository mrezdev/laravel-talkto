<?php

namespace Mrezdev\LaravelTalkto\Services;

class TalktoIncomingCommandResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly bool $retryable = false,
        public readonly ?string $errorClass = null,
        public readonly ?string $errorMessage = null,
        public readonly array $result = [],
        public readonly array $meta = [],
        public readonly bool $skipped = false,
    ) {}

    public static function succeeded(array $result = [], array $meta = []): static
    {
        return new static(
            succeeded: true,
            retryable: false,
            result: $result,
            meta: $meta
        );
    }

    public static function failedRetryable(string $errorMessage, ?string $errorClass = null, array $meta = []): static
    {
        return new static(
            succeeded: false,
            retryable: true,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
            meta: $meta
        );
    }

    public static function failedFinal(string $errorMessage, ?string $errorClass = null, array $meta = []): static
    {
        return new static(
            succeeded: false,
            retryable: false,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
            meta: $meta
        );
    }

    public static function skipped(?string $reason = null, array $meta = []): static
    {
        return new static(
            succeeded: true,
            result: [],
            meta: array_filter(array_merge([
                'reason' => $reason,
            ], $meta), fn (mixed $value): bool => $value !== null),
            skipped: true
        );
    }
}
