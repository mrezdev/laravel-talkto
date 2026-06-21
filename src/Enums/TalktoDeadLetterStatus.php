<?php

namespace Mrezdev\LaravelTalkto\Enums;

/**
 * @internal Internal persisted dead-letter lifecycle status values.
 */
enum TalktoDeadLetterStatus: string
{
    case Open = 'open';
    case Reprocessing = 'reprocessing';
    case Reprocessed = 'reprocessed';
    case FailedReprocess = 'failed_reprocess';
    case Ignored = 'ignored';
}
