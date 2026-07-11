<?php

namespace Mrezdev\LaravelTalkto\Exceptions;

use Throwable;

class InvalidTalktoRequestJsonException extends TalktoException
{
    public function __construct(
        private readonly string $error = 'invalid_json',
        private readonly int $status = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct($error, 0, $previous);
    }

    public static function invalidJson(?Throwable $previous = null): self
    {
        return new self('invalid_json', 422, $previous);
    }

    public function error(): string
    {
        return $this->error;
    }

    public function status(): int
    {
        return $this->status;
    }
}
