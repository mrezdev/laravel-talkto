<?php

namespace Mrezdev\LaravelTalkto\Exceptions;

use RuntimeException;

/** @internal A reliable processing attempt requires investigation, not handler retry. */
class TalktoReliableCallbackPersistenceException extends RuntimeException {}
