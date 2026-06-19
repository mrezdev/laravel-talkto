<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoHealthChecker;
use Mrezdev\LaravelTalkto\Services\TalktoMetricsCollector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ReportTalktoMessagesCommand extends Command
{
    protected $signature = 'talkto:report
        {--hours= : Window size in hours when --from is not provided}
        {--from= : Window start, for example "2026-06-19 00:00:00"}
        {--to= : Window end, for example "2026-06-19 23:59:59"}
        {--json : Output JSON}
        {--direction=all : incoming, outgoing, or all}
        {--limit= : Maximum recent failures/events to show}';

    protected $description = 'Show read-only Talkto message metrics, health warnings, and recent failures.';

    public function handle(TalktoMetricsCollector $metrics, TalktoHealthChecker $health): int
    {
        $direction = (string) $this->option('direction');

        if (! in_array($direction, ['incoming', 'outgoing', 'all'], true)) {
            $this->error('Invalid direction. Use incoming, outgoing, or all.');

            return self::FAILURE;
        }

        try {
            [$from, $to] = $this->window();
        } catch (Throwable $throwable) {
            $this->error('Invalid date window: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $limit = max(1, (int) ($this->option('limit') ?: config('talkto.observability.report.default_limit', 20)));
        $snapshot = $metrics->collect($from, $to, $direction);
        $healthReport = $health->check($from, $to, $direction);
        $payload = [
            'metrics' => $snapshot->toArray(),
            'attempt_status_counts' => $metrics->attemptStatusCounts($from, $to),
            'event_type_counts' => $metrics->eventTypeCounts($from, $to),
            'health' => $healthReport,
            'recent_failures' => $this->recentFailures($from, $to, $direction, $limit),
            'recent_events' => $this->recentEvents($from, $to, $limit),
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Talkto report');
        $this->line('window='.$from->toDateTimeString().'..'.$to->toDateTimeString().' direction='.$direction);
        $this->line(
            'messages='.$snapshot->totalMessages
            .' incoming='.$snapshot->incomingMessages
            .' outgoing='.$snapshot->outgoingMessages
            .' success_rate='.$snapshot->successRate.'%'
            .' failure_rate='.$snapshot->failureRate.'%'
        );
        $this->line(
            'retryable='.$snapshot->retryableMessages
            .' due_retry='.$snapshot->dueRetryMessages
            .' final_failed='.$snapshot->finalFailedMessages
            .' processing='.$snapshot->processingMessages
            .' open_dead_letters='.$snapshot->openDeadLetters
        );
        $this->line('health='.($healthReport['ok'] ? 'ok' : 'warning'));
        $this->line('Use talkto:trace <message_id> for a message-level timeline.');

        foreach ($healthReport['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($snapshot->statusCounts !== []) {
            $this->table(['status', 'count'], $this->rows($snapshot->statusCounts));
        }

        if ($payload['attempt_status_counts'] !== []) {
            $this->table(['attempt_status', 'count'], $this->rows($payload['attempt_status_counts']));
        }

        if ($payload['event_type_counts'] !== []) {
            $this->table(['event_type', 'count'], $this->rows($payload['event_type_counts']));
        }

        if ($payload['recent_failures'] !== []) {
            $this->table(['message_id', 'direction', 'status', 'last_error'], array_map(
                fn (array $failure): array => [
                    $failure['message_id'],
                    $failure['direction'],
                    $failure['overall_status'],
                    $failure['last_error'],
                ],
                $payload['recent_failures']
            ));
        }

        return self::SUCCESS;
    }

    private function window(): array
    {
        $to = $this->option('to') !== null
            ? CarbonImmutable::parse((string) $this->option('to'))
            : CarbonImmutable::now();

        $from = $this->option('from') !== null
            ? CarbonImmutable::parse((string) $this->option('from'))
            : $to->subHours((int) ($this->option('hours') ?: config('talkto.observability.report.default_window_hours', 24)));

        if ($from->greaterThan($to)) {
            throw new \InvalidArgumentException('from must be before to');
        }

        return [$from, $to];
    }

    private function recentFailures(CarbonInterface $from, CarbonInterface $to, string $direction, int $limit): array
    {
        if (! $this->messagesTableExists()) {
            return [];
        }

        return $this->messageModelClass()::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('overall_status', ['failed_retryable', 'failed_final', 'failed'])
            ->when($direction !== 'all', fn (Builder $query) => $query->where('direction', $direction))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['message_id', 'direction', 'overall_status', 'last_error', 'created_at'])
            ->map(fn (TalktoMessage $message): array => [
                'message_id' => $message->message_id,
                'direction' => $message->direction,
                'overall_status' => $message->overall_status,
                'last_error' => $message->last_error,
                'created_at' => optional($message->created_at)->toIso8601String(),
            ])
            ->all();
    }

    private function recentEvents(CarbonInterface $from, CarbonInterface $to, int $limit): array
    {
        if (! $this->eventsTableExists()) {
            return [];
        }

        return $this->eventModelClass()::query()
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['message_id', 'event_type', 'old_status', 'new_status', 'created_at'])
            ->map(fn (TalktoEvent $event): array => [
                'message_id' => $event->message_id,
                'event_type' => $event->event_type,
                'old_status' => $event->old_status,
                'new_status' => $event->new_status,
                'created_at' => optional($event->created_at)->toIso8601String(),
            ])
            ->all();
    }

    private function rows(array $counts): array
    {
        return array_map(
            fn (string $key, int $count): array => [$key, $count],
            array_keys($counts),
            array_values($counts)
        );
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
