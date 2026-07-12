<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Exceptions\TalktoInvalidEnvelopeFieldException;

/**
 * @internal Validates protocol identifiers before signing, routing, headers, and persistence.
 */
class TalktoEnvelopeFieldValidator
{
    /**
     * @var list<string>
     */
    private const BUILT_IN_PROTOCOL_HEADERS = [
        'x-talkto-signature',
        'x-talkto-timestamp',
        'x-talkto-message-id',
        'x-talkto-protocol-version',
        'x-talkto-signature-version',
        'x-talkto-payload-hash',
        'x-talkto-nonce',
    ];

    public function validateNullableIdentifier(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->validateIdentifier($field, $value);
    }

    public function validateIdentifier(string $field, mixed $value): string
    {
        if (! is_string($value)) {
            throw TalktoInvalidEnvelopeFieldException::forType($field);
        }

        if (! $this->isValidUtf8($value)) {
            throw TalktoInvalidEnvelopeFieldException::forInvalidUtf8($field);
        }

        if ($this->containsForbiddenCharacter($value)) {
            throw TalktoInvalidEnvelopeFieldException::forControlCharacter($field);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function validateIdentifiers(array $fields): void
    {
        foreach ($fields as $field => $value) {
            $this->validateNullableIdentifier((string) $field, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function validateTalktoHeaders(
        array $headers,
        array $additionalTalktoHeaderNames = [],
        bool $validateAllValues = true
    ): void {
        $additionalTalktoHeaderNames = $this->normalizeConfiguredHeaderNames($additionalTalktoHeaderNames);
        $singularProtocolHeaders = array_values(array_unique(array_merge(
            self::BUILT_IN_PROTOCOL_HEADERS,
            array_values($additionalTalktoHeaderNames)
        )));

        foreach ($headers as $name => $value) {
            $headerName = $this->validateHeaderName('header_name', $name);
            $normalizedName = strtolower($headerName);
            $shouldValidateValue = $validateAllValues
                || $this->isTalktoHeader($headerName)
                || in_array($normalizedName, $additionalTalktoHeaderNames, true);

            if (! $shouldValidateValue) {
                continue;
            }

            $this->validateHeaderValues(
                $this->fieldForHeaderValue($normalizedName, $additionalTalktoHeaderNames),
                $value,
                in_array($normalizedName, $singularProtocolHeaders, true)
            );
        }
    }

    public function validateHeaderName(string $field, mixed $name): string
    {
        if (! is_string($name) || $name === '') {
            throw TalktoInvalidEnvelopeFieldException::forHeaderName($field);
        }

        if (preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/", $name) !== 1) {
            throw TalktoInvalidEnvelopeFieldException::forHeaderName($field);
        }

        return $name;
    }

    public function validateHeaderValues(string $field, mixed $values, bool $mustBeSingular = false): void
    {
        if (is_array($values)) {
            if ($mustBeSingular && count($values) !== 1) {
                throw TalktoInvalidEnvelopeFieldException::forHeaderValueCount($field);
            }

            foreach ($values as $value) {
                if (is_array($value)) {
                    throw TalktoInvalidEnvelopeFieldException::forHeaderValueType($field);
                }

                $this->validateNullableHeaderValue($field, $value);
            }

            return;
        }

        $this->validateNullableHeaderValue($field, $values);
    }

    /**
     * @param  array<int|string, mixed>  $headerNames
     * @return array<string, string>
     */
    public function normalizeConfiguredHeaderNames(array $headerNames): array
    {
        $normalized = [];

        foreach ($headerNames as $field => $name) {
            $safeField = is_string($field) ? $field : 'header_name';
            $headerName = $this->validateHeaderName($safeField, $name);

            $normalized[$safeField] = strtolower($headerName);
        }

        return $normalized;
    }

    private function validateNullableHeaderValue(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $this->validateIdentifier($field, (string) $value);
        }

        if (! is_string($value)) {
            throw TalktoInvalidEnvelopeFieldException::forHeaderValueType($field);
        }

        return $this->validateIdentifier($field, $value);
    }

    private function containsForbiddenCharacter(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F\x{2028}\x{2029}]/u', $value) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private function isTalktoHeader(string $name): bool
    {
        return str_starts_with(strtolower($name), 'x-talkto-');
    }

    /**
     * @param  array<string, string>  $configuredHeaderNames
     */
    private function fieldForHeaderValue(string $normalizedName, array $configuredHeaderNames): string
    {
        if (array_key_exists('signature_version_header_name', $configuredHeaderNames)
            && $normalizedName === $configuredHeaderNames['signature_version_header_name']) {
            return 'signature_version';
        }

        if (array_key_exists('nonce_header_name', $configuredHeaderNames)
            && $normalizedName === $configuredHeaderNames['nonce_header_name']) {
            return 'nonce';
        }

        return match ($normalizedName) {
            'x-talkto-signature' => 'signature',
            'x-talkto-timestamp' => 'timestamp',
            'x-talkto-message-id' => 'message_id',
            'x-talkto-protocol-version' => 'protocol_version',
            'x-talkto-signature-version' => 'signature_version',
            'x-talkto-payload-hash' => 'payload_hash',
            'x-talkto-nonce' => 'nonce',
            default => 'header_value',
        };
    }

    private function isValidUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }
}
