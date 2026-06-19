<?php

namespace Mrezdev\LaravelTalkto\Support;

final readonly class TalktoRetryDecision
{
    public function __construct(
        public bool $retryable,
        public bool $canSchedule,
        public string $reason,
        public bool $directionEnabled,
        public bool $statusRetryable,
        public bool $httpStatusRetryable,
        public int $currentRetryCount,
        public int $maxAttempts,
        public int $backoffSeconds,
        public ?string $nextRetryAt,
        public string $finalFailureStatus,
        public array $policy,
    ) {}

    public function toArray(): array
    {
        return [
            'retryable' => $this->retryable,
            'can_schedule' => $this->canSchedule,
            'reason' => $this->reason,
            'direction_enabled' => $this->directionEnabled,
            'status_retryable' => $this->statusRetryable,
            'http_status_retryable' => $this->httpStatusRetryable,
            'current_retry_count' => $this->currentRetryCount,
            'max_attempts' => $this->maxAttempts,
            'backoff_seconds' => $this->backoffSeconds,
            'next_retry_at' => $this->nextRetryAt,
            'final_failure_status' => $this->finalFailureStatus,
            'policy' => $this->policy,
        ];
    }
}
