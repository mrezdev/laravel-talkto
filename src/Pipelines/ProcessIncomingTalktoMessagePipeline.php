<?php

namespace Mrezdev\LaravelTalkto\Pipelines;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Enums\TalktoAttemptStatus;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;
use Mrezdev\LaravelTalkto\Exceptions\TalktoReliableCallbackPersistenceException;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResolver;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoReliableCallbackService;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Mrezdev\LaravelTalkto\Support\TalktoModelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use Mrezdev\LaravelTalkto\Support\TalktoReliableCallbackContext;
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

    private ?TalktoReliableCallbackContext $reliableContext = null;

    private ?TalktoReliableCallbackService $reliableCallbacks = null;

    public function process(int $talktoMessageId, mixed $resolver = null, ?TalktoRetryPolicy $retryPolicy = null): void
    {
        $this->talktoMessageId = $talktoMessageId;
        $this->reliableContext = null;
        $this->reliableCallbacks = null;
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

        if (TalktoReliableCallbackService::required()) {
            $this->reliableCallbacks = app(TalktoReliableCallbackService::class);
            $this->reliableContext = $this->reliableCallbacks->preflight($message);
        }

        $processing = $this->markProcessing($retryPolicy);

        if ($processing === null) {
            return;
        }

        [$message, $attempt] = $processing;

        if ($this->reliableContext !== null) {
            $this->processWithReliableCallback($message, $attempt, $resolver, $retryPolicy);

            return;
        }

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
        if (TalktoReliableCallbackService::isProtected($message)) {
            return false;
        }
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
        if ($this->reliableContext !== null) {
            return TalktoReliableCallbackService::LOCK_PREFIX.Str::uuid();
        }
        $hostname = function_exists('gethostname') ? gethostname() : false;
        $processId = function_exists('getmypid') ? getmypid() : false;

        return 'processor:'.($hostname ?: 'unknown-host').':'.($processId ?: 'unknown-process');
    }

    private function processWithReliableCallback(TalktoMessage $message, TalktoAttempt $attempt, mixed $resolver, TalktoRetryPolicy $retryPolicy): void
    {
        $context = $this->reliableContext;
        $callbacks = $this->reliableCallbacks;
        if ($context === null || $callbacks === null) {
            throw new \LogicException('Reliable callback processing context is unavailable.');
        }
        $returned = false;
        $active = $callbacks->registerActive($message);
        try {
            $callbacks->assertContext($context);
            if ($context->connection->transactionLevel() !== 0) {
                throw new \RuntimeException('The reliable processing claim must commit before handler execution.');
            }
            $hook = $callbacks->hookContext($message, $attempt);
            TalktoDispatchTestHooks::fire('reliable_callback.before_handler', $hook);
            $callbacks->assertContext($context);
            $context->connection->transaction(fn () => $callbacks->lockResult($context, $message, $attempt));
            $failure = null;
            $result = null;
            try {
                $handler = $resolver->resolve($message);
                $result = $handler->handle($message);
                $returned = true;
            } catch (Throwable $throwable) {
                if ($throwable instanceof TalktoReliableCallbackPersistenceException) {
                    throw $throwable;
                }
                $failure = $throwable;
            }
            TalktoDispatchTestHooks::fire('reliable_callback.after_handler', $hook + ['result_returned' => $returned]);
            $callbacks->assertContext($context);
            if ($context->connection->transactionLevel() !== 0) {
                throw new \RuntimeException('A reliable handler must finish its own transactions before result persistence.');
            }
            $snapshot = $failure === null ? $callbacks->freezeResult($result) : null;
            $context->connection->transaction(function () use ($callbacks, $context, $message, $attempt, $snapshot, $failure, $retryPolicy, $hook): void {
                [$locked, $lockedAttempt] = $callbacks->lockResult($context, $message, $attempt);
                TalktoDispatchTestHooks::fire('reliable_callback.before_result_persistence', $hook);
                $callbacks->assertContext($context);
                if ($failure !== null) {
                    $result = $this->applyUnexpectedFailure($locked, $lockedAttempt, $failure, $retryPolicy);
                    if ($result === null) {
                        throw new \RuntimeException('The reliable handler exception result could not be persisted.');
                    }
                    $result = $callbacks->freezeResult($result);
                } else {
                    $result = $snapshot;
                    $this->applyResult($locked, $lockedAttempt, $result, $retryPolicy);
                }
                $callbacks->assertContext($context);
                TalktoDispatchTestHooks::fire('reliable_callback.result_persisted', $hook);
                $callbacks->assertContext($context);
                $class = $context->models['message'];
                $current = $class::query()->whereKey($message->getKey())->first();
                if (! $current instanceof TalktoMessage) {
                    throw new \RuntimeException('The reliable result message disappeared before callback persistence.');
                }
                $callbacks->assertPersistedResult($context, $current, $lockedAttempt);
                $callbacks->persistForAttempt($context, $current, $result, $lockedAttempt);
                TalktoDispatchTestHooks::fire('reliable_callback.before_commit', $hook);
                $callbacks->assertContext($context);
            });
            TalktoDispatchTestHooks::fire('reliable_callback.committed', $hook);
        } catch (Throwable $failure) {
            $callbacks->investigate($context, $message, $attempt, $failure, $returned);

            // Database errors may embed the complete result as SQL bindings.
            // Keep that exception out of worker/failed-job logs and chains.
            throw new TalktoReliableCallbackPersistenceException('Reliable callback processing requires investigation. Inspect the durable message and callback state; no handler retry was authorized.');
        } finally {
            $callbacks->unregisterActive($active);
        }
    }

    private function createSkippedAttempt(TalktoMessage $message, string $errorClass, string $errorMessage): void
    {
        $attemptClass = $this->attemptModelClass();

        TalktoModelConnection::assertSameConnection($message, $attemptClass);

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
        $messageClass = $this->messageModelClass();
        $attemptClass = $this->attemptModelClass();
        $eventClass = $this->eventModelClass();

        TalktoModelConnection::assertSameConnection($messageClass, $attemptClass, $eventClass);

        return TalktoModelConnection::transaction($messageClass, function () use ($messageClass, $attemptClass, $eventClass, $retryPolicy): ?array {
            $message = $messageClass::query()
                ->whereKey($this->talktoMessageId)
                ->lockForUpdate()
                ->first();

            if (! $message) {
                return null;
            }

            if ($this->reliableContext !== null && $this->reliableCallbacks !== null) {
                $this->reliableCallbacks->assertMessageIdentity($this->reliableContext, $message);
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

            $this->assertReliableContext();

            $this->saveRequired($message, [
                'attempts' => $attemptNo,
                'destination_action_status' => TalktoMessageStatus::Processing->value,
                'overall_status' => TalktoMessageStatus::Processing->value,
                'processing_started_at' => $message->processing_started_at ?: now(),
                'last_attempted_at' => now(),
                'next_retry_at' => null,
                'next_attempt_at' => null,
                'locked_at' => now(),
                'locked_by' => $this->processorLockName(),
            ]);

            $this->assertReliableContext();

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
                    ...($this->reliableContext !== null ? ['reliable_callback_claim' => $message->locked_by] : []),
                ],
            ]);
            $this->assertReliableCreated($attempt);

            $this->assertReliableContext();

            $event = $eventClass::query()->create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'destination_processing_started',
                'old_status' => $previousStatus,
                'new_status' => TalktoMessageStatus::Processing->value,
                'meta' => $this->eventMeta($message),
            ]);
            $this->assertReliableCreated($event);

            $this->assertReliableContext();

            if ($this->reliableContext !== null && $this->reliableCallbacks !== null) {
                return $this->reliableCallbacks->lockResult($this->reliableContext, $message, $attempt);
            }

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

        TalktoModelConnection::assertSameConnection($message, $eventClass);

        $event = $eventClass::query()->create([
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
        $this->assertReliableCreated($event);
    }

    private function applySkippedResult(TalktoMessage $message, TalktoAttempt $attempt, IncomingCommandResultContract $result): void
    {
        TalktoModelConnection::assertSameConnection($message, $attempt, $this->eventModelClass());

        TalktoModelConnection::transaction($message, function () use ($message, $attempt, $result): void {
            $messageClass = $this->messageModelClass();
            $eventClass = $this->eventModelClass();
            $meta = $result->meta();

            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $this->assertReliableContext();

            $this->saveRequired($message, [
                'destination_action_status' => TalktoMessageStatus::Skipped->value,
                'overall_status' => TalktoMessageStatus::Skipped->value,
                'completed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'locked_at' => null,
                'locked_by' => null,
            ]);

            $this->assertReliableContext();

            $this->saveRequired($attempt, [
                'status' => TalktoAttemptStatus::Skipped->value,
                'meta' => $this->mergeAttemptMeta($attempt, [
                    'result_meta' => $meta,
                ]),
            ]);

            $this->assertReliableContext();

            $event = $eventClass::query()->create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'incoming_command_skipped',
                'old_status' => TalktoMessageStatus::Processing->value,
                'new_status' => TalktoMessageStatus::Skipped->value,
                'meta' => $this->eventMeta($message, $meta),
            ]);
            $this->assertReliableCreated($event);

            app(TalktoDeadLetterQueue::class)->markReprocessedForMessage($message);
        });
    }

    private function applySuccessfulResult(TalktoMessage $message, TalktoAttempt $attempt, IncomingCommandResultContract $result): void
    {
        TalktoModelConnection::assertSameConnection($message, $attempt, $this->eventModelClass());

        TalktoModelConnection::transaction($message, function () use ($message, $attempt, $result): void {
            $messageClass = $this->messageModelClass();
            $eventClass = $this->eventModelClass();
            $resultPayload = $result->result();
            $meta = $result->meta();

            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $this->assertReliableContext();

            $this->saveRequired($message, [
                'destination_action_status' => TalktoMessageStatus::Succeeded->value,
                'overall_status' => TalktoMessageStatus::Succeeded->value,
                'completed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'locked_at' => null,
                'locked_by' => null,
            ]);

            $this->assertReliableContext();

            $this->saveRequired($attempt, [
                'status' => TalktoAttemptStatus::Succeeded->value,
                'meta' => $this->mergeAttemptMeta($attempt, [
                    'result' => $resultPayload,
                    'result_meta' => $meta,
                ]),
            ]);

            $this->assertReliableContext();

            $event = $eventClass::query()->create([
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
            $this->assertReliableCreated($event);

            app(TalktoDeadLetterQueue::class)->markReprocessedForMessage($message);
        });
    }

    private function applyFailedResult(TalktoMessage $message, TalktoAttempt $attempt, IncomingCommandResultContract $result, TalktoRetryPolicy $retryPolicy): void
    {
        $newStatus = $result->isRetryable() ? TalktoMessageStatus::FailedRetryable->value : TalktoMessageStatus::FailedFinal->value;

        TalktoModelConnection::assertSameConnection($message, $attempt, $this->eventModelClass());

        TalktoModelConnection::transaction($message, function () use ($message, $attempt, $result, $newStatus, $retryPolicy): void {
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
                $this->assertReliableContext();

                $this->saveRequired($message, [
                    'destination_action_status' => $newStatus,
                    'overall_status' => $newStatus,
                    'failed_at' => now(),
                    'last_attempted_at' => now(),
                    'last_error' => $errorMessage,
                    'locked_at' => null,
                    'locked_by' => null,
                ]);
            }

            if ($newStatus === $retryPolicy->finalFailureStatus()) {
                $this->storeDeadLetterIfEnabled($message, $errorMessage);
            }

            $this->assertReliableContext();

            $this->saveRequired($attempt, [
                'status' => $newStatus,
                'error_class' => $errorClass,
                'error_message' => $errorMessage,
                'meta' => $this->mergeAttemptMeta($attempt, [
                    'result_meta' => $meta,
                ]),
            ]);

            $this->assertReliableContext();

            $event = $eventClass::query()->create([
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
            $this->assertReliableCreated($event);

            if ($retryable && $retryPolicy->isDirectionEnabled($message)) {
                $this->recordRetryEvent($eventClass, $message, $scheduled ? 'retry_scheduled' : 'retry_exhausted');
            }
        });
    }

    private function applyUnexpectedFailure(TalktoMessage $message, ?TalktoAttempt $attempt, Throwable $throwable, TalktoRetryPolicy $retryPolicy): ?IncomingCommandResultContract
    {
        $attempt instanceof TalktoAttempt
            ? TalktoModelConnection::assertSameConnection($message, $attempt, $this->eventModelClass())
            : TalktoModelConnection::assertSameConnection($message, $this->eventModelClass());

        return TalktoModelConnection::transaction($message, function () use ($message, $attempt, $throwable, $retryPolicy): ?IncomingCommandResultContract {
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
                $this->assertReliableContext();

                $this->saveRequired($message, [
                    'destination_action_status' => $newStatus,
                    'overall_status' => $newStatus,
                    'failed_at' => now(),
                    'last_attempted_at' => now(),
                    'last_error' => $throwable->getMessage(),
                    'locked_at' => null,
                    'locked_by' => null,
                ]);
            }

            $isFinalFailure = $newStatus === $retryPolicy->finalFailureStatus($message);
            $retryableForEvent = ! $isFinalFailure;

            if ($isFinalFailure) {
                $this->storeDeadLetterIfEnabled($message, $throwable->getMessage(), $throwable);
            }

            if ($attempt) {
                $this->assertReliableContext();

                $this->saveRequired($attempt, [
                    'status' => $newStatus,
                    'error_class' => $throwable::class,
                    'error_message' => $throwable->getMessage(),
                ]);
            }

            $this->assertReliableContext();

            $event = $eventClass::query()->create([
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
            $this->assertReliableCreated($event);

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

        TalktoModelConnection::assertSameConnection($message, $this->deadLetterModelClass(), $this->eventModelClass());

        $deadLetterQueue->store($message, $failureReason, $throwable);
    }

    private function recordRetryEvent(string $eventClass, TalktoMessage $message, string $eventType): void
    {
        $event = $eventClass::query()->create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => $eventType,
            'old_status' => TalktoMessageStatus::Processing->value,
            'new_status' => $message->overall_status,
            'meta' => $this->eventMeta($message, $this->retryEventMeta($message, $eventType)),
        ]);
        $this->assertReliableCreated($event);
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
        if ($this->reliableContext !== null) {
            $this->assertReliableContext();

            return $this->reliableContext->models['message'];
        }

        return app(TalktoModelResolver::class)->message();
    }

    private function attemptModelClass(): string
    {
        if ($this->reliableContext !== null) {
            $this->assertReliableContext();

            return $this->reliableContext->models['attempt'];
        }

        return app(TalktoModelResolver::class)->attempt();
    }

    private function eventModelClass(): string
    {
        if ($this->reliableContext !== null) {
            $this->assertReliableContext();

            return $this->reliableContext->models['event'];
        }

        return app(TalktoModelResolver::class)->event();
    }

    private function deadLetterModelClass(): string
    {
        if ($this->reliableContext !== null) {
            $this->assertReliableContext();

            return $this->reliableContext->models['dead_letter'];
        }

        return app(TalktoModelResolver::class)->deadLetter();
    }

    private function assertReliableContext(): void
    {
        if ($this->reliableContext !== null && $this->reliableCallbacks !== null) {
            $this->reliableCallbacks->assertContext($this->reliableContext);
        }
    }

    private function saveRequired(Model $model, array $attributes): void
    {
        $saved = $model->forceFill($attributes)->save();
        if ($this->reliableContext !== null && ! $saved) {
            throw new TalktoReliableCallbackPersistenceException('Required reliable processing persistence was cancelled.');
        }
    }

    private function assertReliableCreated(Model $model): void
    {
        if ($this->reliableContext !== null && (! $model->exists || $model->getKey() === null)) {
            throw new TalktoReliableCallbackPersistenceException('Required reliable processing evidence was not persisted.');
        }
    }
}
