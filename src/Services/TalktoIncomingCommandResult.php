<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;

/**
 * @phpstan-consistent-constructor
 */
class TalktoIncomingCommandResult implements IncomingCommandResultContract
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

    public function isSucceeded(): bool
    {
        return $this->succeeded;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function errorClass(): ?string
    {
        return $this->errorClass;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function result(): array
    {
        return $this->result;
    }

    public function meta(): array
    {
        return $this->meta;
    }

    public function ok(): bool
    {
        return $this->isSucceeded();
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
}
