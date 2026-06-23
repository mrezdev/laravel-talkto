<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Database\Eloquent\Model;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;
use Throwable;

/**
 * @internal Default sender behind ResultCallbackSenderContract.
 */
class TalktoResultCallbackSender implements ResultCallbackSenderContract
{
    public function __construct(
        private readonly TalktoResultCallbackMessageFactory $callbackMessages,
        private readonly TalktoSecurityRedactor $redactor
    ) {}

    public function sendResult(Model $message, IncomingCommandResultContract $result, array $options = []): array
    {
        if (! config('talkto.callbacks.enabled', true)) {
            $this->recordEvent($message, 'result_callback_skipped', null, 'skipped', [
                'error' => 'callbacks_disabled',
            ]);

            return [
                'sent' => false,
                'queued' => false,
                'status' => 'skipped',
                'message_id' => null,
                'original_message_id' => (string) ($message->message_id ?? ''),
                'error' => 'callbacks_disabled',
            ];
        }

        if (($message->direction ?? null) !== 'incoming') {
            $this->recordEvent($message, 'result_callback_failed', null, 'skipped', [
                'error' => 'invalid_direction',
            ]);

            return [
                'sent' => false,
                'queued' => false,
                'status' => 'skipped',
                'message_id' => null,
                'original_message_id' => (string) ($message->message_id ?? ''),
                'error' => 'invalid_direction',
            ];
        }

        $callbackMessage = null;

        try {
            if (! $message instanceof TalktoMessage) {
                throw new \InvalidArgumentException('Talkto result callbacks require a TalktoMessage instance.');
            }

            $callbackMessage = $this->callbackMessages->createForIncomingResult($message, $result, $options);

            if ($this->alreadyHandled($callbackMessage)) {
                return $this->duplicateSummary($message, $callbackMessage);
            }

            if ($this->alreadyQueued($message, $callbackMessage)) {
                return $this->duplicateSummary($message, $callbackMessage);
            }

            $this->dispatchSendJob((int) $callbackMessage->id);
            $this->recordQueuedEvent($message, $callbackMessage);

            return $this->queuedSummary($message, $callbackMessage);
        } catch (Throwable $throwable) {
            $this->recordEvent($message, 'result_callback_queue_failed', null, 'failed', [
                'callback_message_id' => $callbackMessage?->message_id,
                'callback_message_db_id' => $callbackMessage?->id,
                'target' => $callbackMessage?->target_service,
                'command' => $callbackMessage?->command,
                'error_class' => $throwable::class,
                'error_message' => $this->excerpt($throwable->getMessage(), 500),
            ]);

            return [
                'sent' => false,
                'queued' => false,
                'status' => 'failed',
                'message_id' => $callbackMessage?->message_id,
                'callback_message_id' => $callbackMessage?->message_id,
                'callback_message_db_id' => $callbackMessage?->id,
                'original_message_id' => (string) ($message->message_id ?? ''),
                'error' => 'queue_failed',
            ];
        }
    }

    private function recordEvent(Model $message, string $eventType, ?string $oldStatus, ?string $newStatus, array $meta = []): void
    {
        $eventClass = $this->eventModelClass();

        $eventClass::query()->create([
            'talkto_message_id' => $message->id,
            'message_id' => (string) ($message->message_id ?? ''),
            'service_name' => config('talkto.service', 'app'),
            'event_type' => $eventType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'meta' => array_filter($meta, fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }

    private function dispatchSendJob(int $messageId): void
    {
        $jobClass = $this->sendJobClass();
        $pendingDispatch = $jobClass::dispatch($messageId);

        if (is_object($pendingDispatch) && method_exists($pendingDispatch, 'afterCommit')) {
            $pendingDispatch->afterCommit();
        }
    }

    private function sendJobClass(): string
    {
        $class = config('talkto.jobs.send_message', SendTalktoMessage::class);

        return is_string($class) && is_a($class, SendTalktoMessage::class, true)
            ? $class
            : SendTalktoMessage::class;
    }

    private function recordQueuedEvent(Model $message, TalktoMessage $callbackMessage): void
    {
        $this->recordEvent($message, 'result_callback_queued', null, 'queued', [
            'callback_message_id' => $callbackMessage->message_id,
            'callback_message_db_id' => $callbackMessage->id,
            'target' => $callbackMessage->target_service,
            'command' => $callbackMessage->command,
            'callback_status' => $this->callbackStatus($callbackMessage),
            'durable' => true,
        ]);
    }

    private function queuedSummary(Model $message, TalktoMessage $callbackMessage): array
    {
        return [
            'sent' => false,
            'queued' => true,
            'status' => 'queued',
            'message_id' => $callbackMessage->message_id,
            'callback_message_id' => $callbackMessage->message_id,
            'callback_message_db_id' => $callbackMessage->id,
            'original_message_id' => (string) ($message->message_id ?? ''),
            'target' => $callbackMessage->target_service,
            'command' => $callbackMessage->command,
        ];
    }

    private function duplicateSummary(Model $message, TalktoMessage $callbackMessage): array
    {
        return [
            'sent' => false,
            'queued' => false,
            'status' => $callbackMessage->overall_status,
            'message_id' => $callbackMessage->message_id,
            'callback_message_id' => $callbackMessage->message_id,
            'callback_message_db_id' => $callbackMessage->id,
            'original_message_id' => (string) ($message->message_id ?? ''),
            'target' => $callbackMessage->target_service,
            'command' => $callbackMessage->command,
            'duplicate' => true,
        ];
    }

    private function alreadyHandled(TalktoMessage $callbackMessage): bool
    {
        return in_array($callbackMessage->overall_status, [
            TalktoMessageStatus::Completed->value,
            TalktoMessageStatus::Succeeded->value,
            TalktoMessageStatus::FailedFinal->value,
            TalktoMessageStatus::DestinationReceived->value,
            TalktoMessageStatus::Sent->value,
        ], true);
    }

    private function alreadyQueued(Model $message, TalktoMessage $callbackMessage): bool
    {
        if ($callbackMessage->wasRecentlyCreated) {
            return false;
        }

        if (! in_array($callbackMessage->overall_status, [
            TalktoMessageStatus::Created->value,
            TalktoMessageStatus::Queued->value,
            TalktoMessageStatus::Pending->value,
            TalktoMessageStatus::WaitingToSend->value,
            TalktoMessageStatus::Sending->value,
            TalktoMessageStatus::FailedRetryable->value,
        ], true)) {
            return false;
        }

        $eventClass = $this->eventModelClass();

        return $eventClass::query()
            ->where('message_id', (string) ($message->message_id ?? ''))
            ->where('event_type', 'result_callback_queued')
            ->get()
            ->contains(function (TalktoEvent $event) use ($callbackMessage): bool {
                $meta = $event->meta ?? [];

                return ($meta['callback_message_id'] ?? null) === $callbackMessage->message_id
                    || (int) ($meta['callback_message_db_id'] ?? 0) === (int) $callbackMessage->id;
            });
    }

    private function callbackStatus(TalktoMessage $callbackMessage): ?string
    {
        $payload = $callbackMessage->payload;

        if (! is_array($payload)) {
            return null;
        }

        $status = $payload['status'] ?? null;

        return is_scalar($status) && (string) $status !== '' ? (string) $status : null;
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

        return mb_substr((string) $this->redactor->redactText($value), 0, $limit);
    }
}
