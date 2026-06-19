<?php

namespace Mrezdev\LaravelTalkto\Pipelines;

use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResolver;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessIncomingTalktoMessagePipeline
{
    private const PROCESSABLE_STATUSES = ['queued', 'pending'];

    private const NON_PROCESSABLE_STATUSES = [
        'processing',
        'succeeded',
        'completed',
        'failed_final',
        'cancelled',
        'skipped',
    ];

    private int $talktoMessageId;

    public function process(int $talktoMessageId, mixed $resolver = null, ?TalktoRetryPolicy $retryPolicy = null): void
    {
        $this->talktoMessageId = $talktoMessageId;
        $resolver ??= app(TalktoIncomingCommandResolver::class);
        $retryPolicy ??= app(TalktoRetryPolicy::class);

        $messageClass = $this->messageModelClass();
        $message = $messageClass::query()->find($this->talktoMessageId);

        if (! $message) {
            return;
        }

        if ($message->direction !== 'incoming') {
            $this->createSkippedAttempt(
                $message,
                'invalid_direction',
                'Only incoming Talkto messages can be processed by this job.'
            );

            return;
        }

        if (! $this->isQueuedForProcessing($message, $retryPolicy)) {
            return;
        }

        $processing = $this->markProcessing($retryPolicy);

        if ($processing === null) {
            return;
        }

        [$message, $attempt] = $processing;

        try {
            $handler = $resolver->resolve($message);
            $result = $handler->handle($message);

            $this->applyResult($message, $attempt, $result, $retryPolicy);
        } catch (Throwable $throwable) {
            $this->applyUnexpectedFailure($message, $attempt, $throwable, $retryPolicy);
        }
    }

    private function isQueuedForProcessing(TalktoMessage $message, TalktoRetryPolicy $retryPolicy): bool
    {
        if (
            in_array($message->overall_status, self::NON_PROCESSABLE_STATUSES, true)
            || in_array($message->destination_action_status, self::NON_PROCESSABLE_STATUSES, true)
        ) {
            return false;
        }

        return in_array($message->overall_status, self::PROCESSABLE_STATUSES, true)
            || in_array($message->destination_action_status, self::PROCESSABLE_STATUSES, true)
            || ($retryPolicy->canRetry($message) && $retryPolicy->isDue($message));
    }

    private function processorLockName(): string
    {
        $hostname = function_exists('gethostname') ? gethostname() : false;
        $processId = function_exists('getmypid') ? getmypid() : false;

        return 'processor:'.($hostname ?: 'unknown-host').':'.($processId ?: 'unknown-process');
    }

    private function createSkippedAttempt(TalktoMessage $message, string $errorClass, string $errorMessage): void
    {
        $attemptClass = $this->attemptModelClass();

        $attemptClass::query()->create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'stage' => 'destination_processor',
            'attempt_no' => ((int) $message->attempts) + 1,
            'status' => 'skipped',
            'error_class' => $errorClass,
            'error_message' => $errorMessage,
        ]);
    }

    private function markProcessing(TalktoRetryPolicy $retryPolicy): ?array
    {
        return DB::transaction(function () use ($retryPolicy): ?array {
            $messageClass = $this->messageModelClass();
            $attemptClass = $this->attemptModelClass();
            $eventClass = $this->eventModelClass();

            $message = $messageClass::query()
                ->whereKey($this->talktoMessageId)
                ->lockForUpdate()
                ->first();

            if (! $message) {
                return null;
            }

            if ($message->direction !== 'incoming') {
                $this->createSkippedAttempt(
                    $message,
                    'invalid_direction',
                    'Only incoming Talkto messages can be processed by this job.'
                );

                return null;
            }

            if (! $this->isQueuedForProcessing($message, $retryPolicy)) {
                return null;
            }

            $previousStatus = $message->overall_status;
            $attemptNo = ((int) $message->attempts) + 1;

            $message->forceFill([
                'attempts' => $attemptNo,
                'destination_action_status' => 'processing',
                'overall_status' => 'processing',
                'processing_started_at' => $message->processing_started_at ?: now(),
                'last_attempted_at' => now(),
                'next_retry_at' => null,
                'next_attempt_at' => null,
                'locked_at' => now(),
                'locked_by' => $this->processorLockName(),
            ])->save();

            $attempt = $attemptClass::query()->create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'stage' => 'destination_processor',
                'attempt_no' => $attemptNo,
                'status' => 'processing',
                'meta' => [
                    'command' => $message->command,
                    'business_key' => $message->business_key,
                    'idempotency_key' => $message->idempotency_key,
                ],
            ]);

            $eventClass::query()->create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'destination_processing_started',
                'old_status' => $previousStatus,
                'new_status' => 'processing',
                'meta' => $this->eventMeta($message),
            ]);

            return [$message->fresh(), $attempt];
        });
    }

    private function applyResult(TalktoMessage $message, TalktoAttempt $attempt, IncomingCommandResultContract $result, TalktoRetryPolicy $retryPolicy): void
    {
        if ($result->isSkipped()) {
            $this->applySkippedResult($message, $attempt, $result);

            return;
        }

        if ($result->isSucceeded()) {
            $this->applySuccessfulResult($message, $attempt, $result);

            return;
        }

        $this->applyFailedResult($message, $attempt, $result, $retryPolicy);
    }

    private function applySkippedResult(TalktoMessage $message, TalktoAttempt $attempt, IncomingCommandResultContract $result): void
    {
        DB::transaction(function () use ($message, $attempt, $result): void {
            $messageClass = $this->messageModelClass();
            $eventClass = $this->eventModelClass();
            $meta = $result->meta();

            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $message->forceFill([
                'destination_action_status' => 'skipped',
                'overall_status' => 'skipped',
                'completed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            $attempt->forceFill([
                'status' => 'skipped',
                'meta' => $this->mergeAttemptMeta($attempt, [
                    'result_meta' => $meta,
                ]),
            ])->save();

            $eventClass::query()->create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'incoming_command_skipped',
                'old_status' => 'processing',
                'new_status' => 'skipped',
                'meta' => $this->eventMeta($message, $meta),
            ]);

            app(TalktoDeadLetterQueue::class)->markReprocessedForMessage($message);
        });
    }

    private function applySuccessfulResult(TalktoMessage $message, TalktoAttempt $attempt, IncomingCommandResultContract $result): void
    {
        DB::transaction(function () use ($message, $attempt, $result): void {
            $messageClass = $this->messageModelClass();
            $eventClass = $this->eventModelClass();
            $resultPayload = $result->result();
            $meta = $result->meta();

            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $message->forceFill([
                'destination_action_status' => 'succeeded',
                'overall_status' => 'succeeded',
                'completed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            $attempt->forceFill([
                'status' => 'succeeded',
                'meta' => $this->mergeAttemptMeta($attempt, [
                    'result' => $resultPayload,
                    'result_meta' => $meta,
                ]),
            ])->save();

            $eventClass::query()->create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'destination_processing_succeeded',
                'old_status' => 'processing',
                'new_status' => 'succeeded',
                'meta' => $this->eventMeta($message, [
                    'result' => $resultPayload,
                    'result_meta' => $meta,
                ]),
            ]);

            app(TalktoDeadLetterQueue::class)->markReprocessedForMessage($message);
        });
    }

    private function applyFailedResult(TalktoMessage $message, TalktoAttempt $attempt, IncomingCommandResultContract $result, TalktoRetryPolicy $retryPolicy): void
    {
        $newStatus = $result->isRetryable() ? 'failed_retryable' : 'failed_final';

        DB::transaction(function () use ($message, $attempt, $result, $newStatus, $retryPolicy): void {
            $messageClass = $this->messageModelClass();
            $eventClass = $this->eventModelClass();
            $retryable = $result->isRetryable();
            $errorMessage = $result->errorMessage();
            $errorClass = $result->errorClass();
            $meta = $result->meta();

            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $scheduled = false;

            if ($retryable && $retryPolicy->isDirectionEnabled($message)) {
                if ($retryPolicy->canScheduleRetry($message)) {
                    $retryPolicy->markRetryableFailure($message, 'destination_action_status', $errorMessage);
                    $newStatus = $message->overall_status;
                    $scheduled = true;
                } else {
                    $retryPolicy->markFinalFailure($message, 'destination_action_status', $errorMessage);
                    $newStatus = $message->overall_status;
                }
            } else {
                $message->forceFill([
                    'destination_action_status' => $newStatus,
                    'overall_status' => $newStatus,
                    'failed_at' => now(),
                    'last_attempted_at' => now(),
                    'last_error' => $errorMessage,
                    'locked_at' => null,
                    'locked_by' => null,
                ])->save();
            }

            if ($newStatus === $retryPolicy->finalFailureStatus()) {
                $this->storeDeadLetterIfEnabled($message, $errorMessage);
            }

            $attempt->forceFill([
                'status' => $newStatus,
                'error_class' => $errorClass,
                'error_message' => $errorMessage,
                'meta' => $this->mergeAttemptMeta($attempt, [
                    'result_meta' => $meta,
                ]),
            ])->save();

            $eventClass::query()->create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'destination_processing_failed',
                'old_status' => 'processing',
                'new_status' => $newStatus,
                'meta' => $this->eventMeta($message, [
                    'error_class' => $errorClass,
                    'retryable' => $retryable,
                ]),
            ]);

            if ($retryable && $retryPolicy->isDirectionEnabled($message)) {
                $this->recordRetryEvent($eventClass, $message, $scheduled ? 'retry_scheduled' : 'retry_exhausted');
            }
        });
    }

    private function applyUnexpectedFailure(TalktoMessage $message, ?TalktoAttempt $attempt, Throwable $throwable, TalktoRetryPolicy $retryPolicy): void
    {
        DB::transaction(function () use ($message, $attempt, $throwable, $retryPolicy): void {
            $messageClass = $this->messageModelClass();
            $eventClass = $this->eventModelClass();

            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $newStatus = 'failed_retryable';
            $scheduled = false;

            if ($retryPolicy->isDirectionEnabled($message)) {
                if ($retryPolicy->canScheduleRetry($message)) {
                    $retryPolicy->markRetryableFailure($message, 'destination_action_status', $throwable->getMessage());
                    $scheduled = true;
                } else {
                    $retryPolicy->markFinalFailure($message, 'destination_action_status', $throwable->getMessage());
                }

                $newStatus = $message->overall_status;
            } else {
                $message->forceFill([
                    'destination_action_status' => $newStatus,
                    'overall_status' => $newStatus,
                    'failed_at' => now(),
                    'last_attempted_at' => now(),
                    'last_error' => $throwable->getMessage(),
                    'locked_at' => null,
                    'locked_by' => null,
                ])->save();
            }

            if ($newStatus === $retryPolicy->finalFailureStatus()) {
                $this->storeDeadLetterIfEnabled($message, $throwable->getMessage(), $throwable);
            }

            if ($attempt) {
                $attempt->forceFill([
                    'status' => $newStatus,
                    'error_class' => $throwable::class,
                    'error_message' => $throwable->getMessage(),
                ])->save();
            }

            $eventClass::query()->create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'destination_processing_failed',
                'old_status' => 'processing',
                'new_status' => $newStatus,
                'meta' => $this->eventMeta($message, [
                    'error_class' => $throwable::class,
                    'retryable' => true,
                ]),
            ]);

            if ($retryPolicy->isDirectionEnabled($message)) {
                $this->recordRetryEvent($eventClass, $message, $scheduled ? 'retry_scheduled' : 'retry_exhausted');
            }
        });
    }

    private function storeDeadLetterIfEnabled(TalktoMessage $message, ?string $failureReason = null, ?Throwable $throwable = null): void
    {
        $deadLetterQueue = app(TalktoDeadLetterQueue::class);

        if (! $deadLetterQueue->autoStoreEnabled()) {
            return;
        }

        $deadLetterQueue->store($message, $failureReason, $throwable);
    }

    private function recordRetryEvent(string $eventClass, TalktoMessage $message, string $eventType): void
    {
        $eventClass::query()->create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => $eventType,
            'old_status' => 'processing',
            'new_status' => $message->overall_status,
            'meta' => $this->eventMeta($message, $this->retryEventMeta($message, $eventType)),
        ]);
    }

    private function retryEventMeta(TalktoMessage $message, string $eventType): array
    {
        $decision = app(TalktoRetryPolicy::class)->decisionFor($message, ['ignore_status' => true])->toArray();
        $reason = $decision['reason'] ?? null;
        $backoffSeconds = $decision['backoff_seconds'] ?? null;

        if ($eventType === 'retry_scheduled') {
            $reason = 'scheduled';
            $backoffSeconds = $this->scheduledBackoffSeconds($message);
        } elseif ($eventType === 'retry_exhausted' && in_array($reason, ['eligible', 'not_due'], true)) {
            $reason = 'max_attempts_exhausted';
        }

        return [
            'direction' => $message->direction,
            'retry_count' => (int) ($message->retry_count ?? 0),
            'max_attempts' => $decision['max_attempts'] ?? null,
            'backoff_seconds' => $backoffSeconds,
            'next_retry_at' => optional($message->next_retry_at)->toIso8601String(),
            'reason' => $reason,
        ];
    }

    private function scheduledBackoffSeconds(TalktoMessage $message): ?int
    {
        if (($message->last_attempted_at ?? null) === null || ($message->next_retry_at ?? null) === null) {
            return null;
        }

        return max(0, (int) $message->last_attempted_at->diffInSeconds($message->next_retry_at, false));
    }

    private function eventMeta(TalktoMessage $message, array $extra = []): array
    {
        return array_merge([
            'command' => $message->command,
            'business_key' => $message->business_key,
        ], $extra);
    }

    private function mergeAttemptMeta(TalktoAttempt $attempt, array $extra): array
    {
        return array_merge($attempt->meta ?? [], $extra);
    }

    private function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
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
}
