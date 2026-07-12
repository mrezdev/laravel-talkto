<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Database\Eloquent\Model;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Mrezdev\LaravelTalkto\Support\TalktoModelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;
use Throwable;

/**
 * @internal Default sender behind ResultCallbackSenderContract.
 */
class TalktoResultCallbackSender implements ResultCallbackSenderContract
{
    public function __construct(
        private readonly TalktoResultCallbackMessageFactory $callbackMessages,
        private readonly TalktoSecurityRedactor $redactor,
        private readonly TalktoDispatchClaimingService $dispatchClaims,
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
        $callbackClaimId = null;

        try {
            if (! $message instanceof TalktoMessage) {
                throw new \InvalidArgumentException('Talkto result callbacks require a TalktoMessage instance.');
            }

            $callbackMessage = $this->callbackMessages->createForIncomingResult($message, $result, $options);
            $decision = $this->prepareDispatchDecision($message, $callbackMessage);
            $callbackMessage = $decision['callback_message'];
            $callbackClaimId = $decision['claim_id'] ?? null;

            if (! $decision['dispatch']) {
                return $this->duplicateSummary($message, $callbackMessage);
            }

            TalktoDispatchTestHooks::fire('dispatch.before_queue', [
                'operation' => 'result-callback',
                'message_db_id' => $callbackMessage->id,
                'message_id' => $callbackMessage->message_id,
                'direction' => $callbackMessage->direction,
                'claim_id' => $callbackClaimId,
            ]);

            $this->dispatchSendJob((int) $callbackMessage->id);

            return $this->queuedSummary($message, $callbackMessage);
        } catch (Throwable $throwable) {
            $failureMeta = [
                'callback_message_id' => $callbackMessage?->message_id,
                'callback_message_db_id' => $callbackMessage?->id,
                'target' => $callbackMessage?->target_service,
                'command' => $callbackMessage?->command,
                'error_class' => $throwable::class,
                'error_message' => $this->excerpt($throwable->getMessage(), 500),
            ];

            if ($message instanceof TalktoMessage && $callbackMessage instanceof TalktoMessage && is_string($callbackClaimId)) {
                try {
                    $this->dispatchClaims->compensateCallbackQueueDispatchFailure($message, $callbackMessage, $callbackClaimId, $failureMeta);
                } catch (Throwable) {
                    //
                }
            } else {
                $this->recordEvent($message, 'result_callback_queue_failed', null, 'failed', $failureMeta);
            }

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

        if ($message instanceof TalktoMessage) {
            TalktoModelConnection::assertSameConnection($message, $eventClass);
        }

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
        return app(TalktoModelResolver::class)->event();
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

    /**
     * @return array{dispatch: bool, callback_message: TalktoMessage, claim_id?: string|null}
     */
    private function prepareDispatchDecision(TalktoMessage $message, TalktoMessage $callbackMessage): array
    {
        TalktoModelConnection::assertSameConnection($callbackMessage, $this->eventModelClass());

        return TalktoModelConnection::transaction($callbackMessage, function () use ($message, $callbackMessage): array {
            $lockedCallbackMessage = $callbackMessage->newQuery()
                ->whereKey($callbackMessage->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedCallbackMessage instanceof TalktoMessage) {
                throw new \RuntimeException('Talkto result callback message could not be locked.');
            }

            if ($this->alreadyHandled($lockedCallbackMessage)) {
                return [
                    'dispatch' => false,
                    'callback_message' => $lockedCallbackMessage,
                    'claim_id' => null,
                ];
            }

            if ($this->hasActiveQueuedEvent($message, $lockedCallbackMessage)) {
                return [
                    'dispatch' => false,
                    'callback_message' => $lockedCallbackMessage,
                    'claim_id' => null,
                ];
            }

            $claimId = $this->dispatchClaims->newClaimId('result-callback');
            $lockedCallbackMessage->forceFill([
                'locked_at' => now(),
                'locked_by' => $claimId,
            ])->save();

            $lockedCallbackMessage = $lockedCallbackMessage->fresh() ?? $lockedCallbackMessage;

            $this->recordQueuedEvent($message, $lockedCallbackMessage, $claimId);

            return [
                'dispatch' => true,
                'callback_message' => $lockedCallbackMessage,
                'claim_id' => $claimId,
            ];
        });
    }

    private function recordQueuedEvent(Model $message, TalktoMessage $callbackMessage, string $claimId): void
    {
        $this->recordEvent($message, 'result_callback_queued', null, 'queued', [
            'callback_message_id' => $callbackMessage->message_id,
            'callback_message_db_id' => $callbackMessage->id,
            'target' => $callbackMessage->target_service,
            'command' => $callbackMessage->command,
            'callback_status' => $this->callbackStatus($callbackMessage),
            'durable' => true,
            'claim_id' => $claimId,
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
            TalktoMessageStatus::Sending->value,
            TalktoMessageStatus::Sent->value,
        ], true);
    }

    private function hasActiveQueuedEvent(Model $message, TalktoMessage $callbackMessage): bool
    {
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
        $queued = false;

        $eventClass::query()
            ->where('message_id', (string) ($message->message_id ?? ''))
            ->whereIn('event_type', ['result_callback_queued', 'result_callback_queue_failed'])
            ->orderBy('id')
            ->get()
            ->each(function (TalktoEvent $event) use (&$queued, $callbackMessage): void {
                if (! $this->eventMatchesCallback($event, $callbackMessage)) {
                    return;
                }

                $queued = $event->event_type === 'result_callback_queued';
            });

        return $queued;
    }

    private function eventMatchesCallback(TalktoEvent $event, TalktoMessage $callbackMessage): bool
    {
        $meta = $event->meta ?? [];

        return ($meta['callback_message_id'] ?? null) === $callbackMessage->message_id
            || (int) ($meta['callback_message_db_id'] ?? 0) === (int) $callbackMessage->id;
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
