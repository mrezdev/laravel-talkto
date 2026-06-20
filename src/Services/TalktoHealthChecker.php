<?php

namespace Mrezdev\LaravelTalkto\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

/**
 * Read-only public service for package health summaries.
 */
class TalktoHealthChecker
{
    public function __construct(private readonly TalktoMetricsCollector $metrics) {}

    public function check(?CarbonInterface $from = null, ?CarbonInterface $to = null, string $direction = 'all'): array
    {
        $to ??= now();
        $from ??= $to->copy()->subHours((int) config('talkto.observability.report.default_window_hours', 24));
        $staleProcessing = $this->staleProcessingMessages($direction);
        $dueRetry = $this->dueRetryBacklog($direction);
        $deadLetterCounts = $this->metrics->deadLetterCounts();
        $openDeadLetters = (int) (($deadLetterCounts['open'] ?? 0) + ($deadLetterCounts['failed_reprocess'] ?? 0));
        $recentFinalFailures = $this->recentFinalFailures($from, $to, $direction);
        $securityFailures = $this->securityFailures($from, $to);
        $warnings = [];

        if ($staleProcessing > 0) {
            $warnings[] = "stale_processing_messages={$staleProcessing}";
        }

        if ($dueRetry > 0) {
            $warnings[] = "due_retry_messages={$dueRetry}";
        }

        if ($openDeadLetters > 0) {
            $warnings[] = "open_dead_letters={$openDeadLetters}";
        }

        if ($recentFinalFailures > 0) {
            $warnings[] = "recent_final_failures={$recentFinalFailures}";
        }

        if ($securityFailures > 0) {
            $warnings[] = "security_failures={$securityFailures}";
        }

        return [
            'ok' => $warnings === [],
            'stale_processing_messages' => $staleProcessing,
            'due_retry_messages' => $dueRetry,
            'open_dead_letters' => $openDeadLetters,
            'recent_final_failures' => $recentFinalFailures,
            'security_failures' => $securityFailures,
            'warnings' => $warnings,
        ];
    }

    public function staleProcessingMessages(string $direction = 'all'): int
    {
        if (! $this->messagesTableExists()) {
            return 0;
        }

        $cutoff = now()->subMinutes((int) config('talkto.observability.health.stale_processing_minutes', 15));

        return $this->messageModelClass()::query()
            ->where('overall_status', 'processing')
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('processing_started_at', '<=', $cutoff)
                    ->orWhere('updated_at', '<=', $cutoff);
            })
            ->when($direction !== 'all', fn (Builder $query) => $query->where('direction', $direction))
            ->count();
    }

    public function dueRetryBacklog(string $direction = 'all'): int
    {
        $graceCutoff = now()->subMinutes((int) config('talkto.observability.health.due_retry_grace_minutes', 5));

        return $this->metrics->dueRetryMessages($direction, $graceCutoff);
    }

    public function recentFinalFailures(CarbonInterface $from, CarbonInterface $to, string $direction = 'all'): int
    {
        if (! $this->messagesTableExists()) {
            return 0;
        }

        return $this->messageModelClass()::query()
            ->where('overall_status', 'failed_final')
            ->whereBetween('created_at', [$from, $to])
            ->when($direction !== 'all', fn (Builder $query) => $query->where('direction', $direction))
            ->count();
    }

    public function securityFailures(CarbonInterface $from, CarbonInterface $to): int
    {
        if (! $this->eventsTableExists()) {
            return 0;
        }

        return $this->eventModelClass()::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('event_type', [
                'security_signature_failed',
                'security_payload_hash_failed',
                'security_timestamp_expired',
                'security_unsupported_signature_version',
            ])
            ->count();
    }

    private function messagesTableExists(): bool
    {
        return $this->tableExists($this->messageModelClass());
    }

    private function eventsTableExists(): bool
    {
        return $this->tableExists($this->eventModelClass());
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

    private function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }
}
