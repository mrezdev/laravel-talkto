<?php

namespace Mrezdev\LaravelTalkto\Pipelines;

use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Throwable;

class SendOutgoingTalktoMessagePipeline
{
    private int $talktoMessageId;

    public function __construct(private ?TalktoHttpClient $httpClient = null) {}

    public function send(int $talktoMessageId, TalktoOutgoingEnvelopeBuilder $builder, ?TalktoRetryPolicy $retryPolicy = null): void
    {
        $this->talktoMessageId = $talktoMessageId;
        $retryPolicy ??= app(TalktoRetryPolicy::class);
        $messageClass = $this->messageModelClass();
        $message = $messageClass::query()->find($this->talktoMessageId);

        if (! $message) {
            return;
        }

        if ($message->direction !== 'outgoing') {
            $this->createSkippedAttempt(
                $message,
                'invalid_direction',
                'Only outgoing Talkto messages can be sent by this job.'
            );

            return;
        }

        if (! $this->isSendable($message, $retryPolicy)) {
            $this->createSkippedAttempt(
                $message,
                'not_sendable',
                'Message is not eligible for transport sending.'
            );

            return;
        }

        $sending = $this->markSending($retryPolicy);

        if ($sending === null) {
            return;
        }

        [$message, $attempt] = $sending;

        try {
            $endpoint = $builder->endpointFor($message);
            $envelope = $builder->buildEnvelope($message);
            $headers = $builder->buildHeaders($message);
            $timeout = $builder->timeoutFor($message);

            $response = $this->httpClient()->post($endpoint, $headers, $envelope, $timeout);

            if ($response->successful()) {
                $this->applySuccessfulResponse($message, $attempt, $response);

                return;
            }

            $this->applyFailedResponse($message, $attempt, $response, $retryPolicy);
        } catch (Throwable $throwable) {
            $this->applyUnexpectedFailure($message, $attempt, $throwable, $retryPolicy);
        }
    }

    private function isSendable(TalktoMessage $message, TalktoRetryPolicy $retryPolicy): bool
    {
        if (in_array($message->overall_status, ['sending', 'sent', 'destination_received', 'succeeded', 'completed', 'failed_final'], true)) {
            return false;
        }

        return in_array($message->overall_status, ['waiting_to_send'], true)
            || in_array($message->transport_status, ['pending'], true)
            || ($retryPolicy->canRetry($message) && $retryPolicy->isDue($message));
    }

    private function senderLockName(): string
    {
        $hostname = function_exists('gethostname') ? gethostname() : false;
        $processId = function_exists('getmypid') ? getmypid() : false;

        return 'sender:'.($hostname ?: 'unknown-host').':'.($processId ?: 'unknown-process');
    }

    private function httpClient(): TalktoHttpClient
    {
        return $this->httpClient ??= app(TalktoHttpClient::class);
    }

    private function createSkippedAttempt(TalktoMessage $message, string $errorClass, string $errorMessage): void
    {
        $attemptClass = $this->attemptModelClass();

        $attemptClass::create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'stage' => 'transport',
            'attempt_no' => ((int) $message->getAttribute('attempts')) + 1,
            'status' => 'skipped',
            'error_class' => $errorClass,
            'error_message' => $errorMessage,
        ]);
    }

    private function markSending(TalktoRetryPolicy $retryPolicy): ?array
    {
        $messageClass = $this->messageModelClass();
        $attemptClass = $this->attemptModelClass();
        $eventClass = $this->eventModelClass();

        return DB::transaction(function () use ($messageClass, $attemptClass, $eventClass, $retryPolicy): ?array {
            $message = $messageClass::query()
                ->whereKey($this->talktoMessageId)
                ->lockForUpdate()
                ->first();

            if (! $message) {
                return null;
            }

            if ($message->direction !== 'outgoing') {
                $this->createSkippedAttempt(
                    $message,
                    'invalid_direction',
                    'Only outgoing Talkto messages can be sent by this job.'
                );

                return null;
            }

            if (! $this->isSendable($message, $retryPolicy)) {
                $this->createSkippedAttempt(
                    $message,
                    'not_sendable',
                    'Message is not eligible for transport sending.'
                );

                return null;
            }

            $previousStatus = $message->overall_status;
            $attemptNo = ((int) $message->getAttribute('attempts')) + 1;
            $sourceService = $message->source_service;
            $targetService = $message->target_service;
            $command = $message->command;
            $businessKey = $message->business_key;
            $idempotencyKey = $message->idempotency_key;

            $message->forceFill([
                'attempts' => $attemptNo,
                'transport_status' => 'sending',
                'overall_status' => 'sending',
                'last_attempted_at' => now(),
                'next_retry_at' => null,
                'next_attempt_at' => null,
                'locked_at' => now(),
                'locked_by' => $this->senderLockName(),
            ])->save();

            $attempt = $attemptClass::create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'stage' => 'transport',
                'attempt_no' => $attemptNo,
                'status' => 'sending',
                'meta' => [
                    'source' => $sourceService,
                    'target' => $targetService,
                    'command' => $command,
                    'business_key' => $businessKey,
                    'idempotency_key' => $idempotencyKey,
                ],
            ]);

            $eventClass::create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'message_sending_started',
                'old_status' => $previousStatus,
                'new_status' => 'sending',
                'meta' => [
                    'target' => $targetService,
                    'command' => $command,
                    'business_key' => $businessKey,
                ],
            ]);

            return [$message->fresh(), $attempt];
        });
    }

    private function applySuccessfulResponse(TalktoMessage $message, TalktoAttempt $attempt, mixed $response): void
    {
        $json = $this->safeJsonResponse($response);
        $received = $json['received'] ?? null;
        $destinationStatus = $json['status'] ?? null;
        $overallStatus = $received === true ? 'destination_received' : 'sent';
        $responseExcerpt = $this->excerpt($response->body());
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();

        DB::transaction(function () use ($messageClass, $eventClass, $message, $attempt, $response, $received, $destinationStatus, $overallStatus, $responseExcerpt): void {
            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $message->forceFill([
                'transport_status' => 'sent',
                'sent_at' => now(),
                'last_http_status' => $response->status(),
                'last_response' => $responseExcerpt,
                'destination_receive_status' => $received === true ? 'received' : 'unknown',
                'destination_action_status' => $received === true && $destinationStatus !== null ? (string) $destinationStatus : $message->destination_action_status,
                'overall_status' => $overallStatus,
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            $attempt->forceFill([
                'status' => 'sent',
                'http_status' => $response->status(),
                'response_excerpt' => $responseExcerpt,
                'meta' => array_filter([
                    'received' => $received,
                    'destination_status' => $destinationStatus,
                ], fn (mixed $value): bool => $value !== null),
            ])->save();

            $eventClass::create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'message_sent',
                'old_status' => 'sending',
                'new_status' => $overallStatus,
                'meta' => array_filter([
                    'http_status' => $response->status(),
                    'received' => $received,
                    'destination_status' => $destinationStatus,
                ], fn (mixed $value): bool => $value !== null),
            ]);

            app(TalktoDeadLetterQueue::class)->markReprocessedForMessage($message);
        });
    }

    private function applyFailedResponse(TalktoMessage $message, TalktoAttempt $attempt, mixed $response, TalktoRetryPolicy $retryPolicy): void
    {
        $status = $response->status();
        $errorMessage = "HTTP transport failed with status [{$status}].";
        $responseExcerpt = $this->excerpt($response->body());
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();

        DB::transaction(function () use ($messageClass, $eventClass, $message, $attempt, $response, $status, $errorMessage, $responseExcerpt, $retryPolicy): void {
            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $retryableHttpStatus = $retryPolicy->isRetryableHttpStatus($status, $message);
            $retryable = $retryableHttpStatus && $retryPolicy->canScheduleRetry($message);
            $retryable
                ? $retryPolicy->markRetryableFailure($message, 'transport_status', $errorMessage, $status)
                : $retryPolicy->markFinalFailure($message, 'transport_status', $errorMessage, $status);

            $message->forceFill([
                'last_response' => $responseExcerpt,
            ])->save();

            if (! $retryable) {
                $this->storeDeadLetterIfEnabled($message, $errorMessage);
            }

            $attempt->forceFill([
                'status' => $message->overall_status,
                'http_status' => $status,
                'response_excerpt' => $responseExcerpt,
                'error_class' => 'http_error',
                'error_message' => $errorMessage,
            ])->save();

            $eventClass::create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'message_send_failed',
                'old_status' => 'sending',
                'new_status' => $message->overall_status,
                'meta' => [
                    'http_status' => $response->status(),
                    'retryable' => $retryable,
                    'retryable_http_status' => $retryableHttpStatus,
                ],
            ]);

            $this->recordRetryEvent(
                $eventClass,
                $message,
                $retryable ? 'retry_scheduled' : ($retryableHttpStatus ? 'retry_exhausted' : 'retry_not_scheduled')
            );
        });
    }

    private function applyUnexpectedFailure(TalktoMessage $message, TalktoAttempt $attempt, Throwable $throwable, TalktoRetryPolicy $retryPolicy): void
    {
        $errorMessage = $this->excerpt($throwable->getMessage());
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();

        DB::transaction(function () use ($messageClass, $eventClass, $message, $attempt, $throwable, $errorMessage, $retryPolicy): void {
            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $retryable = $retryPolicy->canScheduleRetry($message);
            $retryable
                ? $retryPolicy->markRetryableFailure($message, 'transport_status', $errorMessage)
                : $retryPolicy->markFinalFailure($message, 'transport_status', $errorMessage);

            if (! $retryable) {
                $this->storeDeadLetterIfEnabled($message, $errorMessage, $throwable);
            }

            $attempt->forceFill([
                'status' => $message->overall_status,
                'error_class' => $throwable::class,
                'error_message' => $errorMessage,
            ])->save();

            $eventClass::create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'message_send_failed',
                'old_status' => 'sending',
                'new_status' => $message->overall_status,
                'meta' => [
                    'error_class' => $throwable::class,
                    'retryable' => $retryable,
                ],
            ]);

            $this->recordRetryEvent($eventClass, $message, $retryable ? 'retry_scheduled' : 'retry_exhausted');
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
        $eventClass::create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => $eventType,
            'old_status' => 'sending',
            'new_status' => $message->overall_status,
            'meta' => $this->retryEventMeta($message, $eventType),
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
        } elseif ($eventType === 'retry_not_scheduled' && in_array($reason, ['eligible', 'not_due'], true)) {
            $reason = 'non_retryable_status';
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

    /**
     * @return class-string<TalktoMessage>
     */
    protected function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }

    /**
     * @return class-string<TalktoAttempt>
     */
    protected function attemptModelClass(): string
    {
        $class = config('talkto.models.attempt', TalktoAttempt::class);

        return is_string($class) && is_a($class, TalktoAttempt::class, true)
            ? $class
            : TalktoAttempt::class;
    }

    /**
     * @return class-string<TalktoEvent>
     */
    protected function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }

    private function excerpt(mixed $value, int $limit = 2000): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = (string) $value;

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }

    private function safeJsonResponse(mixed $response): array
    {
        try {
            $json = $response->json();

            return is_array($json) ? $json : [];
        } catch (Throwable) {
            return [];
        }
    }
}
