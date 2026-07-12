<?php

namespace Mrezdev\LaravelTalkto\Exceptions;

use InvalidArgumentException;
use Throwable;

class TalktoUnsupportedPayloadValueException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $payloadPath,
        public readonly string $payloadReason,
        public readonly string $payloadErrorCode,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            "Unsupported Talkto payload value at [{$payloadPath}]: {$payloadReason} ({$payloadErrorCode}).",
            0,
            $previous
        );
    }

    public static function atPath(
        string $path,
        string $reason,
        string $errorCode,
        ?Throwable $previous = null
    ): self {
        return new self($path, $reason, $errorCode, $previous);
    }
}
