<?php

namespace Mrezdev\LaravelTalkto\Pipelines;

use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Enums\TalktoAttemptStatus;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResolver;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;
use Throwable;

/**
 * @internal Runtime orchestration pipeline behind incoming command handling.
 */
class ProcessIncomingTalktoMessagePipeline
{
    private const PROCESSABLE_STATUSES = [
        TalktoMessageStatus::Queued->value,
        TalktoMessageStatus::Pending->value,
    ];

    private const NON_PROCESSABLE_STATUSES = [
        TalktoMessageStatus::Processing->value,
        TalktoMessageStatus::Succeeded->value,
        TalktoMessageStatus::Completed->value,
        TalktoMessageStatus::FailedFinal->value,
        TalktoMessageStatus::Cancelled->value,
        TalktoMessageStatus::Skipped->value,
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

        if ($message->direction !== TalktoMessageDirection::Incoming->value) {
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
            $result = $this->applyUnexpectedFailure($message, $attempt, $throwable, $retryPolicy);

            if ($result !== null) {
                $this->autoDispatchResultCallback($message, $result);
            }

            return;
        }

        $this->autoDispatchResultCallback($message, $result);
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
            'attempt_no' => ((int) $message->getAttribute('attempts')) + 1,
            'status' => TalktoAttemptStatus::Skipped->value,
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

            if ($message->direction !== TalktoMessageDirection::Incoming->value) {
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
            $attemptNo = ((int) $message->getAttribute('attempts')) + 1;

            $message->forceFill([
                'attempts' => $attemptNo,
                'destination_action_status' => TalktoMessageStatus::Processing->value,
                'overall_status' => TalktoMessageStatus::Processing->value,
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
                'status' => TalktoAttemptStatus::Processing->value,
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
                'new_status' => TalktoMessageStatus::Processing->value,
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

    private function autoDispatchResultCallback(TalktoMessage $message, IncomingCommandResultContract $result): void
    {
        $messageClass = $this->messageModelClass();
        $message = $messageClass::query()->find($message->id) ?? $message;

        if (! config('talkto.callbacks.auto_dispatch', true)) {
            $this->recordAutoDispatchSkippedEvent($message);

            return;
        }

        app(ResultCallbackSenderContract::class)->sendResult($message, $result);
    }

    private function recordAutoDispatchSkippedEvent(TalktoMessage $message): void
    {
        $eventClass = $this->eventModelClass();

        $eventClass::query()->create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => 'result_callback_auto_dispatch_skipped',
            'old_status' => null,
            'new_status' => 'skipped',
            'meta' => $this->eventMeta($message, [
                'reason' => 'auto_dispatch_disabled',
                'durable' => true,
            ]),
        ]);
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
                'destination_action_status' => TalktoMessageStatus::Skipped->value,
                'overall_status' => TalktoMessageStatus::Skipped->value,
                'completed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            $attempt->forceFill([
                'status' => TalktoAttemptStatus::Skipped->value,
                'meta' => $this->mergeAttemptMeta($attempt, [
                    'result_meta' => $meta,
                ]),
            ])->save();

            $eventClass::query()->create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'incoming_command_skipped',
                'old_status' => TalktoMessageStatus::Processing->value,
                'new_status' => TalktoMessageStatus::Skipped->value,
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
                'destination_action_status' => TalktoMessageStatus::Succeeded->value,
                'overall_status' => TalktoMessageStatus::Succeeded->value,
                'completed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            $attempt->forceFill([
                'status' => TalktoAttemptStatus::Succeeded->value,
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
                'old_status' => TalktoMessageStatus::Processing->value,
                'new_status' => TalktoMessageStatus::Succeeded->value,
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
        $newStatus = $result->isRetryable() ? TalktoMessageStatus::FailedRetryable->value : TalktoMessageStatus::FailedFinal->value;

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
                'old_status' => TalktoMessageStatus::Processing->value,
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

    private function applyUnexpectedFailure(TalktoMessage $message, ?TalktoAttempt $attempt, Throwable $throwable, TalktoRetryPolicy $retryPolicy): ?IncomingCommandResultContract
    {
        return DB::transaction(function () use ($message, $attempt, $throwable, $retryPolicy): ?IncomingCommandResultContract {
            $messageClass = $this->messageModelClass();
            $eventClass = $this->eventModelClass();

            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return null;
            }

            $newStatus = TalktoMessageStatus::FailedRetryable->value;
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

            $isFinalFailure = $newStatus === $retryPolicy->finalFailureStatus($message);
            $retryableForEvent = ! $isFinalFailure;

            if ($isFinalFailure) {
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
                'old_status' => TalktoMessageStatus::Processing->value,
                'new_status' => $newStatus,
                'meta' => $this->eventMeta($message, [
                    'error_class' => $throwable::class,
                    'retryable' => $retryableForEvent,
                ]),
            ]);

            if ($retryPolicy->isDirectionEnabled($message)) {
                $this->recordRetryEvent($eventClass, $message, $scheduled ? 'retry_scheduled' : 'retry_exhausted');
            }

            return $this->resultFromUnexpectedFailure($message, $throwable, $retryPolicy);
        });
    }

    private function resultFromUnexpectedFailure(TalktoMessage $message, Throwable $throwable, TalktoRetryPolicy $retryPolicy): IncomingCommandResultContract
    {
        $retryable = $message->overall_status !== $retryPolicy->finalFailureStatus($message);
        $errorMessage = $this->safeExceptionMessage($throwable);
        $meta = [
            'unexpected_exception' => true,
            'exception_class' => $throwable::class,
            'retryable' => $retryable,
            'source' => 'handler_exception',
        ];

        return $retryable
            ? TalktoIncomingCommandResult::failedRetryable($errorMessage, $throwable::class, $meta)
            : TalktoIncomingCommandResult::failedFinal($errorMessage, $throwable::class, $meta);
    }

    private function safeExceptionMessage(Throwable $throwable): string
    {
        $message = app(TalktoSecurityRedactor::class)->redactText($throwable->getMessage()) ?? '';
        $message = trim($message);

        if ($message === '') {
            $message = 'Unexpected handler exception.';
        }

        return mb_substr($message, 0, 500);
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
            'old_status' => TalktoMessageStatus::Processing->value,
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
        return app(TalktoModelResolver::class)->message();
    }

    private function attemptModelClass(): string
    {
        return app(TalktoModelResolver::class)->attempt();
    }

    private function eventModelClass(): string
    {
        return app(TalktoModelResolver::class)->event();
    }
}
