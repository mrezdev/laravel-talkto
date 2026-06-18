<?php

namespace Ibake\TalktoReliable\Support;

use Carbon\CarbonInterface;

class TalktoMetricsSnapshot
{
    public function __construct(
        public readonly CarbonInterface $windowStart,
        public readonly CarbonInterface $windowEnd,
        public readonly int $totalMessages,
        public readonly int $incomingMessages,
        public readonly int $outgoingMessages,
        public readonly int $succeededMessages,
        public readonly int $failedMessages,
        public readonly int $retryableMessages,
        public readonly int $finalFailedMessages,
        public readonly int $processingMessages,
        public readonly int $dueRetryMessages,
        public readonly int $openDeadLetters,
        public readonly float $successRate,
        public readonly float $failureRate,
        public readonly array $statusCounts = [],
        public readonly array $directionCounts = [],
        public readonly array $deadLetterCounts = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'window_start' => $this->windowStart->toIso8601String(),
            'window_end' => $this->windowEnd->toIso8601String(),
            'total_messages' => $this->totalMessages,
            'incoming_messages' => $this->incomingMessages,
            'outgoing_messages' => $this->outgoingMessages,
            'succeeded_messages' => $this->succeededMessages,
            'failed_messages' => $this->failedMessages,
            'retryable_messages' => $this->retryableMessages,
            'final_failed_messages' => $this->finalFailedMessages,
            'processing_messages' => $this->processingMessages,
            'due_retry_messages' => $this->dueRetryMessages,
            'open_dead_letters' => $this->openDeadLetters,
            'success_rate' => $this->successRate,
            'failure_rate' => $this->failureRate,
            'status_counts' => $this->statusCounts,
            'direction_counts' => $this->directionCounts,
            'dead_letter_counts' => $this->deadLetterCounts,
        ];
    }
}
