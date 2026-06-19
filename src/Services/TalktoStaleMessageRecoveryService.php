<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

class TalktoStaleMessageRecoveryService
{
    public function __construct(
        private readonly TalktoRetryPolicy $retryPolicy,
        private readonly TalktoDeadLetterQueue $deadLetterQueue,
    ) {}

    public function recover(?string $direction, int $olderThanMinutes, int $limit, bool $dryRun): array
    {
        $messages = $this->candidateQuery($direction, $olderThanMinutes, $limit)->get();
        $summary = [
            'candidates' => $messages->count(),
            'recovered' => 0,
            'failed' => 0,
            'skipped' => 0,
            'dispatched' => 0,
            'dry_run' => $dryRun,
            'messages' => [],
        ];

        foreach ($messages as $message) {
            if ($dryRun) {
                $summary['messages'][] = $this->messageSummary($message, 'candidate');

                continue;
            }

            $result = $this->recoverMessage($message, $olderThanMinutes);
            $summary['messages'][] = $result;

            if ($result['status'] === 'recovered') {
                $summary['recovered']++;
                $this->dispatchMessageJob($result['id'], $result['direction']);
                $summary['dispatched']++;

                continue;
            }

            if ($result['status'] === 'failed') {
                $summary['failed']++;

                continue;
            }

            $summary['skipped']++;
        }

        return $summary;
    }

    private function candidateQuery(?string $direction, int $olderThanMinutes, int $limit): mixed
    {
        $messageClass = $this->messageModelClass();
        $cutoff = now()->subMinutes($olderThanMinutes);

        return $messageClass::query()
            ->whereNotNull('locked_at')
            ->where('locked_at', '<=', $cutoff)
            ->when($direction !== null, fn ($query) => $query->where('direction', $direction))
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('direction', 'outgoing')
                        ->where('overall_status', 'sending')
                        ->where('transport_status', 'sending');
                })->orWhere(function ($query): void {
                    $query->where('direction', 'incoming')
                        ->where('overall_status', 'processing')
                        ->where('destination_action_status', 'processing');
                });
            })
            ->orderBy('locked_at')
            ->limit($limit);
    }

    private function recoverMessage(TalktoMessage $message, int $olderThanMinutes): array
    {
        $messageClass = $this->messageModelClass();

        return DB::transaction(function () use ($messageClass, $message, $olderThanMinutes): array {
            $locked = $messageClass::query()
                ->whereKey($message->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof TalktoMessage || ! $this->isStillStale($locked, $olderThanMinutes)) {
                return $this->messageSummary($message, 'skipped', 'not_stale');
            }

            $oldStatus = (string) $locked->overall_status;
            $oldLockedAt = $locked->locked_at?->toIso8601String();
            $oldLockedBy = $locked->locked_by;

            if (! $this->hasAttemptRemaining($locked)) {
                $statusColumn = $locked->direction === 'outgoing'
                    ? 'transport_status'
                    : 'destination_action_status';

                $this->retryPolicy->markFinalFailure(
                    $locked,
                    $statusColumn,
                    'Stale Talkto lock recovered after attempts were exhausted.'
                );

                $locked = $locked->fresh() ?? $locked;
                $this->recordEvent($locked, 'stale_lock_recovery_exhausted', $oldStatus, (string) $locked->overall_status, [
                    'direction' => $locked->direction,
                    'locked_at' => $oldLockedAt,
                    'locked_by' => $oldLockedBy,
                    'older_than_minutes' => $olderThanMinutes,
                    'attempts' => (int) ($locked->attempts ?? 0),
                    'max_attempts' => $this->retryPolicy->maxAttempts($locked),
                ]);

                if ($this->deadLetterQueue->autoStoreEnabled()) {
                    $this->deadLetterQueue->store($locked, $locked->last_error);
                }

                return $this->messageSummary($locked, 'failed', 'attempts_exhausted');
            }

            $attributes = [
                'next_retry_at' => now(),
                'next_attempt_at' => now(),
                'locked_at' => null,
                'locked_by' => null,
            ];

            if ($locked->direction === 'outgoing') {
                $attributes['transport_status'] = 'pending';
                $attributes['overall_status'] = 'waiting_to_send';
            } else {
                $attributes['destination_action_status'] = 'queued';
                $attributes['overall_status'] = 'queued';
            }

            $locked->forceFill($attributes)->save();
            $locked = $locked->fresh() ?? $locked;

            $this->recordEvent($locked, 'stale_lock_recovered', $oldStatus, (string) $locked->overall_status, [
                'direction' => $locked->direction,
                'locked_at' => $oldLockedAt,
                'locked_by' => $oldLockedBy,
                'older_than_minutes' => $olderThanMinutes,
                'attempts' => (int) ($locked->attempts ?? 0),
                'max_attempts' => $this->retryPolicy->maxAttempts($locked),
            ]);

            return $this->messageSummary($locked, 'recovered');
        });
    }

    private function isStillStale(TalktoMessage $message, int $olderThanMinutes): bool
    {
        if ($message->locked_at === null || $message->locked_at->greaterThan(now()->subMinutes($olderThanMinutes))) {
            return false;
        }

        if ($message->direction === 'outgoing') {
            return $message->overall_status === 'sending' && $message->transport_status === 'sending';
        }

        if ($message->direction === 'incoming') {
            return $message->overall_status === 'processing' && $message->destination_action_status === 'processing';
        }

        return false;
    }

    private function hasAttemptRemaining(TalktoMessage $message): bool
    {
        return ((int) ($message->attempts ?? 0)) < $this->retryPolicy->maxAttempts($message);
    }

    private function dispatchMessageJob(int $messageId, string $direction): void
    {
        if ($direction === 'outgoing') {
            $jobClass = $this->sendJobClass();
            $jobClass::dispatch($messageId);

            return;
        }

        if ($direction === 'incoming') {
            $jobClass = $this->processIncomingJobClass();
            $jobClass::dispatch($messageId);
        }
    }

    private function recordEvent(TalktoMessage $message, string $eventType, string $oldStatus, string $newStatus, array $meta): void
    {
        $eventClass = $this->eventModelClass();

        $eventClass::query()->create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => $eventType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'meta' => $meta,
        ]);
    }

    private function messageSummary(TalktoMessage $message, string $status, ?string $reason = null): array
    {
        return array_filter([
            'id' => $message->id,
            'message_id' => $message->message_id,
            'direction' => $message->direction,
            'status' => $status,
            'reason' => $reason,
            'overall_status' => $message->overall_status,
            'locked_at' => $message->locked_at?->toIso8601String(),
        ], static fn (mixed $value): bool => $value !== null);
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
