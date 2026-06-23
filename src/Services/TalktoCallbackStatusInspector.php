<?php

namespace Mrezdev\LaravelTalkto\Services;

use DateTimeInterface;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;

/**
 * Read-only callback status summary service for panel and operator surfaces.
 */
class TalktoCallbackStatusInspector
{
    private const CALLBACK_EVENT_TYPES = [
        'result_callback_queued',
        'result_callback_auto_dispatch_skipped',
        'result_callback_queue_failed',
        'result_callback_skipped',
        'result_callback_failed',
        'result_callback_received',
        'result_callback_applied',
        'result_callback_rejected',
        'result_callback_duplicate',
        'result_callback_stale_ignored',
    ];

    public function __construct(
        private readonly TalktoModelResolver $models,
        private readonly TalktoSecurityRedactor $redactor
    ) {}

    public function inspect(TalktoMessage $message): array
    {
        $message = $this->freshMessage($message) ?? $message;

        if ($this->isCallbackCommand($message)) {
            return $message->isOutgoing()
                ? $this->inspectOutgoingCallback($message)
                : $this->notApplicable(
                    $message,
                    'incoming_callback',
                    'Stored incoming callback messages are not used by the current callback receiver.'
                );
        }

        if ($message->isIncoming()) {
            return $this->inspectDestinationIncoming($message);
        }

        if ($message->isOutgoing()) {
            return $this->inspectSourceOutgoing($message);
        }

        return $this->notApplicable($message, 'unknown', 'Callback inspection is not applicable for this message.');
    }

    public function isApplicable(TalktoMessage $message): bool
    {
        return (bool) $this->inspect($message)['applicable'];
    }

    private function inspectDestinationIncoming(TalktoMessage $message): array
    {
        if (! $this->incomingHasResult($message)) {
            return $this->buildResult(
                message: $message,
                applicable: false,
                context: 'destination_incoming',
                state: 'callback_not_expected_yet'
            );
        }

        $callbackMessage = $this->callbackMessageForIncoming($message);

        if (! $callbackMessage) {
            return $this->buildResult(
                message: $message,
                applicable: true,
                context: 'destination_incoming',
                state: 'callback_message_missing'
            );
        }

        return $this->buildResult(
            message: $message,
            applicable: true,
            context: 'destination_incoming',
            state: $this->stateForCallbackMessage($callbackMessage),
            callbackMessage: $callbackMessage
        );
    }

    private function inspectSourceOutgoing(TalktoMessage $message): array
    {
        if (! $this->outgoingReachedDestination($message)) {
            return $this->notApplicable(
                $message,
                'source_outgoing',
                'Callback inspection is not applicable for this message yet.'
            );
        }

        return $this->buildResult(
            message: $message,
            applicable: true,
            context: 'source_outgoing',
            state: $this->stateForSourceOutgoing($message)
        );
    }

    private function inspectOutgoingCallback(TalktoMessage $message): array
    {
        return $this->buildResult(
            message: $message,
            applicable: true,
            context: 'outgoing_callback',
            state: $this->stateForCallbackMessage($message),
            callbackMessage: $message,
            parentMessage: $this->parentMessageForCallback($message)
        );
    }

    private function buildResult(
        TalktoMessage $message,
        bool $applicable,
        string $context,
        string $state,
        ?TalktoMessage $callbackMessage = null,
        ?TalktoMessage $parentMessage = null
    ): array {
        $operationalMessage = $callbackMessage ?? $message;

        return [
            'applicable' => $applicable,
            'context' => $context,
            'state' => $state,
            'label' => $this->labelForState($state),
            'summary' => $this->summaryForState($state),
            'message' => $this->messageSummary($message),
            'callback_message' => $callbackMessage ? $this->callbackMessageSummary($callbackMessage) : null,
            'parent_message' => $parentMessage ? $this->messageSummary($parentMessage) : null,
            'attempts' => $this->attemptSummary($operationalMessage),
            'events' => $this->eventSummary($message, $callbackMessage),
            'dead_letter' => $this->deadLetterSummary($operationalMessage),
        ];
    }

    private function notApplicable(TalktoMessage $message, string $context, string $summary): array
    {
        $result = $this->buildResult(
            message: $message,
            applicable: false,
            context: $context,
            state: 'not_applicable'
        );
        $result['summary'] = $summary;

        return $result;
    }

    private function callbackMessageForIncoming(TalktoMessage $message): ?TalktoMessage
    {
        $messageClass = $this->models->message();

        return $messageClass::query()
            ->where('direction', TalktoMessageDirection::Outgoing->value)
            ->where('command', $this->callbackCommand())
            ->where('parent_message_id', $message->message_id)
            ->orderByDesc('id')
            ->first();
    }

    private function parentMessageForCallback(TalktoMessage $message): ?TalktoMessage
    {
        $parentMessageId = $message->parent_message_id;

        if (! is_string($parentMessageId) || $parentMessageId === '') {
            return null;
        }

        $messageClass = $this->models->message();

        return $messageClass::query()
            ->where('message_id', $parentMessageId)
            ->orderByDesc('id')
            ->first();
    }

    private function freshMessage(TalktoMessage $message): ?TalktoMessage
    {
        if (! $message->exists) {
            return null;
        }

        $messageClass = $this->models->message();

        return $messageClass::query()->whereKey($message->getKey())->first();
    }

    private function incomingHasResult(TalktoMessage $message): bool
    {
        return $this->hasAnyStatus($message, [
            TalktoMessageStatus::Succeeded->value,
            TalktoMessageStatus::Skipped->value,
            TalktoMessageStatus::Completed->value,
            TalktoMessageStatus::FailedRetryable->value,
            TalktoMessageStatus::FailedFinal->value,
            TalktoMessageStatus::DeadLettered->value,
        ]);
    }

    private function outgoingReachedDestination(TalktoMessage $message): bool
    {
        if ($message->destination_receive_status === TalktoMessageStatus::Received->value) {
            return true;
        }

        return in_array($message->overall_status, [
            TalktoMessageStatus::DestinationReceived->value,
            TalktoMessageStatus::Succeeded->value,
            TalktoMessageStatus::Completed->value,
            TalktoMessageStatus::FailedRetryable->value,
            TalktoMessageStatus::FailedFinal->value,
            TalktoMessageStatus::DeadLettered->value,
        ], true);
    }

    private function stateForSourceOutgoing(TalktoMessage $message): string
    {
        $events = $this->eventTypesFor($message);
        $hasCallbackEvent = count(array_intersect($events, self::CALLBACK_EVENT_TYPES)) > 0;
        $hasAppliedEvent = in_array('result_callback_applied', $events, true);
        $hasReceivedEvent = in_array('result_callback_received', $events, true);
        $failedResult = in_array($message->overall_status, [
            TalktoMessageStatus::FailedRetryable->value,
            TalktoMessageStatus::FailedFinal->value,
        ], true) || in_array($message->destination_action_status, [
            TalktoMessageStatus::FailedRetryable->value,
            TalktoMessageStatus::FailedFinal->value,
        ], true);

        if (($hasAppliedEvent || $hasReceivedEvent) && $failedResult) {
            return 'callback_failed_result_received';
        }

        if ($hasAppliedEvent) {
            return 'callback_applied';
        }

        if ($message->overall_status === TalktoMessageStatus::Completed->value
            && in_array($message->destination_action_status, [
                TalktoMessageStatus::Succeeded->value,
                TalktoMessageStatus::Skipped->value,
            ], true)) {
            return 'callback_applied';
        }

        if ($failedResult && $hasCallbackEvent) {
            return 'callback_failed_result_received';
        }

        if ($failedResult) {
            return 'callback_events_missing';
        }

        if ($message->overall_status === TalktoMessageStatus::DestinationReceived->value
            || $message->destination_receive_status === TalktoMessageStatus::Received->value) {
            return 'waiting_for_callback';
        }

        return 'callback_events_missing';
    }

    private function stateForCallbackMessage(TalktoMessage $message): string
    {
        if ($this->deadLetterFor($message) !== null) {
            return 'callback_dead_lettered';
        }

        return match ($message->overall_status) {
            TalktoMessageStatus::Completed->value,
            TalktoMessageStatus::Succeeded->value => 'callback_completed',
            TalktoMessageStatus::FailedFinal->value,
            TalktoMessageStatus::DeadLettered->value => 'callback_failed_final',
            TalktoMessageStatus::FailedRetryable->value,
            TalktoMessageStatus::Failed->value => 'callback_failed_retryable',
            TalktoMessageStatus::Sending->value,
            TalktoMessageStatus::Processing->value,
            TalktoMessageStatus::Sent->value,
            TalktoMessageStatus::DestinationReceived->value,
            TalktoMessageStatus::Received->value => 'callback_sending',
            default => 'callback_queued',
        };
    }

    private function hasAnyStatus(TalktoMessage $message, array $statuses): bool
    {
        return in_array($message->overall_status, $statuses, true)
            || in_array($message->destination_action_status, $statuses, true);
    }

    private function messageSummary(TalktoMessage $message): array
    {
        return [
            'id' => $message->id,
            'message_id' => $message->message_id,
            'direction' => $message->direction,
            'command' => $message->command,
            'overall_status' => $message->overall_status,
        ];
    }

    private function callbackMessageSummary(TalktoMessage $message): array
    {
        return [
            'id' => $message->id,
            'message_id' => $message->message_id,
            'direction' => $message->direction,
            'command' => $message->command,
            'overall_status' => $message->overall_status,
            'transport_status' => $message->transport_status,
            'destination_receive_status' => $message->destination_receive_status,
            'destination_action_status' => $message->destination_action_status,
            'target_service' => $message->target_service,
            'last_http_status' => $message->last_http_status,
            'last_error' => $this->redactText($message->last_error),
            'sent_at' => $this->date($message->sent_at),
            'completed_at' => $this->date($message->completed_at),
            'failed_at' => $this->date($message->failed_at),
        ];
    }

    private function attemptSummary(TalktoMessage $message): array
    {
        $attemptClass = $this->models->attempt();
        $query = $attemptClass::query()->where('talkto_message_id', $message->id);
        $lastAttempt = (clone $query)->orderByDesc('id')->first();

        return [
            'count' => (clone $query)->count(),
            'last_status' => $lastAttempt?->status,
            'last_http_status' => $lastAttempt?->http_status,
            'last_error' => $this->redactText($lastAttempt?->error_message),
        ];
    }

    private function eventSummary(TalktoMessage $message, ?TalktoMessage $callbackMessage = null): array
    {
        $messageIds = array_values(array_filter([
            $message->id,
            $callbackMessage?->id,
        ], fn (mixed $id): bool => $id !== null));

        $eventClass = $this->models->event();
        $eventTypes = $eventClass::query()
            ->whereIn('talkto_message_id', $messageIds)
            ->pluck('event_type')
            ->map(fn (mixed $eventType): string => (string) $eventType)
            ->all();

        $summary = [];

        foreach (self::CALLBACK_EVENT_TYPES as $eventType) {
            $summary[$eventType] = in_array($eventType, $eventTypes, true);
        }

        return $summary;
    }

    private function eventTypesFor(TalktoMessage $message): array
    {
        $eventClass = $this->models->event();

        return $eventClass::query()
            ->where('talkto_message_id', $message->id)
            ->pluck('event_type')
            ->map(fn (mixed $eventType): string => (string) $eventType)
            ->all();
    }

    private function deadLetterSummary(TalktoMessage $message): array
    {
        $deadLetter = $this->deadLetterFor($message);

        return [
            'exists' => $deadLetter !== null,
            'id' => $deadLetter?->id,
            'status' => $deadLetter?->status,
            'failed_status' => $deadLetter?->failed_status,
        ];
    }

    private function deadLetterFor(TalktoMessage $message): ?TalktoDeadLetter
    {
        $deadLetterClass = $this->models->deadLetter();

        return $deadLetterClass::query()
            ->where(function ($query) use ($message): void {
                $query->where('talkto_message_id', $message->id)
                    ->orWhere('message_id', $message->message_id);
            })
            ->orderByDesc('id')
            ->first();
    }

    private function callbackCommand(): string
    {
        $command = config('talkto.callbacks.command', 'talkto.result');

        return is_string($command) && trim($command) !== '' ? trim($command) : 'talkto.result';
    }

    private function isCallbackCommand(TalktoMessage $message): bool
    {
        return $message->command === $this->callbackCommand();
    }

    private function labelForState(string $state): string
    {
        return match ($state) {
            'callback_not_expected_yet' => 'Callback not expected yet',
            'callback_message_missing' => 'Callback message missing',
            'callback_queued' => 'Callback queued',
            'callback_sending' => 'Callback sending',
            'callback_completed' => 'Callback completed',
            'callback_failed_retryable' => 'Callback failed, retryable',
            'callback_failed_final' => 'Callback failed final',
            'callback_dead_lettered' => 'Callback dead-lettered',
            'waiting_for_callback' => 'Waiting for callback',
            'callback_applied' => 'Callback applied',
            'callback_failed_result_received' => 'Callback failure received',
            'callback_events_missing' => 'Callback events missing',
            default => 'Callback not applicable',
        };
    }

    private function summaryForState(string $state): string
    {
        return match ($state) {
            'callback_not_expected_yet' => 'Callback inspection is not applicable for this message yet.',
            'callback_message_missing' => 'No durable callback message was found for this processed incoming message.',
            'callback_queued' => 'A durable callback message exists and is waiting to be sent.',
            'callback_sending' => 'A durable callback message is being delivered or awaiting a terminal response.',
            'callback_completed' => 'A durable callback was delivered and applied.',
            'callback_failed_retryable' => 'The durable callback delivery failed and remains retryable.',
            'callback_failed_final' => 'The durable callback delivery reached a final failure state.',
            'callback_dead_lettered' => 'The durable callback message has a dead-letter record.',
            'waiting_for_callback' => 'The message reached the destination and is waiting for a result callback.',
            'callback_applied' => 'A result callback was received and applied to this message.',
            'callback_failed_result_received' => 'A result callback reported a failed destination result.',
            'callback_events_missing' => 'The message is in a callback-related state, but callback receiver events are missing.',
            default => 'Callback inspection is not applicable for this message.',
        };
    }

    private function redactText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        if ($value === '') {
            return null;
        }

        return $this->redactor->redactText($value);
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
