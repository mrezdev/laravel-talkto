<?php

namespace Mrezdev\LaravelTalkto\Enums;

/**
 * @internal Internal persisted attempt status values.
 */
enum TalktoAttemptStatus: string
{
    case Started = 'started';
    case Processing = 'processing';
    case Sending = 'sending';
    case Sent = 'sent';
    case Succeeded = 'succeeded';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case FailedRetryable = 'failed_retryable';
    case FailedFinal = 'failed_final';
}
