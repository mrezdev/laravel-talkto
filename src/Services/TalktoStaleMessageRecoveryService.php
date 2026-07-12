<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchClaim;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Mrezdev\LaravelTalkto\Support\TalktoModelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use Throwable;

/**
 * @internal Runtime service behind stale message recovery.
 */
class TalktoStaleMessageRecoveryService
{
    public function __construct(
        private readonly TalktoRetryPolicy $retryPolicy,
        private readonly TalktoDeadLetterQueue $deadLetterQueue,
        private readonly TalktoDispatchClaimingService $dispatchClaims,
    ) {}

    public function recover(?string $direction, int $olderThanMinutes, int $limit, bool $dryRun): array
    {
        $messages = $this->candidateQuery($direction, $olderThanMinutes, $limit)->get();
        $summary = [
            'candidates' => $messages->count(),
            'recovered' => 0,
            'stale_processing_recovered' => 0,
            'orphaned_dispatch_claims_recovered' => 0,
            'failed' => 0,
            'skipped' => 0,
            'dispatch_claims_skipped' => 0,
            'claim_changed' => 0,
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
            $claim = $result['_claim'] ?? null;
            unset($result['_claim']);

            if ($result['status'] === 'recovered') {
                try {
                    TalktoDispatchTestHooks::fire('dispatch.before_queue', [
                        'operation' => 'stale-recovery',
                        'message_db_id' => $result['id'],
                        'message_id' => $result['message_id'] ?? null,
                        'direction' => $result['direction'],
                        'claim_id' => $claim instanceof TalktoDispatchClaim ? $claim->claimId : null,
                        'recovery_type' => $result['recovery_type'] ?? null,
                    ]);

                    $this->dispatchMessageJob($result['id'], $result['direction']);
                    $summary['recovered']++;
                    if (($result['recovery_type'] ?? null) === 'orphaned_dispatch_claim') {
                        $summary['orphaned_dispatch_claims_recovered']++;
                    } else {
                        $summary['stale_processing_recovered']++;
                    }
                    $summary['dispatched']++;
                } catch (Throwable $throwable) {
                    if ($claim instanceof TalktoDispatchClaim) {
                        $this->dispatchClaims->compensateMessageClaim($claim);
                        $this->recordDispatchFailure($claim, $throwable);
                    }

                    $result['status'] = 'failed';
                    $result['reason'] = 'dispatch_failed';
                    $summary['failed']++;
                }

                $summary['messages'][] = $result;

                continue;
            }

            $summary['messages'][] = $result;

            if ($result['status'] === 'failed') {
                $summary['failed']++;

                continue;
            }

            $summary['skipped']++;

            if (($result['reason'] ?? null) === 'not_stale' && str_starts_with((string) ($result['locked_by'] ?? ''), 'dispatch-claim:')) {
                $summary['dispatch_claims_skipped']++;
            }

            if (($result['reason'] ?? null) === 'not_stale') {
                $summary['claim_changed']++;
            }
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
                    $query->where('direction', TalktoMessageDirection::Outgoing->value)
                        ->where('overall_status', TalktoMessageStatus::Sending->value)
                        ->where('transport_status', TalktoMessageStatus::Sending->value);
                })->orWhere(function ($query): void {
                    $query->where('direction', TalktoMessageDirection::Incoming->value)
                        ->where('overall_status', TalktoMessageStatus::Processing->value)
                        ->where('destination_action_status', TalktoMessageStatus::Processing->value);
                })->orWhere(function ($query): void {
                    $query->where('locked_by', 'like', 'dispatch-claim:%')
                        ->where(function ($query): void {
                            $query->where(function ($query): void {
                                $query->where('direction', TalktoMessageDirection::Outgoing->value)
                                    ->where('overall_status', TalktoMessageStatus::WaitingToSend->value)
                                    ->where('transport_status', TalktoMessageStatus::Pending->value);
                            })->orWhere(function ($query): void {
                                $query->where('direction', TalktoMessageDirection::Incoming->value)
                                    ->where('overall_status', TalktoMessageStatus::Queued->value)
                                    ->where('destination_action_status', TalktoMessageStatus::Queued->value);
                            });
                        });
                });
            })
            ->orderBy('locked_at')
            ->limit($limit);
    }

    private function recoverMessage(TalktoMessage $message, int $olderThanMinutes): array
    {
        TalktoDispatchTestHooks::fire('recovery.before_claim', [
            'operation' => 'stale-recovery',
            'message_db_id' => $message->id,
            'message_id' => $message->message_id,
            'direction' => $message->direction,
            'locked_by' => $message->locked_by,
        ]);

        $claim = $this->dispatchClaims->claimStaleRecovery($message, $olderThanMinutes, 'stale-recovery');

        if ($claim->claimed && $claim->message) {
            $recoveryType = (string) ($claim->meta['recovery_type'] ?? 'stale_processing_lock');
            $eventType = $recoveryType === 'orphaned_dispatch_claim'
                ? 'orphaned_dispatch_claim_recovered'
                : 'stale_lock_recovered';

            $this->recordEvent($claim->message, $eventType, (string) $claim->previousMessageAttributes['overall_status'], (string) $claim->message->overall_status, [
                'direction' => $claim->message->direction,
                'locked_at' => $this->dateToIso($claim->previousMessageAttributes['locked_at'] ?? null),
                'locked_by' => $claim->previousMessageAttributes['locked_by'] ?? null,
                'older_than_minutes' => $olderThanMinutes,
                'attempts' => (int) ($claim->message->attempts ?? 0),
                'max_attempts' => $this->retryPolicy->maxAttempts($claim->message),
                'claim_id' => $claim->claimId,
                'recovery_type' => $recoveryType,
            ]);

            $summary = $this->messageSummary($claim->message, 'recovered');
            $summary['recovery_type'] = $recoveryType;
            $summary['_claim'] = $claim;

            return $summary;
        }

        if ($claim->status === 'attempts_exhausted' && $claim->message) {
            return $this->markStaleAttemptsExhausted($claim->message, $olderThanMinutes);
        }

        return $this->messageSummary($claim->message ?? $message, 'skipped', $claim->status);
    }

    private function markStaleAttemptsExhausted(TalktoMessage $message, int $olderThanMinutes): array
    {
        $messageClass = $this->messageModelClass();

        TalktoModelConnection::assertSameConnection($message, $this->eventModelClass());

        return TalktoModelConnection::transaction($message, function () use ($messageClass, $message, $olderThanMinutes): array {
            $locked = $messageClass::query()
                ->whereKey($message->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof TalktoMessage || ! $this->isStillStale($locked, $olderThanMinutes)) {
                return $this->messageSummary($message, 'skipped', 'not_stale');
            }

            if ($this->hasAttemptRemaining($locked)) {
                return $this->messageSummary($locked, 'skipped', 'attempts_available');
            }

            $oldStatus = (string) $locked->overall_status;
            $oldLockedAt = $locked->locked_at?->toIso8601String();
            $oldLockedBy = $locked->locked_by;
            $statusColumn = $locked->direction === TalktoMessageDirection::Outgoing->value
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
                TalktoModelConnection::assertSameConnection($locked, $this->deadLetterModelClass(), $this->eventModelClass());

                $this->deadLetterQueue->store($locked, $locked->last_error);
            }

            return $this->messageSummary($locked, 'failed', 'attempts_exhausted');
        });
    }

    private function isStillStale(TalktoMessage $message, int $olderThanMinutes): bool
    {
        if ($message->locked_at === null || $message->locked_at->greaterThan(now()->subMinutes($olderThanMinutes))) {
            return false;
        }

        if ($message->direction === TalktoMessageDirection::Outgoing->value) {
            return $message->overall_status === TalktoMessageStatus::Sending->value && $message->transport_status === TalktoMessageStatus::Sending->value;
        }

        if ($message->direction === TalktoMessageDirection::Incoming->value) {
            return $message->overall_status === TalktoMessageStatus::Processing->value && $message->destination_action_status === TalktoMessageStatus::Processing->value;
        }

        return false;
    }

    private function hasAttemptRemaining(TalktoMessage $message): bool
    {
        return ((int) ($message->attempts ?? 0)) < $this->retryPolicy->maxAttempts($message);
    }

    private function dispatchMessageJob(int $messageId, string $direction): void
    {
        if ($direction === TalktoMessageDirection::Outgoing->value) {
            $jobClass = $this->sendJobClass();
            $jobClass::dispatch($messageId);

            return;
        }

        if ($direction === TalktoMessageDirection::Incoming->value) {
            $jobClass = $this->processIncomingJobClass();
            $jobClass::dispatch($messageId);
        }
    }

    private function recordEvent(TalktoMessage $message, string $eventType, string $oldStatus, string $newStatus, array $meta): void
    {
        $eventClass = $this->eventModelClass();

        TalktoModelConnection::assertSameConnection($message, $eventClass);

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

    private function recordDispatchFailure(TalktoDispatchClaim $claim, Throwable $throwable): void
    {
        if (! $claim->message) {
            return;
        }

        $message = $claim->message->fresh() ?? $claim->message;

        $this->recordEvent($message, 'stale_lock_recovery_dispatch_failed', (string) ($claim->message->overall_status ?? $message->overall_status), (string) $message->overall_status, [
            'direction' => $message->direction,
            'claim_id' => $claim->claimId,
            'exception_class' => $throwable::class,
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
            'locked_by' => $message->locked_by,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function dateToIso(mixed $value): ?string
    {
        return is_object($value) && method_exists($value, 'toIso8601String')
            ? $value->toIso8601String()
            : null;
    }

    private function messageModelClass(): string
    {
        return app(TalktoModelResolver::class)->message();
    }

    private function eventModelClass(): string
    {
        return app(TalktoModelResolver::class)->event();
    }

    private function deadLetterModelClass(): string
    {
        return app(TalktoModelResolver::class)->deadLetter();
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
