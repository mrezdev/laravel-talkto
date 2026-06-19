<?php

namespace Mrezdev\LaravelTalkto\Contracts;

interface IncomingCommandResultContract
{
    public function isSucceeded(): bool;

    public function isRetryable(): bool;

    public function isSkipped(): bool;

    public function errorClass(): ?string;

    public function errorMessage(): ?string;

    public function result(): array;

    public function meta(): array;
}
