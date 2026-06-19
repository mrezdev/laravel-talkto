<?php

namespace Mrezdev\LaravelTalkto\Services\Panel;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelConnection;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelConnectionHealth;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelHealthStatus;

class TalktoPanelConnectionHealthChecker
{
    public function __construct(private readonly TalktoPanelConnectionRegistry $registry)
    {
    }

    public function check(TalktoPanelConnection $connection, int $windowMinutes = 60): TalktoPanelConnectionHealth
    {
        $windowStart = now()->subMinutes(max(1, $windowMinutes));
        $warnings = $connection->warnings;

        if (! $this->messagesTableExists()) {
            return new TalktoPanelConnectionHealth(
                connection: $connection,
                status: $connection->configured ? TalktoPanelHealthStatus::Unknown : TalktoPanelHealthStatus::Misconfigured,
                warnings: array_values(array_unique([...$warnings, 'messages_table_missing'])),
                checks: ['messages_table_exists' => false],
            );
        }

        $allMessages = $this->messagesForConnection($connection);
        $recentMessages = (clone $allMessages)->where('created_at', '>=', $windowStart);
        $successStatuses = ['completed', 'succeeded'];
        $retryableStatuses = $this->retryableStatuses();
        $failureStatuses = array_values(array_unique([...$retryableStatuses, 'failed_final', 'failed']));
        $deadLetters = $this->deadLettersForConnection($connection);
        $retryBacklog = (clone $allMessages)
            ->whereIn('overall_status', $retryableStatuses)
            ->where(function (Builder $query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->count();
        $recentFailures = (clone $recentMessages)
            ->whereIn('overall_status', $failureStatuses)
            ->count();
        $recentFinalFailures = (clone $recentMessages)
            ->where('overall_status', 'failed_final')
            ->count();
        $recentRetryableFailures = (clone $recentMessages)
            ->whereIn('overall_status', $retryableStatuses)
            ->count();
        $recentSuccesses = (clone $recentMessages)
            ->whereIn('overall_status', $successStatuses)
            ->count();

        $status = TalktoPanelHealthStatus::Unknown;

        if (! $connection->configured) {
            $status = TalktoPanelHealthStatus::Misconfigured;
        } elseif ($deadLetters > 0 || $recentFinalFailures > 0) {
            $status = TalktoPanelHealthStatus::Failing;
        } elseif ($retryBacklog > 0 || $recentRetryableFailures > 0) {
            $status = TalktoPanelHealthStatus::Degraded;
        } elseif ($recentSuccesses > 0 && $warnings === []) {
            $status = TalktoPanelHealthStatus::Healthy;
        }

        if ($deadLetters > 0) {
            $warnings[] = "dead_letters={$deadLetters}";
        }

        if ($retryBacklog > 0) {
            $warnings[] = "retry_backlog={$retryBacklog}";
        }

        if ($recentFailures > 0) {
            $warnings[] = "recent_failures={$recentFailures}";
        }

        return new TalktoPanelConnectionHealth(
            connection: $connection,
            status: $status,
            lastMessageAt: $this->latestTimestamp((clone $allMessages), ['created_at']),
            lastSuccessAt: $this->latestTimestamp((clone $allMessages)->whereIn('overall_status', $successStatuses), ['completed_at', 'updated_at']),
            lastFailureAt: $this->latestTimestamp((clone $allMessages)->whereIn('overall_status', $failureStatuses), ['failed_at', 'updated_at']),
            recentMessages: (clone $recentMessages)->count(),
            recentFailures: $recentFailures,
            retryBacklog: $retryBacklog,
            deadLetters: $deadLetters,
            warnings: array_values(array_unique($warnings)),
            checks: [
                'messages_table_exists' => true,
                'dead_letters_table_exists' => $this->deadLettersTableExists(),
                'window_minutes' => max(1, $windowMinutes),
                'recent_successes' => $recentSuccesses,
                'recent_final_failures' => $recentFinalFailures,
                'recent_retryable_failures' => $recentRetryableFailures,
            ],
        );
    }

    public function checkAll(int $windowMinutes = 60): Collection
    {
        return $this->registry->all()
            ->map(fn (TalktoPanelConnection $connection): TalktoPanelConnectionHealth => $this->check($connection, $windowMinutes))
            ->values();
    }

    private function messagesForConnection(TalktoPanelConnection $connection): Builder
    {
        return $this->messageModelClass()::query()
            ->where('direction', $connection->direction)
            ->when(
                $connection->direction === 'outgoing',
                fn (Builder $query) => $query->where('target_service', $connection->service),
                fn (Builder $query) => $query->where('source_service', $connection->service),
            );
    }

    private function deadLettersForConnection(TalktoPanelConnection $connection): int
    {
        if (! $this->deadLettersTableExists()) {
            return 0;
        }

        return $this->deadLetterModelClass()::query()
            ->where('direction', $connection->direction)
            ->when(
                $connection->direction === 'outgoing',
                fn (Builder $query) => $query->where('target', $connection->service),
                fn (Builder $query) => $query->where('source', $connection->service),
            )
            ->whereIn('status', ['open', 'failed_reprocess'])
            ->count();
    }

    private function latestTimestamp(Builder $query, array $columns): ?CarbonInterface
    {
        foreach ($columns as $column) {
            $message = (clone $query)
                ->whereNotNull($column)
                ->orderByDesc($column)
                ->orderByDesc('id')
                ->first();

            if ($message !== null) {
                return $message->{$column};
            }
        }

        return null;
    }

    private function retryableStatuses(): array
    {
        $statuses = config('talkto.retry.retryable_statuses', ['failed_retryable']);

        return is_array($statuses) && $statuses !== [] ? array_values($statuses) : ['failed_retryable'];
    }

    private function messagesTableExists(): bool
    {
        return $this->tableExists($this->messageModelClass());
    }

    private function deadLettersTableExists(): bool
    {
        return $this->tableExists($this->deadLetterModelClass());
    }

    private function tableExists(string $modelClass): bool
    {
        $model = new $modelClass;

        return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
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
