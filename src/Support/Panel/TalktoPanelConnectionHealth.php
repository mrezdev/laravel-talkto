<?php

namespace Mrezdev\LaravelTalkto\Support\Panel;

use Carbon\CarbonInterface;

class TalktoPanelConnectionHealth
{
    public function __construct(
        public readonly TalktoPanelConnection $connection,
        public readonly TalktoPanelHealthStatus|string $status,
        public readonly ?CarbonInterface $lastMessageAt = null,
        public readonly ?CarbonInterface $lastSuccessAt = null,
        public readonly ?CarbonInterface $lastFailureAt = null,
        public readonly int $recentMessages = 0,
        public readonly int $recentFailures = 0,
        public readonly int $retryBacklog = 0,
        public readonly int $deadLetters = 0,
        public readonly array $warnings = [],
        public readonly array $checks = [],
    ) {}

    public function toArray(): array
    {
        return [
            'connection' => $this->connection->toArray(),
            'status' => $this->status instanceof TalktoPanelHealthStatus ? $this->status->value : $this->status,
            'last_message_at' => $this->lastMessageAt?->toIso8601String(),
            'last_success_at' => $this->lastSuccessAt?->toIso8601String(),
            'last_failure_at' => $this->lastFailureAt?->toIso8601String(),
            'recent_messages' => $this->recentMessages,
            'recent_failures' => $this->recentFailures,
            'retry_backlog' => $this->retryBacklog,
            'dead_letters' => $this->deadLetters,
            'warnings' => $this->warnings,
            'checks' => $this->checks,
        ];
    }
}
