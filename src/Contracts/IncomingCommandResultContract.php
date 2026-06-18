<?php

namespace Ibake\TalktoReliable\Contracts;

interface IncomingCommandResultContract
{
    public function succeeded(): bool;

    public function retryable(): bool;

    public function errorClass(): ?string;

    public function errorMessage(): ?string;

    public function result(): array;

    public function meta(): array;
}
