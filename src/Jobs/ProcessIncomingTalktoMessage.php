<?php

namespace Ibake\TalktoReliable\Jobs;

use Ibake\TalktoReliable\Models\TalktoAttempt;
use Ibake\TalktoReliable\Models\TalktoEvent;
use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Services\TalktoIncomingCommandResolver;
use Ibake\TalktoReliable\Services\TalktoIncomingCommandResult;
use Ibake\TalktoReliable\Services\TalktoRetryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessIncomingTalktoMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const PROCESSABLE_STATUSES = ['queued', 'pending'];

    private const NON_PROCESSABLE_STATUSES = [
        'processing',
        'succeeded',
        'completed',
        'failed_final',
        'cancelled',
        'skipped',
    ];

    public int $tries = 1;

    public function __construct(public int $talktoMessageId) {}

    public function handle(mixed $resolver = null, ?TalktoRetryPolicy $retryPolicy = null): void
    {
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

    private function applyResult(TalktoMessage $message, TalktoAttempt $attempt, TalktoIncomingCommandResult $result, TalktoRetryPolicy $retryPolicy): void
    {
        if ($result->succeeded) {
            $this->applySuccessfulResult($message, $attempt, $result);

            return;
        }

        $this->applyFailedResult($message, $attempt, $result, $retryPolicy);
    }

    private function applySuccessfulResult(TalktoMessage $message, TalktoAttempt $attempt, TalktoIncomingCommandResult $result): void
    {
        DB::transaction(function () use ($message, $attempt, $result): void {
            $messageClass = $this->messageModelClass();
            $eventClass = $this->eventModelClass();

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
                    'result' => $result->result,
                    'result_meta' => $result->meta,
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
                    'result' => $result->result,
                    'result_meta' => $result->meta,
                ]),
            ]);
        });
    }

    private function applyFailedResult(TalktoMessage $message, TalktoAttempt $attempt, TalktoIncomingCommandResult $result, TalktoRetryPolicy $retryPolicy): void
    {
        $newStatus = $result->retryable ? 'failed_retryable' : 'failed_final';

        DB::transaction(function () use ($message, $attempt, $result, $newStatus, $retryPolicy): void {
            $messageClass = $this->messageModelClass();
            $eventClass = $this->eventModelClass();

            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $scheduled = false;

            if ($result->retryable && $retryPolicy->isDirectionEnabled($message)) {
                if ($retryPolicy->canScheduleRetry($message)) {
                    $retryPolicy->markRetryableFailure($message, 'destination_action_status', $result->errorMessage);
                    $newStatus = $message->overall_status;
                    $scheduled = true;
                } else {
                    $retryPolicy->markFinalFailure($message, 'destination_action_status', $result->errorMessage);
                    $newStatus = $message->overall_status;
                }
            } else {
                $message->forceFill([
                    'destination_action_status' => $newStatus,
                    'overall_status' => $newStatus,
                    'failed_at' => now(),
                    'last_attempted_at' => now(),
                    'last_error' => $result->errorMessage,
                    'locked_at' => null,
                    'locked_by' => null,
                ])->save();
            }

            $attempt->forceFill([
                'status' => $newStatus,
                'error_class' => $result->errorClass,
                'error_message' => $result->errorMessage,
                'meta' => $this->mergeAttemptMeta($attempt, [
                    'result_meta' => $result->meta,
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
                    'error_class' => $result->errorClass,
                    'retryable' => $result->retryable,
                ]),
            ]);

            if ($result->retryable && $retryPolicy->isDirectionEnabled($message)) {
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

    private function recordRetryEvent(string $eventClass, TalktoMessage $message, string $eventType): void
    {
        $eventClass::query()->create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => $eventType,
            'old_status' => 'processing',
            'new_status' => $message->overall_status,
            'meta' => $this->eventMeta($message, [
                'direction' => $message->direction,
                'retry_count' => (int) ($message->retry_count ?? 0),
                'next_retry_at' => optional($message->next_retry_at)->toIso8601String(),
            ]),
        ]);
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
