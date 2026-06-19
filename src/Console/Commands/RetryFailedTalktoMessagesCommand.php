<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;

class RetryFailedTalktoMessagesCommand extends Command
{
    protected $signature = 'talkto:retry-failed
        {--direction=all : incoming, outgoing, or all}
        {--limit=100 : Maximum messages to scan}
        {--dry-run : Show eligible messages without dispatching jobs}';

    protected $description = 'Dispatch due Talkto messages scheduled for retry.';

    public function handle(TalktoRetryPolicy $retryPolicy): int
    {
        $direction = (string) $this->option('direction');

        if (! in_array($direction, ['incoming', 'outgoing', 'all'], true)) {
            $this->error('Invalid direction. Use incoming, outgoing, or all.');

            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            $this->error('Invalid --limit. Use a value between 1 and 1000.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $messageClass = $this->messageModelClass();
        $query = $messageClass::query()
            ->whereIn('overall_status', $retryPolicy->retryableStatuses())
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->when($direction !== 'all', fn ($query) => $query->where('direction', $direction))
            ->orderBy('next_retry_at')
            ->limit($limit);

        $scanned = 0;
        $eligible = 0;
        $dispatched = 0;
        $skipped = 0;
        $skipReasons = [];

        foreach ($query->get() as $message) {
            $scanned++;
            $decision = $retryPolicy->decisionFor($message);

            if (! $decision->retryable || ! $retryPolicy->isDue($message)) {
                $skipped++;
                $reason = ! $retryPolicy->isDue($message) && $decision->reason === 'eligible'
                    ? 'not_due'
                    : $decision->reason;
                $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;

                continue;
            }

            $eligible++;

            if (! $dryRun) {
                $this->dispatchRetryJob($message);
                $this->recordRetryDispatched($message, $decision->toArray());
                $dispatched++;
            }
        }

        $dryRunValue = $dryRun ? 'true' : 'false';

        $skipSummary = json_encode($skipReasons, JSON_UNESCAPED_SLASHES) ?: '{}';

        $this->line("scanned={$scanned} eligible={$eligible} dispatched={$dispatched} skipped={$skipped} dry_run={$dryRunValue} direction={$direction} skip_reasons={$skipSummary}");

        return self::SUCCESS;
    }

    private function dispatchRetryJob(TalktoMessage $message): void
    {
        if ($message->direction === 'outgoing') {
            $jobClass = $this->sendJobClass();
            $jobClass::dispatch($message->id);

            return;
        }

        if ($message->direction === 'incoming') {
            $jobClass = $this->processIncomingJobClass();
            $jobClass::dispatch($message->id);
        }
    }

    private function recordRetryDispatched(TalktoMessage $message, array $decision): void
    {
        $eventClass = $this->eventModelClass();

        $eventClass::query()->create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => 'retry_dispatched',
            'old_status' => $message->overall_status,
            'new_status' => $message->overall_status,
            'meta' => [
                'direction' => $message->direction,
                'retry_count' => (int) ($message->retry_count ?? 0),
                'max_attempts' => $decision['max_attempts'] ?? null,
                'backoff_seconds' => $decision['backoff_seconds'] ?? null,
                'next_retry_at' => optional($message->next_retry_at)->toIso8601String(),
                'reason' => $decision['reason'] ?? null,
            ],
        ]);
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

    private function sendJobClass(): string
    {
        $class = config('talkto.jobs.send_message', SendTalktoMessage::class);

        return is_string($class) && is_a($class, SendTalktoMessage::class, true)
            ? $class
            : SendTalktoMessage::class;
    }

    private function processIncomingJobClass(): string
    {
        $class = config('talkto.jobs.process_incoming', ProcessIncomingTalktoMessage::class);

        return is_string($class) && is_a($class, ProcessIncomingTalktoMessage::class, true)
            ? $class
            : ProcessIncomingTalktoMessage::class;
    }
}
