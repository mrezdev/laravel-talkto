<?php

namespace Mrezdev\LaravelTalkto\Enums;

/**
 * @internal Internal persisted message lifecycle status values.
 */
enum TalktoMessageStatus: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Pending = 'pending';
    case Processing = 'processing';
    case WaitingToSend = 'waiting_to_send';
    case Sending = 'sending';
    case Sent = 'sent';
    case Received = 'received';
    case DestinationReceived = 'destination_received';
    case Succeeded = 'succeeded';
    case SucceededAssumed = 'succeeded_assumed';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case FailedRetryable = 'failed_retryable';
    case FailedFinal = 'failed_final';
    case DeadLettered = 'dead_lettered';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public function isSuccessfulCompletion(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Completed,
        ], true);
    }

    /**
     * @return array<int, string>
     */
    public static function successfulCompletionValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isSuccessfulCompletion())
        ));
    }
}
