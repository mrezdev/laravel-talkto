<?php

namespace Mrezdev\LaravelTalkto\Support\Panel;

class TalktoPanelActionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $meta = [],
        public readonly ?string $redirectRoute = null,
    ) {}

    public static function success(string $message, array $meta = []): self
    {
        return new self(true, $message, $meta);
    }

    public static function failure(string $message, array $meta = []): self
    {
        return new self(false, $message, $meta);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'meta' => $this->meta,
            'redirect_route' => $this->redirectRoute,
        ];
    }
}
