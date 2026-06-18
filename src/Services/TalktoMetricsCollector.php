<?php

namespace Ibake\TalktoReliable\Services;

use Carbon\CarbonInterface;
use Ibake\TalktoReliable\Models\TalktoAttempt;
use Ibake\TalktoReliable\Models\TalktoDeadLetter;
use Ibake\TalktoReliable\Models\TalktoEvent;
use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Support\TalktoMetricsSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class TalktoMetricsCollector
{
    public function collect(?CarbonInterface $from = null, ?CarbonInterface $to = null, string $direction = 'all'): TalktoMetricsSnapshot
    {
        $to ??= now();
        $from ??= $to->copy()->subHours((int) config('talkto.observability.report.default_window_hours', 24));
        $statusCounts = $this->statusCounts($from, $to, $direction);
        $directionCounts = $this->directionCounts($from, $to, $direction);
        $total = array_sum($statusCounts);
        $succeeded = $this->sumStatuses($statusCounts, ['succeeded', 'completed']);
        $retryable = $this->sumStatuses($statusCounts, $this->retryableStatuses());
        $finalFailed = $this->sumStatuses($statusCounts, ['failed_final']);
        $failed = $this->sumStatuses($statusCounts, ['failed_retryable', 'failed_final', 'failed']);
        $processing = $this->sumStatuses($statusCounts, ['processing']);
        $dueRetry = $this->dueRetryMessages($direction);
        $deadLetterCounts = $this->deadLetterCounts();
        $openDeadLetters = (int) (($deadLetterCounts['open'] ?? 0) + ($deadLetterCounts['failed_reprocess'] ?? 0));

        return new TalktoMetricsSnapshot(
            windowStart: $from,
            windowEnd: $to,
            totalMessages: $total,
            incomingMessages: (int) ($directionCounts['incoming'] ?? 0),
            outgoingMessages: (int) ($directionCounts['outgoing'] ?? 0),
            succeededMessages: $succeeded,
            failedMessages: $failed,
            retryableMessages: $retryable,
            finalFailedMessages: $finalFailed,
            processingMessages: $processing,
            dueRetryMessages: $dueRetry,
            openDeadLetters: $openDeadLetters,
            successRate: $this->rate($succeeded, $total),
            failureRate: $this->rate($failed, $total),
            statusCounts: $statusCounts,
            directionCounts: $directionCounts,
            deadLetterCounts: $deadLetterCounts
        );
    }

    public function statusCounts(?CarbonInterface $from = null, ?CarbonInterface $to = null, string $direction = 'all'): array
    {
        if (! $this->messagesTableExists()) {
            return [];
        }

        return $this->windowedMessagesQuery($from, $to, $direction)
            ->selectRaw('overall_status, COUNT(*) as aggregate')
            ->groupBy('overall_status')
            ->pluck('aggregate', 'overall_status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    public function directionCounts(?CarbonInterface $from = null, ?CarbonInterface $to = null, string $direction = 'all'): array
    {
        if (! $this->messagesTableExists()) {
            return [];
        }

        return $this->windowedMessagesQuery($from, $to, $direction)
            ->selectRaw('direction, COUNT(*) as aggregate')
            ->groupBy('direction')
            ->pluck('aggregate', 'direction')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    public function dueRetryMessages(string $direction = 'all', ?CarbonInterface $dueBefore = null): int
    {
        if (! $this->messagesTableExists()) {
            return 0;
        }

        $dueBefore ??= now();

        return $this->messageModelClass()::query()
            ->whereIn('overall_status', $this->retryableStatuses())
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $dueBefore)
            ->when($direction !== 'all', fn (Builder $query) => $query->where('direction', $direction))
            ->count();
    }

    public function deadLetterCounts(): array
    {
        if (! $this->deadLettersTableExists()) {
            return [];
        }

        return $this->deadLetterModelClass()::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    public function attemptStatusCounts(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        if (! $this->attemptsTableExists()) {
            return [];
        }

        return $this->attemptModelClass()::query()
            ->when($from !== null, fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('created_at', '<=', $to))
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    public function eventTypeCounts(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        if (! $this->eventsTableExists()) {
            return [];
        }

        return $this->eventModelClass()::query()
            ->when($from !== null, fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('created_at', '<=', $to))
            ->selectRaw('event_type, COUNT(*) as aggregate')
            ->groupBy('event_type')
            ->pluck('aggregate', 'event_type')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    private function windowedMessagesQuery(?CarbonInterface $from, ?CarbonInterface $to, string $direction): Builder
    {
        return $this->messageModelClass()::query()
            ->when($from !== null, fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('created_at', '<=', $to))
            ->when($direction !== 'all', fn (Builder $query) => $query->where('direction', $direction));
    }

    private function rate(int $count, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return round(($count / $total) * 100, 2);
    }

    private function sumStatuses(array $counts, array $statuses): int
    {
        return array_reduce(
            $statuses,
            fn (int $total, string $status): int => $total + (int) ($counts[$status] ?? 0),
            0
        );
    }

    private function retryableStatuses(): array
    {
        $statuses = config('talkto.retry.retryable_statuses', ['failed_retryable']);

        return is_array($statuses) && $statuses !== [] ? array_values($statuses) : ['failed_retryable'];
    }

    private function messagesTableExists(): bool
    {
        return Schema::hasTable((new ($this->messageModelClass()))->getTable());
    }

    private function deadLettersTableExists(): bool
    {
        return Schema::hasTable((new ($this->deadLetterModelClass()))->getTable());
    }

    private function attemptsTableExists(): bool
    {
        return Schema::hasTable((new ($this->attemptModelClass()))->getTable());
    }

    private function eventsTableExists(): bool
    {
        return Schema::hasTable((new ($this->eventModelClass()))->getTable());
    }

    private function attemptModelClass(): string
    {
        $class = config('talkto.models.attempt', TalktoAttempt::class);

        return is_string($class) && is_a($class, TalktoAttempt::class, true)
            ? $class
            : TalktoAttempt::class;
    }

    private function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }

    private function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }

    private function deadLetterModelClass(): string
    {
        $class = config('talkto.models.dead_letter', TalktoDeadLetter::class);

        return is_string($class) && is_a($class, TalktoDeadLetter::class, true)
            ? $class
            : TalktoDeadLetter::class;
    }
}
