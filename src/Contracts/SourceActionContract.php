<?php

namespace Mrezdev\LaravelTalkto\Contracts;

/**
 * Runs optional source-side work before an outgoing Talkto message is recorded.
 */
interface SourceActionContract
{
    public function execute(): mixed;
}
