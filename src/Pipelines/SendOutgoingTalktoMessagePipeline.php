<?php

namespace Mrezdev\LaravelTalkto\Pipelines;

use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClientWithOptions;
use Mrezdev\LaravelTalkto\Enums\TalktoAttemptStatus;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;
use Mrezdev\LaravelTalkto\Exceptions\TalktoInvalidEnvelopeFieldException;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoEnvelopeFieldValidator;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Support\TalktoModelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use Throwable;

/**
 * @internal Runtime orchestration pipeline behind outgoing message delivery.
 */
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

        if ($message->direction !== TalktoMessageDirection::Outgoing->value) {
            $this->createSkippedAttempt(
                $message,
                'invalid_direction',
                'Only outgoing Talkto messages can be sent by this job.'
            );

            return;
        }

        if (! $this->isSendable($message, $retryPolicy)) {
            return;
        }

        if (! $this->guardStoredEnvelopeFields($message, $retryPolicy)) {
            return;
        }

        $sending = $this->markSending($retryPolicy);

        if ($sending === null) {
            return;
        }

        [$message, $attempt] = $sending;

        if (! $this->guardStoredPayloadHash($message, $attempt, $retryPolicy)) {
            return;
        }

        try {
            $endpoint = $builder->endpointFor($message);
            $envelope = $builder->buildEnvelope($message);
            $headers = $builder->buildHeaders($message);
            $timeout = $builder->timeoutFor($message);
            $httpOptions = $builder->httpOptionsFor($message);
            $isResultCallbackMessage = $builder->isResultCallbackMessage($message);

            $client = $this->httpClient();
            $response = $client instanceof TalktoHttpClientWithOptions
                ? $client->postWithOptions($endpoint, $headers, $envelope, $timeout, $httpOptions)
                : $client->post($endpoint, $headers, $envelope, $timeout);

            if ($response->successful()) {
                $this->applySuccessfulResponse($message, $attempt, $response, $retryPolicy, $isResultCallbackMessage);

                return;
            }

            $this->applyFailedResponse($message, $attempt, $response, $retryPolicy);
        } catch (TalktoInvalidEnvelopeFieldException $throwable) {
            $this->applyInvalidEnvelopeFieldFailure($message, $attempt, $retryPolicy, $throwable);
        } catch (Throwable $throwable) {
            $this->applyUnexpectedFailure($message, $attempt, $throwable, $retryPolicy);
        }
    }

    private function guardStoredPayloadHash(TalktoMessage $message, TalktoAttempt $attempt, TalktoRetryPolicy $retryPolicy): bool
    {
        try {
            $deterministicHash = app(TalktoPayloadHasher::class)->hash($message->payload);
        } catch (Throwable $throwable) {
            $this->applyStoredPayloadHashFailure(
                $message,
                $attempt,
                $retryPolicy,
                'stored_payload_hash_unencodable',
                'Stored Talkto payload could not be deterministically encoded.',
                ['error_class' => $throwable::class]
            );

            return false;
        }

        $storedHash = is_string($message->payload_hash) ? $message->payload_hash : (string) $message->payload_hash;

        if ($storedHash !== '' && hash_equals($storedHash, $deterministicHash)) {
            return true;
        }

        $this->applyStoredPayloadHashFailure(
            $message,
            $attempt,
            $retryPolicy,
            'stored_payload_hash_mismatch',
            'Stored Talkto payload hash does not match the deterministic payload hash.',
            [
                'stored_payload_hash' => $storedHash,
                'deterministic_payload_hash' => $deterministicHash,
            ]
        );

        return false;
    }

    private function guardStoredEnvelopeFields(TalktoMessage $message, TalktoRetryPolicy $retryPolicy): bool
    {
        try {
            app(TalktoEnvelopeFieldValidator::class)->validateIdentifiers([
                'message_id' => (string) $message->message_id,
                'correlation_id' => $message->correlation_id === null ? null : (string) $message->correlation_id,
                'parent_message_id' => $message->parent_message_id === null ? null : (string) $message->parent_message_id,
                'source_service' => (string) $message->source_service,
                'target_service' => (string) $message->target_service,
                'command' => (string) $message->command,
                'payload_hash' => (string) $message->payload_hash,
            ]);
        } catch (TalktoInvalidEnvelopeFieldException $exception) {
            $this->applyPreSendInvalidEnvelopeFieldFailure($message, $retryPolicy, $exception);

            return false;
        }

        return true;
    }

    private function isSendable(TalktoMessage $message, TalktoRetryPolicy $retryPolicy): bool
    {
        if (in_array($message->overall_status, [
            TalktoMessageStatus::Sending->value,
            TalktoMessageStatus::Sent->value,
            TalktoMessageStatus::DestinationReceived->value,
            TalktoMessageStatus::Succeeded->value,
            TalktoMessageStatus::Completed->value,
            TalktoMessageStatus::FailedFinal->value,
        ], true)) {
            return false;
        }

        return in_array($message->overall_status, [TalktoMessageStatus::WaitingToSend->value], true)
            || in_array($message->transport_status, [TalktoMessageStatus::Pending->value], true)
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

        TalktoModelConnection::assertSameConnection($message, $attemptClass);

        $attemptClass::create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'stage' => 'transport',
            'attempt_no' => ((int) $message->getAttribute('attempts')) + 1,
            'status' => TalktoAttemptStatus::Skipped->value,
            'error_class' => $errorClass,
            'error_message' => $errorMessage,
        ]);
    }

    private function markSending(TalktoRetryPolicy $retryPolicy): ?array
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

            if ($message->direction !== TalktoMessageDirection::Outgoing->value) {
                $this->createSkippedAttempt(
                    $message,
                    'invalid_direction',
                    'Only outgoing Talkto messages can be sent by this job.'
                );

                return null;
            }

            if (! $this->isSendable($message, $retryPolicy)) {
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
                'transport_status' => TalktoMessageStatus::Sending->value,
                'overall_status' => TalktoMessageStatus::Sending->value,
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
                'status' => TalktoAttemptStatus::Sending->value,
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
                'new_status' => TalktoMessageStatus::Sending->value,
                'meta' => [
                    'target' => $targetService,
                    'command' => $command,
                    'business_key' => $businessKey,
                ],
            ]);

            return [$message->fresh(), $attempt];
        });
    }

    private function applySuccessfulResponse(
        TalktoMessage $message,
        TalktoAttempt $attempt,
        mixed $response,
        TalktoRetryPolicy $retryPolicy,
        bool $isResultCallbackMessage = false
    ): void {
        if ($isResultCallbackMessage) {
            $this->applySuccessfulCallbackResponse($message, $attempt, $response, $retryPolicy);

            return;
        }

        $this->applySuccessfulOutgoingResponse($message, $attempt, $response);
    }

    private function applySuccessfulOutgoingResponse(TalktoMessage $message, TalktoAttempt $attempt, mixed $response): void
    {
        $json = $this->safeJsonResponse($response);
        $received = $json['received'] ?? null;
        $destinationStatus = $json['status'] ?? null;
        $overallStatus = $received === true ? TalktoMessageStatus::DestinationReceived->value : TalktoMessageStatus::Sent->value;
        $responseExcerpt = $this->excerpt($response->body());
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();

        TalktoModelConnection::assertSameConnection($message, $attempt, $eventClass);

        TalktoModelConnection::transaction($message, function () use ($messageClass, $eventClass, $message, $attempt, $response, $received, $destinationStatus, $overallStatus, $responseExcerpt): void {
            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            if ($this->hasTerminalOrAdvancedStatus($message)) {
                $attempt->forceFill([
                    'status' => TalktoAttemptStatus::Sent->value,
                    'http_status' => $response->status(),
                    'response_excerpt' => $responseExcerpt,
                    'meta' => [
                        'response_ignored' => true,
                        'reason' => 'message_already_terminal',
                        'current_status' => $message->overall_status,
                    ],
                ])->save();

                $eventClass::create([
                    'talkto_message_id' => $message->id,
                    'message_id' => $message->message_id,
                    'service_name' => config('talkto.service', 'app'),
                    'event_type' => 'message_send_response_ignored',
                    'old_status' => $message->overall_status,
                    'new_status' => $message->overall_status,
                    'meta' => [
                        'http_status' => $response->status(),
                        'reason' => 'message_already_terminal',
                    ],
                ]);

                return;
            }

            $message->forceFill([
                'transport_status' => TalktoMessageStatus::Sent->value,
                'sent_at' => now(),
                'last_http_status' => $response->status(),
                'last_response' => $responseExcerpt,
                'destination_receive_status' => $received === true ? TalktoMessageStatus::Received->value : TalktoMessageStatus::Unknown->value,
                'destination_action_status' => $received === true && $destinationStatus !== null ? (string) $destinationStatus : $message->destination_action_status,
                'overall_status' => $overallStatus,
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            $attempt->forceFill([
                'status' => TalktoAttemptStatus::Sent->value,
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
                'old_status' => TalktoMessageStatus::Sending->value,
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

    private function applySuccessfulCallbackResponse(TalktoMessage $message, TalktoAttempt $attempt, mixed $response, TalktoRetryPolicy $retryPolicy): void
    {
        $json = $this->safeJsonResponse($response);
        $accepted = $json['accepted'] ?? null;

        if ($accepted !== true) {
            $this->applyRejectedCallbackResponse($message, $attempt, $response, $retryPolicy, $json);

            return;
        }

        $callbackStatus = $this->callbackResponseStatus($json);
        $responseExcerpt = $this->excerpt($response->body());
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();

        TalktoModelConnection::assertSameConnection($message, $attempt, $eventClass);

        TalktoModelConnection::transaction($message, function () use ($messageClass, $eventClass, $message, $attempt, $response, $callbackStatus, $responseExcerpt, $json): void {
            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            if ($this->hasTerminalOrAdvancedStatus($message)) {
                $attempt->forceFill([
                    'status' => TalktoAttemptStatus::Sent->value,
                    'http_status' => $response->status(),
                    'response_excerpt' => $responseExcerpt,
                    'meta' => [
                        'result_callback_delivery' => true,
                        'response_ignored' => true,
                        'reason' => 'message_already_terminal',
                        'current_status' => $message->overall_status,
                    ],
                ])->save();

                return;
            }

            $message->forceFill([
                'transport_status' => TalktoMessageStatus::Sent->value,
                'sent_at' => now(),
                'last_http_status' => $response->status(),
                'last_response' => $responseExcerpt,
                'destination_receive_status' => TalktoMessageStatus::Received->value,
                'destination_action_status' => $callbackStatus,
                'overall_status' => TalktoMessageStatus::Completed->value,
                'completed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            $attempt->forceFill([
                'status' => TalktoAttemptStatus::Sent->value,
                'http_status' => $response->status(),
                'response_excerpt' => $responseExcerpt,
                'meta' => array_filter([
                    'result_callback_delivery' => true,
                    'accepted' => true,
                    'callback_status' => $callbackStatus,
                    'duplicate' => $json['duplicate'] ?? null,
                    'original_message_id' => $json['original_message_id'] ?? null,
                ], fn (mixed $value): bool => $value !== null),
            ])->save();

            $eventClass::create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'message_sent',
                'old_status' => TalktoMessageStatus::Sending->value,
                'new_status' => TalktoMessageStatus::Completed->value,
                'meta' => array_filter([
                    'http_status' => $response->status(),
                    'result_callback_delivery' => true,
                    'accepted' => true,
                    'callback_status' => $callbackStatus,
                    'duplicate' => $json['duplicate'] ?? null,
                    'original_message_id' => $json['original_message_id'] ?? null,
                ], fn (mixed $value): bool => $value !== null),
            ]);

            app(TalktoDeadLetterQueue::class)->markReprocessedForMessage($message);
        });
    }

    private function applyRejectedCallbackResponse(
        TalktoMessage $message,
        TalktoAttempt $attempt,
        mixed $response,
        TalktoRetryPolicy $retryPolicy,
        array $json
    ): void {
        $responseExcerpt = $this->excerpt($response->body());
        $destinationStatus = array_key_exists('accepted', $json)
            ? $this->callbackResponseStatus($json, 'rejected')
            : 'rejected';
        $errorCode = $this->callbackResponseError($json);
        $errorMessage = "Result callback delivery rejected [{$errorCode}].";
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();

        TalktoModelConnection::assertSameConnection($message, $attempt, $eventClass);

        TalktoModelConnection::transaction($message, function () use ($messageClass, $eventClass, $message, $attempt, $response, $responseExcerpt, $destinationStatus, $errorCode, $errorMessage, $retryPolicy): void {
            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            if ($this->hasTerminalOrAdvancedStatus($message)) {
                $attempt->forceFill([
                    'status' => TalktoAttemptStatus::FailedFinal->value,
                    'http_status' => $response->status(),
                    'response_excerpt' => $responseExcerpt,
                    'error_class' => 'callback_rejected',
                    'error_message' => $errorMessage,
                    'meta' => [
                        'result_callback_delivery' => true,
                        'response_ignored' => true,
                        'reason' => 'message_already_terminal',
                        'current_status' => $message->overall_status,
                        'callback_error' => $errorCode,
                    ],
                ])->save();

                return;
            }

            $retryPolicy->markFinalFailure($message, 'transport_status', $errorMessage, $response->status());

            $message->forceFill([
                'last_response' => $responseExcerpt,
                'destination_receive_status' => TalktoMessageStatus::Received->value,
                'destination_action_status' => $destinationStatus,
            ])->save();

            $this->storeDeadLetterIfEnabled($message, $errorMessage);

            $attempt->forceFill([
                'status' => $message->overall_status,
                'http_status' => $response->status(),
                'response_excerpt' => $responseExcerpt,
                'error_class' => 'callback_rejected',
                'error_message' => $errorMessage,
                'meta' => [
                    'result_callback_delivery' => true,
                    'accepted' => false,
                    'callback_status' => $destinationStatus,
                    'callback_error' => $errorCode,
                ],
            ])->save();

            $eventClass::create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'message_send_failed',
                'old_status' => TalktoMessageStatus::Sending->value,
                'new_status' => $message->overall_status,
                'meta' => [
                    'http_status' => $response->status(),
                    'result_callback_delivery' => true,
                    'accepted' => false,
                    'callback_status' => $destinationStatus,
                    'callback_error' => $errorCode,
                    'retryable' => false,
                ],
            ]);

            $this->recordRetryEvent($eventClass, $message, 'retry_not_scheduled');
        });
    }

    private function applyFailedResponse(TalktoMessage $message, TalktoAttempt $attempt, mixed $response, TalktoRetryPolicy $retryPolicy): void
    {
        $status = $response->status();
        $errorMessage = "HTTP transport failed with status [{$status}].";
        $responseExcerpt = $this->excerpt($response->body());
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();

        TalktoModelConnection::assertSameConnection($message, $attempt, $eventClass);

        TalktoModelConnection::transaction($message, function () use ($messageClass, $eventClass, $message, $attempt, $response, $status, $errorMessage, $responseExcerpt, $retryPolicy): void {
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
                'old_status' => TalktoMessageStatus::Sending->value,
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

        TalktoModelConnection::assertSameConnection($message, $attempt, $eventClass);

        TalktoModelConnection::transaction($message, function () use ($messageClass, $eventClass, $message, $attempt, $throwable, $errorMessage, $retryPolicy): void {
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
                'old_status' => TalktoMessageStatus::Sending->value,
                'new_status' => $message->overall_status,
                'meta' => [
                    'error_class' => $throwable::class,
                    'retryable' => $retryable,
                ],
            ]);

            $this->recordRetryEvent($eventClass, $message, $retryable ? 'retry_scheduled' : 'retry_exhausted');
        });
    }

    private function applyStoredPayloadHashFailure(
        TalktoMessage $message,
        TalktoAttempt $attempt,
        TalktoRetryPolicy $retryPolicy,
        string $errorCode,
        string $errorMessage,
        array $meta = []
    ): void {
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();

        TalktoModelConnection::assertSameConnection($message, $attempt, $eventClass);

        TalktoModelConnection::transaction($message, function () use ($messageClass, $eventClass, $message, $attempt, $retryPolicy, $errorCode, $errorMessage, $meta): void {
            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $retryPolicy->markFinalFailure($message, 'transport_status', $errorCode);

            $this->storeDeadLetterIfEnabled($message, $errorCode);

            $attempt->forceFill([
                'status' => $message->overall_status,
                'error_class' => $errorCode,
                'error_message' => $errorMessage,
                'meta' => array_merge([
                    'retryable' => false,
                    'review_required' => true,
                ], $meta),
            ])->save();

            $eventClass::create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'message_send_failed',
                'old_status' => TalktoMessageStatus::Sending->value,
                'new_status' => $message->overall_status,
                'meta' => array_merge([
                    'error_code' => $errorCode,
                    'retryable' => false,
                    'review_required' => true,
                ], $meta),
            ]);

            $this->recordRetryEvent($eventClass, $message, 'retry_not_scheduled');
        });
    }

    private function applyInvalidEnvelopeFieldFailure(
        TalktoMessage $message,
        TalktoAttempt $attempt,
        TalktoRetryPolicy $retryPolicy,
        TalktoInvalidEnvelopeFieldException $exception
    ): void {
        $this->applyStoredPayloadHashFailure(
            $message,
            $attempt,
            $retryPolicy,
            $exception->errorCode,
            $exception->getMessage(),
            [
                'field_name' => $exception->field,
                'reason' => $exception->reason,
            ]
        );
    }

    private function applyPreSendInvalidEnvelopeFieldFailure(
        TalktoMessage $message,
        TalktoRetryPolicy $retryPolicy,
        TalktoInvalidEnvelopeFieldException $exception
    ): void {
        $messageClass = $this->messageModelClass();
        $attemptClass = $this->attemptModelClass();
        $eventClass = $this->eventModelClass();

        TalktoModelConnection::assertSameConnection($message, $attemptClass, $eventClass);

        TalktoModelConnection::transaction($message, function () use ($messageClass, $attemptClass, $eventClass, $message, $retryPolicy, $exception): void {
            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            if (! $this->isSendable($message, $retryPolicy)) {
                return;
            }

            $attemptNo = ((int) $message->getAttribute('attempts')) + 1;
            $previousStatus = $message->overall_status;

            $message->forceFill(['attempts' => $attemptNo])->save();
            $retryPolicy->markFinalFailure($message, 'transport_status', $exception->errorCode);

            $this->storeDeadLetterIfEnabled($message, $exception->errorCode);

            $attemptClass::create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'stage' => 'transport',
                'attempt_no' => $attemptNo,
                'status' => $message->overall_status,
                'error_class' => $exception->errorCode,
                'error_message' => $exception->getMessage(),
                'meta' => [
                    'retryable' => false,
                    'review_required' => true,
                    'field_name' => $exception->field,
                    'reason' => $exception->reason,
                ],
            ]);

            $eventClass::create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'message_send_failed',
                'old_status' => $previousStatus,
                'new_status' => $message->overall_status,
                'meta' => [
                    'error_code' => $exception->errorCode,
                    'retryable' => false,
                    'review_required' => true,
                    'field_name' => $exception->field,
                    'reason' => $exception->reason,
                ],
            ]);

            $this->recordRetryEvent($eventClass, $message, 'retry_not_scheduled');
        });
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
        $eventClass::create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => $eventType,
            'old_status' => TalktoMessageStatus::Sending->value,
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

    private function callbackResponseStatus(array $json, string $default = TalktoMessageStatus::Unknown->value): string
    {
        $status = $json['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : $default;
    }

    private function callbackResponseError(array $json): string
    {
        $error = $json['error'] ?? null;

        if (is_string($error) && $error !== '') {
            return $error;
        }

        if (! array_key_exists('accepted', $json)) {
            return 'invalid_callback_response';
        }

        $status = $json['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : 'callback_rejected';
    }

    private function hasTerminalOrAdvancedStatus(TalktoMessage $message): bool
    {
        return in_array($message->overall_status, [
            TalktoMessageStatus::Completed->value,
            TalktoMessageStatus::Succeeded->value,
            TalktoMessageStatus::FailedFinal->value,
        ], true);
    }

    /**
     * @return class-string<TalktoMessage>
     */
    protected function messageModelClass(): string
    {
        return app(TalktoModelResolver::class)->message();
    }

    /**
     * @return class-string<TalktoAttempt>
     */
    protected function attemptModelClass(): string
    {
        return app(TalktoModelResolver::class)->attempt();
    }

    /**
     * @return class-string<TalktoEvent>
     */
    protected function eventModelClass(): string
    {
        return app(TalktoModelResolver::class)->event();
    }

    protected function deadLetterModelClass(): string
    {
        return app(TalktoModelResolver::class)->deadLetter();
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
