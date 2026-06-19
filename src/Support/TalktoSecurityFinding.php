<?php

namespace Mrezdev\LaravelTalkto\Support;

final readonly class TalktoSecurityFinding
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public string $recommendation,
        public array $context = []
    ) {}

    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code' => $this->code,
            'message' => $this->message,
            'recommendation' => $this->recommendation,
            'context' => $this->context,
        ];
    }
}
