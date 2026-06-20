<?php

namespace Mrezdev\LaravelTalkto\Contracts;

/**
 * Describes the stable outcome shape returned by incoming command handlers.
 */
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
