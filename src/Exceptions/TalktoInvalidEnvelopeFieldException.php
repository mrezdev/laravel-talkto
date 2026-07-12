<?php

namespace Mrezdev\LaravelTalkto\Exceptions;

use InvalidArgumentException;

class TalktoInvalidEnvelopeFieldException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $field,
        public readonly string $reason,
        public readonly string $errorCode = 'invalid_envelope_field'
    ) {
        $message = match ($reason) {
            'control_character' => "Invalid control character in Talkto envelope field [{$field}].",
            'header_name' => 'Invalid HTTP header name configured for Talkto.',
            'invalid_header_value_count' => "Invalid number of values for Talkto protocol header [{$field}].",
            'invalid_utf8' => "Invalid UTF-8 in Talkto envelope field [{$field}].",
            default => "Invalid Talkto envelope field [{$field}].",
        };

        parent::__construct($message);
    }

    public static function forControlCharacter(string $field): self
    {
        return new self($field, 'control_character');
    }

    public static function forType(string $field): self
    {
        return new self($field, 'non_string');
    }

    public static function forInvalidUtf8(string $field): self
    {
        return new self($field, 'invalid_utf8');
    }

    public static function forHeaderName(string $field): self
    {
        return new self($field, 'header_name');
    }

    public static function forHeaderValueType(string $field): self
    {
        return new self($field, 'header_value_type');
    }

    public static function forHeaderValueCount(string $field): self
    {
        return new self($field, 'invalid_header_value_count', 'invalid_header_value_count');
    }
}
