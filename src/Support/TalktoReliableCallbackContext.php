<?php

namespace Mrezdev\LaravelTalkto\Support;

use Illuminate\Database\Connection;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackSender;

/** @internal In-memory binding snapshot; never serialized or logged. */
final readonly class TalktoReliableCallbackContext
{
    public function __construct(
        public Connection $connection,
        public array $models,
        public array $modelBindings,
        public array $containerBindings,
        public array $configuration,
        public array $identity,
        public array $routing,
        public string $owner,
        public TalktoResultCallbackSender $sender,
        public TalktoResultCallbackMessageFactory $factory,
        public TalktoOutgoingMessageFactory $outgoing,
    ) {}
}
