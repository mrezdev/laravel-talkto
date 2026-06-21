<?php

namespace Mrezdev\LaravelTalkto\Enums;

/**
 * @internal Internal persisted message direction values.
 */
enum TalktoMessageDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
}
