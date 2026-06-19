<?php

namespace Mrezdev\LaravelTalkto\Data;

class TalktoHttpResponse
{
    public function __construct(
        private readonly int $statusCode,
        private readonly ?string $body = null,
        private readonly array $headers = [],
        private readonly ?bool $successful = null,
    ) {}

    public function status(): int
    {
        return $this->statusCode;
    }

    public function body(): string
    {
        return (string) $this->body;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function successful(): bool
    {
        return $this->successful ?? ($this->statusCode >= 200 && $this->statusCode < 300);
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        $decoded = json_decode($this->body(), true);

        if (! is_array($decoded)) {
            return $key === null ? null : $default;
        }

        if ($key === null) {
            return $decoded;
        }

        return data_get($decoded, $key, $default);
    }
}
