<?php

namespace Ibake\TalktoReliable\Services;

use Ibake\TalktoReliable\Models\TalktoMessage;

class TalktoRetryPolicy
{
    public function backoffSeconds(int $retryCount): int
    {
        $backoff = config('talkto.retry.backoff_seconds', [10, 30, 60, 120, 300]);
        $backoff = is_array($backoff) && $backoff !== [] ? array_values($backoff) : [10];
        $index = max(0, min($retryCount, count($backoff) - 1));

        return max(0, (int) $backoff[$index]);
    }

    public function retryableStatuses(): array
    {
        $statuses = config('talkto.retry.retryable_statuses', ['failed_retryable']);

        return is_array($statuses) && $statuses !== []
            ? array_values(array_filter($statuses, 'is_string'))
            : ['failed_retryable'];
    }

    public function finalFailureStatus(): string
    {
        $status = config('talkto.retry.final_failure_status', 'failed_final');

        return is_string($status) && $status !== '' ? $status : 'failed_final';
    }

    public function isRetryableHttpStatus(?int $status): bool
    {
        if ($status === null) {
            return false;
        }

        $retryableStatuses = config('talkto.retry.retryable_http_statuses', [408, 425, 429]);
        $retryableStatuses = is_array($retryableStatuses)
            ? array_map('intval', $retryableStatuses)
            : [408, 425, 429];

        if (in_array($status, $retryableStatuses, true)) {
            return true;
        }

        return (bool) config('talkto.retry.retry_server_errors', true)
            && $status >= 500
            && $status <= 599;
    }

    public function maxAttempts(TalktoMessage $message): int
    {
        $messageMaxAttempts = (int) ($message->max_attempts ?? 0);

        if ($messageMaxAttempts > 0) {
            return $messageMaxAttempts;
        }

        return max(1, (int) config('talkto.retry.max_attempts', 5));
    }

    public function isDirectionEnabled(TalktoMessage $message): bool
    {
        if (! (bool) config('talkto.retry.enabled', true)) {
            return false;
        }

        if ($message->direction === 'outgoing') {
            return (bool) config('talkto.retry.outgoing_enabled', true);
        }

        if ($message->direction === 'incoming') {
            return (bool) config('talkto.retry.incoming_enabled', false);
        }

        return false;
    }

    public function canScheduleRetry(TalktoMessage $message): bool
    {
        return $this->isDirectionEnabled($message)
            && (((int) ($message->retry_count ?? 0)) + 1) < $this->maxAttempts($message);
    }

    public function canRetry(TalktoMessage $message): bool
    {
        return $this->isDirectionEnabled($message)
            && $this->hasRetryableStatus($message)
            && ((int) ($message->retry_count ?? 0)) < $this->maxAttempts($message);
    }

    public function isDue(TalktoMessage $message): bool
    {
        if ($message->next_retry_at === null) {
            return false;
        }

        return $message->next_retry_at->lessThanOrEqualTo(now());
    }

    public function markRetryableFailure(
        TalktoMessage $message,
        string $statusColumn,
        ?string $errorMessage = null,
        ?int $httpStatus = null
    ): TalktoMessage {
        $currentRetryCount = (int) ($message->retry_count ?? 0);
        $nextRetryAt = now()->addSeconds($this->backoffSeconds($currentRetryCount));

        $message->forceFill(array_filter([
            $statusColumn => 'failed',
            'overall_status' => 'failed_retryable',
            'retry_count' => $currentRetryCount + 1,
            'next_retry_at' => $nextRetryAt,
            'next_attempt_at' => $nextRetryAt,
            'last_attempted_at' => now(),
            'last_http_status' => $httpStatus,
            'last_error' => $this->excerpt($errorMessage),
            'failed_at' => now(),
            'locked_at' => null,
            'locked_by' => null,
        ], fn (mixed $value): bool => $value !== null))->save();

        return $message;
    }

    public function markFinalFailure(
        TalktoMessage $message,
        string $statusColumn,
        ?string $errorMessage = null,
        ?int $httpStatus = null
    ): TalktoMessage {
        $finalStatus = $this->finalFailureStatus();
        $attributes = [
            $statusColumn => $finalStatus,
            'overall_status' => $finalStatus,
            'next_retry_at' => null,
            'next_attempt_at' => null,
            'last_attempted_at' => now(),
            'last_http_status' => $httpStatus,
            'last_error' => $this->excerpt($errorMessage),
            'failed_at' => now(),
            'locked_at' => null,
            'locked_by' => null,
        ];

        if ($httpStatus === null) {
            unset($attributes['last_http_status']);
        }

        $message->forceFill($attributes)->save();

        return $message;
    }

    private function hasRetryableStatus(TalktoMessage $message): bool
    {
        $statuses = $this->retryableStatuses();

        return in_array($message->overall_status, $statuses, true)
            || in_array($message->transport_status, $statuses, true)
            || in_array($message->destination_action_status, $statuses, true);
    }

    private function excerpt(?string $value, int $limit = 2000): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
