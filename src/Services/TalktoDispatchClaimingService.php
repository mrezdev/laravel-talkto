<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Support\Str;
use Mrezdev\LaravelTalkto\Enums\TalktoDeadLetterStatus;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchClaim;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Mrezdev\LaravelTalkto\Support\TalktoModelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use Throwable;

/**
 * @internal Centralized durable claiming for dispatching existing Talkto rows.
 */
class TalktoDispatchClaimingService
{
    private const CLAIM_PREFIX = 'dispatch-claim:';

    public function __construct(
        private readonly TalktoRetryPolicy $retryPolicy,
        private readonly TalktoDeadLetterQueue $deadLetterQueue,
        private readonly TalktoCurrentServiceGuard $currentServiceGuard,
    ) {}

    public function claimRetry(TalktoMessage $message, string $operation, bool $ignoreDue = false): TalktoDispatchClaim
    {
        $messageClass = $this->messageModelClass();

        TalktoModelConnection::assertSameConnection($messageClass, $this->eventModelClass());

        return TalktoModelConnection::transaction($messageClass, function () use ($messageClass, $message, $operation, $ignoreDue): TalktoDispatchClaim {
            $locked = $messageClass::query()
                ->whereKey($message->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof TalktoMessage) {
                return TalktoDispatchClaim::skipped($operation, 'missing_message');
            }

            if (! $this->hasSupportedDirection($locked)) {
                return TalktoDispatchClaim::skipped($operation, 'unsupported_direction', $locked);
            }

            if (! $this->currentServiceGuard->allowsProcessing($locked)) {
                return TalktoDispatchClaim::skipped($operation, 'wrong_service', $locked);
            }

            $decision = $this->retryPolicy->decisionFor($locked)->toArray();

            $retryable = (bool) ($decision['retryable'] ?? false);
            $manuallyRetryable = $ignoreDue
                && ($decision['reason'] ?? null) === 'not_due'
                && $this->retryPolicy->canRetry($locked);

            if (! $retryable && ! $manuallyRetryable) {
                return TalktoDispatchClaim::skipped($operation, (string) ($decision['reason'] ?? 'not_retryable'), $locked, null, $decision);
            }

            if (! $ignoreDue && ! $this->retryPolicy->isDue($locked)) {
                return TalktoDispatchClaim::skipped($operation, 'not_due', $locked, null, $decision);
            }

            $previous = $this->messageDispatchSnapshot($locked);
            $claimId = $this->newClaimId($operation);
            $now = now();

            $locked->forceFill($this->messageDispatchAttributes($locked, $claimId, $now, $now, $now))->save();
            $locked = $locked->fresh() ?? $locked;

            return TalktoDispatchClaim::claimed($operation, $locked, $claimId, $previous, $decision);
        });
    }

    public function claimDeadLetterForReprocess(TalktoDeadLetter $deadLetter, bool $force, string $operation): TalktoDispatchClaim
    {
        $deadLetterClass = $this->deadLetterModelClass();
        $messageClass = $this->messageModelClass();

        TalktoModelConnection::assertSameConnection($deadLetterClass, $messageClass, $this->eventModelClass());

        $candidateDeadLetter = $deadLetterClass::query()
            ->whereKey($deadLetter->getKey())
            ->first();

        if (! $candidateDeadLetter instanceof TalktoDeadLetter) {
            return TalktoDispatchClaim::skipped($operation, 'missing_dead_letter');
        }

        return TalktoModelConnection::transaction($messageClass, function () use ($deadLetterClass, $messageClass, $candidateDeadLetter, $force, $operation): TalktoDispatchClaim {
            $message = $this->findAndLockOriginalMessage($candidateDeadLetter, $messageClass, 'claim_for_reprocess');

            $lockedDeadLetter = $deadLetterClass::query()
                ->whereKey($candidateDeadLetter->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedDeadLetter instanceof TalktoDeadLetter) {
                return TalktoDispatchClaim::skipped($operation, 'missing_dead_letter');
            }

            $this->fireDeadLetterLockedHook($lockedDeadLetter, 'claim_for_reprocess');

            if (! $this->deadLetterQueue->canReprocess($lockedDeadLetter, $force)) {
                return TalktoDispatchClaim::skipped($operation, 'dead_letter_not_reprocessable', null, $lockedDeadLetter);
            }

            if (! $message instanceof TalktoMessage) {
                return TalktoDispatchClaim::skipped($operation, 'missing_original', null, $lockedDeadLetter);
            }

            if (! $this->currentServiceGuard->allowsProcessing($message)) {
                return TalktoDispatchClaim::skipped($operation, 'wrong_service', $message, $lockedDeadLetter);
            }

            if (in_array($message->overall_status, [TalktoMessageStatus::Succeeded->value, TalktoMessageStatus::Completed->value], true)) {
                return TalktoDispatchClaim::skipped($operation, 'terminal_success', $message, $lockedDeadLetter);
            }

            if (! $this->hasSupportedDirection($message)) {
                return TalktoDispatchClaim::skipped($operation, 'unsupported_direction', $message, $lockedDeadLetter);
            }

            if ($this->hasActiveDispatchBlocker($message)) {
                return TalktoDispatchClaim::skipped($operation, $this->activeDispatchBlockerStatus($message), $message, $lockedDeadLetter);
            }

            $previousMessage = $this->messageDispatchSnapshot($message);
            $previousDeadLetter = $this->deadLetterSnapshot($lockedDeadLetter);
            $claimId = $this->newClaimId($operation);
            $now = now();

            $message->forceFill($this->messageDispatchAttributes($message, $claimId, null, null, $now))->save();

            $lockedDeadLetter->forceFill([
                'status' => TalktoDeadLetterStatus::Reprocessing->value,
                'reprocess_count' => ((int) $lockedDeadLetter->reprocess_count) + 1,
                'reprocessed_at' => $now,
            ])->save();

            $lockedDeadLetter = $lockedDeadLetter->fresh() ?? $lockedDeadLetter;
            $message = $message->fresh() ?? $message;

            return TalktoDispatchClaim::claimed(
                $operation,
                $message,
                $claimId,
                $previousMessage,
                [],
                $lockedDeadLetter,
                $previousDeadLetter,
            );
        });
    }

    public function claimStaleRecovery(TalktoMessage $message, int $olderThanMinutes, string $operation): TalktoDispatchClaim
    {
        $messageClass = $this->messageModelClass();

        TalktoModelConnection::assertSameConnection($messageClass, $this->eventModelClass());

        return TalktoModelConnection::transaction($messageClass, function () use ($messageClass, $message, $olderThanMinutes, $operation): TalktoDispatchClaim {
            $locked = $messageClass::query()
                ->whereKey($message->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof TalktoMessage) {
                return TalktoDispatchClaim::skipped($operation, 'missing_message');
            }

            if (! $this->currentServiceGuard->allowsProcessing($locked)) {
                return TalktoDispatchClaim::skipped($operation, 'wrong_service', $locked);
            }

            if (! $this->isStillStale($locked, $olderThanMinutes)) {
                return TalktoDispatchClaim::skipped($operation, 'not_stale', $locked);
            }

            $isDispatchClaim = $this->isStaleDispatchClaim($locked, $olderThanMinutes);

            if (! $isDispatchClaim && ! $this->hasAttemptRemaining($locked)) {
                return TalktoDispatchClaim::skipped($operation, 'attempts_exhausted', $locked);
            }

            $previous = $this->messageDispatchSnapshot($locked);
            $claimId = $this->newClaimId($operation);
            $now = now();
            $nextRetryAt = $isDispatchClaim ? $locked->next_retry_at : $now;
            $nextAttemptAt = $isDispatchClaim ? $locked->next_attempt_at : $now;
            $meta = $isDispatchClaim
                ? [
                    'recovery_type' => 'orphaned_dispatch_claim',
                    'previous_claim_id' => (string) $locked->locked_by,
                ]
                : [
                    'recovery_type' => 'stale_processing_lock',
                ];

            $locked->forceFill($this->messageDispatchAttributes($locked, $claimId, $nextRetryAt, $nextAttemptAt, $now))->save();
            $locked = $locked->fresh() ?? $locked;

            return TalktoDispatchClaim::claimed($operation, $locked, $claimId, $previous, [], null, [], $meta);
        });
    }

    public function compensateMessageClaim(TalktoDispatchClaim $claim): bool
    {
        if (! $claim->claimed || ! $claim->message || ! $claim->claimId || $claim->previousMessageAttributes === []) {
            return false;
        }

        $messageClass = $this->messageModelClass();

        return TalktoModelConnection::transaction($messageClass, function () use ($messageClass, $claim): bool {
            $message = $messageClass::query()
                ->whereKey($claim->message->getKey())
                ->lockForUpdate()
                ->first();

            if (! $message instanceof TalktoMessage || ! $this->isExpectedClaimState($message, $claim)) {
                return false;
            }

            $message->forceFill($claim->previousMessageAttributes)->save();

            return true;
        });
    }

    public function compensateDeadLetterClaim(TalktoDispatchClaim $claim, ?string $reason = null, ?Throwable $throwable = null): bool
    {
        if (! $claim->claimed || ! $claim->deadLetter || ! $claim->message || ! $claim->claimId) {
            return false;
        }

        $deadLetterClass = $this->deadLetterModelClass();
        $messageClass = $this->messageModelClass();

        TalktoModelConnection::assertSameConnection($deadLetterClass, $messageClass, $this->eventModelClass());

        return TalktoModelConnection::transaction($messageClass, function () use ($deadLetterClass, $messageClass, $claim, $reason, $throwable): bool {
            $message = $messageClass::query()
                ->whereKey($claim->message->getKey())
                ->lockForUpdate()
                ->first();

            if ($message instanceof TalktoMessage) {
                $this->fireMessageLockedHook($message, 'compensate_claim');
            }

            if (! $message instanceof TalktoMessage || ! $this->isExpectedClaimState($message, $claim)) {
                return false;
            }

            $deadLetter = $deadLetterClass::query()
                ->whereKey($claim->deadLetter->getKey())
                ->lockForUpdate()
                ->first();

            if ($deadLetter instanceof TalktoDeadLetter) {
                $this->fireDeadLetterLockedHook($deadLetter, 'compensate_claim');
            }

            if (! $this->isExpectedDeadLetterClaimState($deadLetter, $claim)) {
                return false;
            }

            if ($claim->previousMessageAttributes !== []) {
                $message->forceFill($claim->previousMessageAttributes)->save();
            }

            $deadLetter->forceFill([
                'status' => TalktoDeadLetterStatus::FailedReprocess->value,
                'failure_reason' => $this->excerpt($reason ?: $deadLetter->failure_reason),
                'exception_class' => $throwable ? $throwable::class : $deadLetter->exception_class,
                'exception_message' => $throwable ? $this->excerpt($throwable->getMessage()) : $deadLetter->exception_message,
                'reprocessed_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $eventMeta
     */
    public function compensateCallbackQueueDispatchFailure(
        TalktoMessage $originalMessage,
        TalktoMessage $callbackMessage,
        string $claimId,
        array $eventMeta,
    ): bool {
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();

        TalktoModelConnection::assertSameConnection($originalMessage, $callbackMessage, $eventClass);

        return TalktoModelConnection::transaction($messageClass, function () use ($messageClass, $eventClass, $originalMessage, $callbackMessage, $claimId, $eventMeta): bool {
            $lockedCallback = $messageClass::query()
                ->whereKey($callbackMessage->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedCallback instanceof TalktoMessage || ! $this->isExpectedCallbackClaimState($lockedCallback, $claimId)) {
                return false;
            }

            TalktoDispatchTestHooks::fire('callback.compensation.before_event', [
                'callback_message_db_id' => $lockedCallback->id,
                'callback_message_id' => $lockedCallback->message_id,
                'original_message_db_id' => $originalMessage->id,
                'original_message_id' => $originalMessage->message_id,
                'claim_id' => $claimId,
            ]);

            $eventClass::query()->create([
                'talkto_message_id' => $originalMessage->id,
                'message_id' => (string) $originalMessage->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'result_callback_queue_failed',
                'old_status' => null,
                'new_status' => 'failed',
                'meta' => array_filter($eventMeta, fn (mixed $value): bool => $value !== null),
            ]);

            TalktoDispatchTestHooks::fire('callback.compensation.before_release', [
                'callback_message_db_id' => $lockedCallback->id,
                'callback_message_id' => $lockedCallback->message_id,
                'original_message_db_id' => $originalMessage->id,
                'original_message_id' => $originalMessage->message_id,
                'claim_id' => $claimId,
            ]);

            $lockedCallback->forceFill([
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            return true;
        });
    }

    public function releaseMessageClaim(TalktoMessage $message, string $claimId): bool
    {
        $messageClass = $this->messageModelClass();

        return TalktoModelConnection::transaction($messageClass, function () use ($messageClass, $message, $claimId): bool {
            $locked = $messageClass::query()
                ->whereKey($message->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof TalktoMessage || ! $this->isStillHeldByClaim($locked, $claimId)) {
                return false;
            }

            $locked->forceFill([
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            return true;
        });
    }

    public function newClaimId(string $operation): string
    {
        return self::CLAIM_PREFIX.Str::slug($operation, '-').':'.(string) Str::uuid();
    }

    private function messageDispatchAttributes(TalktoMessage $message, string $claimId, mixed $nextRetryAt, mixed $nextAttemptAt, mixed $claimAt): array
    {
        $attributes = [
            'next_retry_at' => $nextRetryAt,
            'next_attempt_at' => $nextAttemptAt,
            'locked_at' => $claimAt,
            'locked_by' => $claimId,
        ];

        if ($message->direction === TalktoMessageDirection::Outgoing->value) {
            $attributes['transport_status'] = TalktoMessageStatus::Pending->value;
            $attributes['overall_status'] = TalktoMessageStatus::WaitingToSend->value;
        }

        if ($message->direction === TalktoMessageDirection::Incoming->value) {
            $attributes['destination_action_status'] = TalktoMessageStatus::Queued->value;
            $attributes['overall_status'] = TalktoMessageStatus::Queued->value;
        }

        return $attributes;
    }

    private function messageDispatchSnapshot(TalktoMessage $message): array
    {
        return [
            'transport_status' => $message->transport_status,
            'destination_action_status' => $message->destination_action_status,
            'overall_status' => $message->overall_status,
            'next_retry_at' => $message->next_retry_at,
            'next_attempt_at' => $message->next_attempt_at,
            'locked_at' => $message->locked_at,
            'locked_by' => $message->locked_by,
        ];
    }

    private function deadLetterSnapshot(TalktoDeadLetter $deadLetter): array
    {
        return [
            'status' => $deadLetter->status,
            'reprocess_count' => (int) $deadLetter->reprocess_count,
            'reprocessed_at' => $deadLetter->reprocessed_at,
            'failure_reason' => $deadLetter->failure_reason,
            'exception_class' => $deadLetter->exception_class,
            'exception_message' => $deadLetter->exception_message,
        ];
    }

    private function findAndLockOriginalMessage(TalktoDeadLetter $deadLetter, string $messageClass, ?string $operation = null): ?TalktoMessage
    {
        if ($deadLetter->talkto_message_id !== null) {
            $message = $messageClass::query()
                ->whereKey($deadLetter->talkto_message_id)
                ->lockForUpdate()
                ->first();

            if ($message instanceof TalktoMessage) {
                $this->fireMessageLockedHook($message, $operation);

                return $message;
            }
        }

        if ($deadLetter->message_id === null || $deadLetter->message_id === '') {
            return null;
        }

        $message = $messageClass::query()
            ->where('message_id', $deadLetter->message_id)
            ->lockForUpdate()
            ->first();

        if ($message instanceof TalktoMessage) {
            $this->fireMessageLockedHook($message, $operation);
        }

        return $message instanceof TalktoMessage ? $message : null;
    }

    private function isStillHeldByClaim(TalktoMessage $message, string $claimId): bool
    {
        return (string) $message->locked_by === $claimId;
    }

    private function isExpectedClaimState(TalktoMessage $message, TalktoDispatchClaim $claim): bool
    {
        if (! $claim->claimId || ! $claim->message || ! $this->isStillHeldByClaim($message, $claim->claimId)) {
            return false;
        }

        if ((string) $message->overall_status !== (string) $claim->message->overall_status) {
            return false;
        }

        if ($message->direction === TalktoMessageDirection::Outgoing->value) {
            return (string) $message->transport_status === (string) $claim->message->transport_status;
        }

        if ($message->direction === TalktoMessageDirection::Incoming->value) {
            return (string) $message->destination_action_status === (string) $claim->message->destination_action_status;
        }

        return false;
    }

    private function isExpectedDeadLetterClaimState(?TalktoDeadLetter $deadLetter, TalktoDispatchClaim $claim): bool
    {
        if (! $deadLetter instanceof TalktoDeadLetter || $deadLetter->status !== TalktoDeadLetterStatus::Reprocessing->value) {
            return false;
        }

        if ($claim->previousDeadLetterAttributes === []) {
            return false;
        }

        $expectedReprocessCount = ((int) ($claim->previousDeadLetterAttributes['reprocess_count'] ?? -1)) + 1;

        return (int) $deadLetter->reprocess_count === $expectedReprocessCount;
    }

    private function isExpectedCallbackClaimState(TalktoMessage $message, string $claimId): bool
    {
        if (! $this->isStillHeldByClaim($message, $claimId)) {
            return false;
        }

        return $message->direction === TalktoMessageDirection::Outgoing->value
            && in_array($message->overall_status, [
                TalktoMessageStatus::Created->value,
                TalktoMessageStatus::Queued->value,
                TalktoMessageStatus::Pending->value,
                TalktoMessageStatus::WaitingToSend->value,
                TalktoMessageStatus::FailedRetryable->value,
            ], true)
            && in_array($message->transport_status, [
                TalktoMessageStatus::Created->value,
                TalktoMessageStatus::Queued->value,
                TalktoMessageStatus::Pending->value,
                TalktoMessageStatus::WaitingToSend->value,
                TalktoMessageStatus::FailedRetryable->value,
            ], true);
    }

    private function isStillStale(TalktoMessage $message, int $olderThanMinutes): bool
    {
        if ($message->locked_at === null || $message->locked_at->greaterThan(now()->subMinutes($olderThanMinutes))) {
            return false;
        }

        if ($this->isDispatchClaim($message)) {
            return $this->isStaleDispatchClaim($message, $olderThanMinutes);
        }

        return $this->isStaleProcessingLock($message);
    }

    private function isStaleProcessingLock(TalktoMessage $message): bool
    {
        if ($message->direction === TalktoMessageDirection::Outgoing->value) {
            return $message->overall_status === TalktoMessageStatus::Sending->value
                && $message->transport_status === TalktoMessageStatus::Sending->value;
        }

        if ($message->direction === TalktoMessageDirection::Incoming->value) {
            return $message->overall_status === TalktoMessageStatus::Processing->value
                && $message->destination_action_status === TalktoMessageStatus::Processing->value;
        }

        return false;
    }

    private function isStaleDispatchClaim(TalktoMessage $message, int $olderThanMinutes): bool
    {
        if (
            ! $this->isDispatchClaim($message)
            || $message->locked_at === null
            || $message->locked_at->greaterThan(now()->subMinutes($olderThanMinutes))
        ) {
            return false;
        }

        if ($message->direction === TalktoMessageDirection::Outgoing->value) {
            return $message->overall_status === TalktoMessageStatus::WaitingToSend->value
                && $message->transport_status === TalktoMessageStatus::Pending->value;
        }

        if ($message->direction === TalktoMessageDirection::Incoming->value) {
            return $message->overall_status === TalktoMessageStatus::Queued->value
                && $message->destination_action_status === TalktoMessageStatus::Queued->value;
        }

        return false;
    }

    private function isDispatchClaim(TalktoMessage $message): bool
    {
        return str_starts_with((string) $message->locked_by, self::CLAIM_PREFIX);
    }

    private function hasActiveDispatchBlocker(TalktoMessage $message): bool
    {
        if ($this->isDispatchClaim($message)) {
            return true;
        }

        return in_array($message->overall_status, [
            TalktoMessageStatus::Sending->value,
            TalktoMessageStatus::Processing->value,
        ], true)
            || $message->transport_status === TalktoMessageStatus::Sending->value
            || $message->destination_action_status === TalktoMessageStatus::Processing->value;
    }

    private function activeDispatchBlockerStatus(TalktoMessage $message): string
    {
        return $this->isDispatchClaim($message) ? 'active_claim' : 'active_message_operation';
    }

    private function hasAttemptRemaining(TalktoMessage $message): bool
    {
        return ((int) ($message->attempts ?? 0)) < $this->retryPolicy->maxAttempts($message);
    }

    private function hasSupportedDirection(TalktoMessage $message): bool
    {
        return in_array($message->direction, [
            TalktoMessageDirection::Incoming->value,
            TalktoMessageDirection::Outgoing->value,
        ], true);
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

    private function fireMessageLockedHook(TalktoMessage $message, ?string $operation): void
    {
        TalktoDispatchTestHooks::fire('dlq.message_locked', [
            'operation' => $operation,
            'message_db_id' => $message->id,
            'message_id' => $message->message_id,
        ]);
    }

    private function fireDeadLetterLockedHook(TalktoDeadLetter $deadLetter, ?string $operation): void
    {
        TalktoDispatchTestHooks::fire('dlq.dead_letter_locked', [
            'operation' => $operation,
            'dead_letter_id' => $deadLetter->id,
            'message_db_id' => $deadLetter->talkto_message_id,
            'message_id' => $deadLetter->message_id,
        ]);
    }

    private function excerpt(?string $value, int $limit = 2000): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
