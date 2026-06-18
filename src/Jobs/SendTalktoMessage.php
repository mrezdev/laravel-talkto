<?php

namespace Ibake\TalktoReliable\Jobs;

use Ibake\TalktoReliable\Models\TalktoAttempt;
use Ibake\TalktoReliable\Models\TalktoEvent;
use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Services\TalktoOutgoingEnvelopeBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class SendTalktoMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $talktoMessageId) {}

    public function handle(TalktoOutgoingEnvelopeBuilder $builder): void
    {
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

        if (! $this->isSendable($message)) {
            $this->createSkippedAttempt(
                $message,
                'not_sendable',
                'Message is not eligible for transport sending.'
            );

            return;
        }

        $sending = $this->markSending();

        if ($sending === null) {
            return;
        }

        [$message, $attempt] = $sending;

        try {
            $endpoint = $builder->endpointFor($message);
            $envelope = $builder->buildEnvelope($message);
            $headers = $builder->buildHeaders($message);

            $response = Http::withHeaders($headers)
                ->timeout((int) config('talkto.http.timeout_seconds', 20))
                ->post($endpoint, $envelope);

            if ($response->successful()) {
                $this->applySuccessfulResponse($message, $attempt, $response);

                return;
            }

            $this->applyFailedResponse($message, $attempt, $response);
        } catch (Throwable $throwable) {
            $this->applyUnexpectedFailure($message, $attempt, $throwable);
        }
    }

    private function isSendable(TalktoMessage $message): bool
    {
        return in_array($message->overall_status, ['waiting_to_send', 'failed_retryable'], true)
            || in_array($message->transport_status, ['pending', 'failed'], true);
    }

    private function senderLockName(): string
    {
        $hostname = function_exists('gethostname') ? gethostname() : false;
        $processId = function_exists('getmypid') ? getmypid() : false;

        return 'sender:'.($hostname ?: 'unknown-host').':'.($processId ?: 'unknown-process');
    }

    private function createSkippedAttempt(TalktoMessage $message, string $errorClass, string $errorMessage): void
    {
        $attemptClass = $this->attemptModelClass();

        $attemptClass::create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'stage' => 'transport',
            'attempt_no' => ((int) $message->attempts) + 1,
            'status' => 'skipped',
            'error_class' => $errorClass,
            'error_message' => $errorMessage,
        ]);
    }

    private function markSending(): ?array
    {
        $messageClass = $this->messageModelClass();
        $attemptClass = $this->attemptModelClass();
        $eventClass = $this->eventModelClass();

        return DB::transaction(function () use ($messageClass, $attemptClass, $eventClass): ?array {
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

            if (! $this->isSendable($message)) {
                $this->createSkippedAttempt(
                    $message,
                    'not_sendable',
                    'Message is not eligible for transport sending.'
                );

                return null;
            }

            $previousStatus = $message->overall_status;
            $attemptNo = ((int) $message->attempts) + 1;
            $sourceService = $message->source_service;
            $targetService = $message->target_service;
            $command = $message->command;
            $businessKey = $message->business_key;
            $idempotencyKey = $message->idempotency_key;

            $message->forceFill([
                'attempts' => $attemptNo,
                'transport_status' => 'sending',
                'overall_status' => 'sending',
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
        });
    }

    private function applyFailedResponse(TalktoMessage $message, TalktoAttempt $attempt, mixed $response): void
    {
        $status = $response->status();
        $errorMessage = "HTTP transport failed with status [{$status}].";
        $responseExcerpt = $this->excerpt($response->body());
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();

        DB::transaction(function () use ($messageClass, $eventClass, $message, $attempt, $response, $status, $errorMessage, $responseExcerpt): void {
            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $message->forceFill([
                'transport_status' => 'failed',
                'overall_status' => 'failed_retryable',
                'last_http_status' => $status,
                'last_response' => $responseExcerpt,
                'last_error' => $errorMessage,
                'failed_at' => now(),
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            $attempt->forceFill([
                'status' => 'failed_retryable',
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
                'new_status' => 'failed_retryable',
                'meta' => [
                    'http_status' => $response->status(),
                    'retryable' => true,
                ],
            ]);
        });
    }

    private function applyUnexpectedFailure(TalktoMessage $message, TalktoAttempt $attempt, Throwable $throwable): void
    {
        $errorMessage = $this->excerpt($throwable->getMessage());
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();

        DB::transaction(function () use ($messageClass, $eventClass, $message, $attempt, $throwable, $errorMessage): void {
            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $message->forceFill([
                'transport_status' => 'failed',
                'overall_status' => 'failed_retryable',
                'last_error' => $errorMessage,
                'failed_at' => now(),
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            $attempt->forceFill([
                'status' => 'failed_retryable',
                'error_class' => $throwable::class,
                'error_message' => $errorMessage,
            ])->save();

            $eventClass::create([
                'talkto_message_id' => $message->id,
                'message_id' => $message->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'message_send_failed',
                'old_status' => 'sending',
                'new_status' => 'failed_retryable',
                'meta' => [
                    'error_class' => $throwable::class,
                    'retryable' => true,
                ],
            ]);
        });
    }

    protected function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }

    protected function attemptModelClass(): string
    {
        $class = config('talkto.models.attempt', TalktoAttempt::class);

        return is_string($class) && is_a($class, TalktoAttempt::class, true)
            ? $class
            : TalktoAttempt::class;
    }

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
