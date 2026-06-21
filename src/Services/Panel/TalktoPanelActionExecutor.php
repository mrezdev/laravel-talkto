<?php

namespace Mrezdev\LaravelTalkto\Services\Panel;

use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoCurrentServiceGuard;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Services\TalktoTraceReporter;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelActionResult;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;
use Mrezdev\LaravelTalkto\Support\TalktoTraceSnapshot;
use Throwable;

/**
 * @internal Optional panel implementation detail.
 */
class TalktoPanelActionExecutor
{
    public function __construct(
        private readonly TalktoRetryPolicy $retryPolicy,
        private readonly TalktoDeadLetterQueue $deadLetterQueue,
        private readonly TalktoTraceReporter $traceReporter,
        private readonly TalktoCurrentServiceGuard $currentServiceGuard,
    ) {}

    public function retryMessage(TalktoMessage $message): TalktoPanelActionResult
    {
        if (! (bool) config('talkto.panel.actions.retry_enabled', true)) {
            return TalktoPanelActionResult::failure($this->actionText('retry_disabled'));
        }

        if (! in_array($message->direction, ['incoming', 'outgoing'], true)) {
            return TalktoPanelActionResult::failure($this->actionText('unsupported_direction'), [
                'direction' => $message->direction,
            ]);
        }

        if (! $this->currentServiceGuard->allowsProcessing($message)) {
            return TalktoPanelActionResult::failure($this->actionText('message_wrong_service'), [
                'message_id' => $message->message_id,
                'current_service' => $this->currentServiceGuard->currentService(),
            ]);
        }

        if (! $this->retryPolicy->canRetry($message)) {
            $decision = $this->retryPolicy->decisionFor($message);

            return TalktoPanelActionResult::failure($this->actionText('message_not_retryable'), [
                'reason' => $decision->reason,
                'direction' => $message->direction,
                'retry_count' => (int) ($message->retry_count ?? 0),
                'max_attempts' => $this->retryPolicy->maxAttempts($message),
            ]);
        }

        $previousNextRetryAt = $message->next_retry_at?->toIso8601String();
        $message = $this->prepareMessageForManualRetry($message);
        $manualRetryAt = now()->toIso8601String();

        try {
            $this->dispatchMessageJob($message);
        } catch (Throwable $throwable) {
            $this->recordEventSafely($message, 'panel_retry_dispatch_failed', [
                'direction' => $message->direction,
                'exception_class' => $throwable::class,
                'exception_message' => $this->safeExceptionMessage($throwable),
                'panel_action' => true,
            ]);

            return TalktoPanelActionResult::failure($this->actionText('retry_dispatch_failed'), [
                'message_id' => $message->message_id,
                'direction' => $message->direction,
                'exception_class' => $throwable::class,
            ]);
        }

        $this->recordEvent($message, 'panel_retry_dispatched', [
            'direction' => $message->direction,
            'retry_count' => (int) ($message->retry_count ?? 0),
            'max_attempts' => $this->retryPolicy->maxAttempts($message),
            'reason' => 'manual_panel_retry',
            'panel_action' => true,
            'manual_retry_at' => $manualRetryAt,
            'previous_next_retry_at' => $previousNextRetryAt,
        ]);

        return TalktoPanelActionResult::success($this->actionText('retry_dispatched'), [
            'message_id' => $message->message_id,
            'direction' => $message->direction,
        ]);
    }

    public function reprocessDeadLetter(TalktoDeadLetter $deadLetter, bool $force = false): TalktoPanelActionResult
    {
        if (! (bool) config('talkto.panel.actions.dead_letter_reprocess_enabled', true)) {
            return TalktoPanelActionResult::failure($this->actionText('reprocess_disabled'));
        }

        if (! $this->deadLetterQueue->canReprocess($deadLetter, $force)) {
            return TalktoPanelActionResult::failure($this->actionText('dead_letter_not_reprocessable'), [
                'dead_letter_id' => $deadLetter->id,
                'status' => $deadLetter->status,
            ]);
        }

        $message = $this->findOriginalMessage($deadLetter);

        if (! $message) {
            $this->recordMissingOriginalEvent($deadLetter);

            return TalktoPanelActionResult::failure($this->actionText('original_message_not_found'), [
                'dead_letter_id' => $deadLetter->id,
                'message_id' => $deadLetter->message_id,
            ]);
        }

        if (! $this->currentServiceGuard->allowsProcessing($message)) {
            return TalktoPanelActionResult::failure($this->actionText('original_message_wrong_service'), [
                'dead_letter_id' => $deadLetter->id,
                'message_id' => $deadLetter->message_id,
                'current_service' => $this->currentServiceGuard->currentService(),
            ]);
        }

        if (in_array($message->overall_status, ['succeeded', 'completed'], true)) {
            $this->deadLetterQueue->recordEvent($message, 'panel_dead_letter_reprocess_skipped', [
                'dead_letter_id' => $deadLetter->id,
                'reason' => 'terminal_success',
                'panel_action' => true,
            ]);

            return TalktoPanelActionResult::failure($this->actionText('original_message_succeeded'));
        }

        if (! in_array($message->direction, ['incoming', 'outgoing'], true)) {
            $this->deadLetterQueue->recordEvent($message, 'panel_dead_letter_reprocess_skipped', [
                'dead_letter_id' => $deadLetter->id,
                'reason' => 'unsupported_direction',
                'direction' => $message->direction,
                'panel_action' => true,
            ]);

            return TalktoPanelActionResult::failure($this->actionText('original_message_unsupported_direction'), [
                'direction' => $message->direction,
            ]);
        }

        $claimed = $this->deadLetterQueue->claimForReprocess($deadLetter, $force);

        if (! $claimed) {
            return TalktoPanelActionResult::failure($this->actionText('dead_letter_claim_failed'));
        }

        $this->prepareOriginalMessageForReprocess($message);

        try {
            $this->dispatchMessageJob($message);
        } catch (Throwable $throwable) {
            $this->deadLetterQueue->markFailedReprocess($claimed, $this->actionText('dispatch_failed'), $throwable);
            $this->recordEventSafely($message, 'panel_dead_letter_reprocess_dispatch_failed', [
                'dead_letter_id' => $claimed->id,
                'direction' => $message->direction,
                'exception_class' => $throwable::class,
                'exception_message' => $this->safeExceptionMessage($throwable),
                'panel_action' => true,
            ]);

            return TalktoPanelActionResult::failure($this->actionText('dead_letter_reprocess_dispatch_failed'), [
                'dead_letter_id' => $claimed->id,
                'message_id' => $message->message_id,
                'direction' => $message->direction,
                'exception_class' => $throwable::class,
            ]);
        }

        $this->deadLetterQueue->recordEvent($message, 'panel_dead_letter_reprocess_dispatched', [
            'dead_letter_id' => $claimed->id,
            'direction' => $message->direction,
            'reprocess_count' => (int) $claimed->reprocess_count,
            'panel_action' => true,
        ]);

        return TalktoPanelActionResult::success($this->actionText('dead_letter_reprocess_dispatched'), [
            'dead_letter_id' => $claimed->id,
            'message_id' => $message->message_id,
            'direction' => $message->direction,
        ]);
    }

    public function traceMessage(TalktoMessage $message, bool $includePayload = false, int $limit = 100): TalktoTraceSnapshot
    {
        $includePayload = $includePayload && (bool) config('talkto.panel.messages.show_payload', false);

        return $this->traceReporter->traceByMessageId($message->message_id, $limit, $includePayload);
    }

    private function prepareMessageForManualRetry(TalktoMessage $message): TalktoMessage
    {
        $messageClass = $this->messageModelClass();

        return DB::transaction(function () use ($messageClass, $message): TalktoMessage {
            $locked = $messageClass::query()
                ->whereKey($message->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof TalktoMessage) {
                $locked = $message;
            }

            $now = now();
            $locked->forceFill([
                'next_retry_at' => $now,
                'next_attempt_at' => $now,
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            return $locked->fresh() ?? $locked;
        });
    }

    private function findOriginalMessage(TalktoDeadLetter $deadLetter): ?TalktoMessage
    {
        $messageClass = $this->messageModelClass();

        if ($deadLetter->talkto_message_id !== null) {
            $message = $messageClass::query()->whereKey($deadLetter->talkto_message_id)->first();

            if ($message instanceof TalktoMessage) {
                return $message;
            }
        }

        if ($deadLetter->message_id === null || $deadLetter->message_id === '') {
            return null;
        }

        return $messageClass::query()->where('message_id', $deadLetter->message_id)->first();
    }

    private function prepareOriginalMessageForReprocess(TalktoMessage $message): void
    {
        $attributes = [
            'next_retry_at' => null,
            'next_attempt_at' => null,
            'locked_at' => null,
            'locked_by' => null,
        ];

        if ($message->direction === 'outgoing') {
            $attributes['transport_status'] = 'pending';
            $attributes['overall_status'] = 'waiting_to_send';
        }

        if ($message->direction === 'incoming') {
            $attributes['destination_action_status'] = 'queued';
            $attributes['overall_status'] = 'queued';
        }

        $message->forceFill($attributes)->save();
    }

    private function dispatchMessageJob(TalktoMessage $message): void
    {
        if ($message->direction === 'outgoing') {
            $jobClass = $this->sendJobClass();
            $jobClass::dispatch($message->id);

            return;
        }

        if ($message->direction === 'incoming') {
            $jobClass = $this->processIncomingJobClass();
            $jobClass::dispatch($message->id);
        }
    }

    private function recordEvent(TalktoMessage $message, string $eventType, array $meta): void
    {
        $eventClass = $this->eventModelClass();

        $eventClass::query()->create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => $eventType,
            'old_status' => $message->overall_status,
            'new_status' => $message->overall_status,
            'meta' => $meta,
        ]);
    }

    private function recordEventSafely(TalktoMessage $message, string $eventType, array $meta): void
    {
        try {
            $this->recordEvent($message, $eventType, $meta);
        } catch (Throwable) {
            //
        }
    }

    private function recordMissingOriginalEvent(TalktoDeadLetter $deadLetter): void
    {
        $eventClass = $this->eventModelClass();

        $eventClass::query()->create([
            'talkto_message_id' => null,
            'message_id' => $deadLetter->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => 'panel_dead_letter_reprocess_missing_original',
            'old_status' => $deadLetter->status,
            'new_status' => $deadLetter->status,
            'meta' => [
                'dead_letter_id' => $deadLetter->id,
                'panel_action' => true,
            ],
        ]);
    }

    private function safeExceptionMessage(Throwable $throwable): string
    {
        $defaultMessage = $this->actionText('dispatch_failed');
        $message = app(TalktoSecurityRedactor::class)->redactText($throwable->getMessage()) ?? $defaultMessage;
        $message = trim($message);

        if ($message === '') {
            return $defaultMessage;
        }

        return mb_substr($message, 0, 300);
    }

    private function actionText(string $key): string
    {
        return (string) __("talkto::panel.actions.{$key}");
    }

    private function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }

    private function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }

    private function sendJobClass(): string
    {
        $class = config('talkto.jobs.send_message', SendTalktoMessage::class);

        return is_string($class) && is_a($class, SendTalktoMessage::class, true)
            ? $class
            : SendTalktoMessage::class;
    }

    private function processIncomingJobClass(): string
    {
        $class = config('talkto.jobs.process_incoming', ProcessIncomingTalktoMessage::class);

        return is_string($class) && is_a($class, ProcessIncomingTalktoMessage::class, true)
            ? $class
            : ProcessIncomingTalktoMessage::class;
    }
}
