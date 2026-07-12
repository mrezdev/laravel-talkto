<?php

namespace Mrezdev\LaravelTalkto\Support;

use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

/**
 * @internal Result object for durable dispatch claims.
 */
class TalktoDispatchClaim
{
    public function __construct(
        public readonly bool $claimed,
        public readonly string $operation,
        public readonly string $status,
        public readonly ?TalktoMessage $message = null,
        public readonly ?TalktoDeadLetter $deadLetter = null,
        public readonly ?string $claimId = null,
        public readonly array $previousMessageAttributes = [],
        public readonly array $previousDeadLetterAttributes = [],
        public readonly array $decision = [],
        public readonly array $meta = [],
    ) {}

    public static function claimed(
        string $operation,
        TalktoMessage $message,
        string $claimId,
        array $previousMessageAttributes,
        array $decision = [],
        ?TalktoDeadLetter $deadLetter = null,
        array $previousDeadLetterAttributes = [],
        array $meta = [],
    ): self {
        return new self(
            true,
            $operation,
            'claimed',
            $message,
            $deadLetter,
            $claimId,
            $previousMessageAttributes,
            $previousDeadLetterAttributes,
            $decision,
            $meta,
        );
    }

    public static function skipped(
        string $operation,
        string $status,
        ?TalktoMessage $message = null,
        ?TalktoDeadLetter $deadLetter = null,
        array $decision = [],
        array $meta = [],
    ): self {
        return new self(
            false,
            $operation,
            $status,
            $message,
            $deadLetter,
            null,
            [],
            [],
            $decision,
            $meta,
        );
    }

    public function direction(): ?string
    {
        return $this->message?->direction;
    }
}
