<?php

namespace Ibake\TalktoReliable\Services;

class TalktoSignatureVerifier
{
    public function __construct(
        private readonly TalktoPayloadHasher $payloadHasher,
        private readonly TalktoSigner $signer
    ) {
    }

    public function verifyEnvelope(array $envelope, array $headers): array
    {
        foreach (['message_id', 'source', 'target', 'command', 'payload_hash'] as $field) {
            if (! array_key_exists($field, $envelope) || $envelope[$field] === null || $envelope[$field] === '') {
                return $this->failure(422, 'missing_required_field');
            }
        }

        $messageId = (string) $envelope['message_id'];
        $source = (string) $envelope['source'];
        $target = (string) $envelope['target'];
        $command = (string) $envelope['command'];
        $payloadHash = (string) $envelope['payload_hash'];
        $requireSignature = (bool) config('talkto.security.require_signature', true);
        $normalizedHeaders = $this->normalizeHeaders($headers);

        if ($requireSignature) {
            $signature = $this->headerValue($normalizedHeaders, 'x-talkto-signature');
            $timestamp = $this->headerValue($normalizedHeaders, 'x-talkto-timestamp');
            $headerMessageId = $this->headerValue($normalizedHeaders, 'x-talkto-message-id');

            if ($signature === null || $timestamp === null || $headerMessageId === null) {
                return $this->failure(401, 'missing_signature_header');
            }

            if ($headerMessageId !== $messageId) {
                return $this->failure(401, 'invalid_signature');
            }

            if (! $this->timestampIsWithinTolerance($timestamp)) {
                return $this->failure(401, 'timestamp_outside_tolerance');
            }
        } else {
            $signature = null;
            $timestamp = null;
        }

        $incoming = (array) config('talkto.incoming', []);

        if (! array_key_exists($source, $incoming) || ! is_array($incoming[$source])) {
            return $this->failure(403, 'unknown_source');
        }

        if ($target !== (string) config('talkto.service', 'app')) {
            return $this->failure(403, 'wrong_target');
        }

        $sourceConfig = $incoming[$source];
        $commandConfig = $this->commandConfig($sourceConfig, $command);

        if ($commandConfig === false) {
            return $this->failure(403, 'command_not_allowed');
        }

        if (
            is_array($commandConfig)
            && ($commandConfig['idempotency'] ?? null) === 'required'
            && empty($envelope['idempotency_key'])
        ) {
            return $this->failure(422, 'missing_idempotency_key');
        }

        $actualPayloadHash = $this->payloadHasher->hash($envelope['payload'] ?? null);

        if (! hash_equals($actualPayloadHash, $payloadHash)) {
            return $this->failure(422, 'payload_hash_mismatch');
        }

        if ($requireSignature) {
            $secret = $sourceConfig['secret'] ?? null;

            if ($secret === null || $secret === '') {
                return $this->failure(403, 'missing_source_secret');
            }

            if (! $this->signer->verify($signature, $messageId, $timestamp, $source, $target, $command, $payloadHash, (string) $secret)) {
                return $this->failure(401, 'invalid_signature');
            }
        }

        return [
            'ok' => true,
            'status' => 200,
            'error' => null,
        ];
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            if (is_array($value)) {
                $value = reset($value);
            }

            $normalized[strtolower((string) $key)] = $value === null ? null : (string) $value;
        }

        return $normalized;
    }

    private function headerValue(array $headers, string $key): ?string
    {
        $value = $headers[strtolower($key)] ?? null;

        return $value === '' ? null : $value;
    }

    private function timestampIsWithinTolerance(string $timestamp): bool
    {
        $timestampValue = is_numeric($timestamp) ? (int) $timestamp : strtotime($timestamp);

        if ($timestampValue === false) {
            return false;
        }

        $tolerance = (int) config('talkto.security.timestamp_tolerance_seconds', 300);

        return abs(time() - $timestampValue) <= $tolerance;
    }

    private function commandConfig(array $sourceConfig, string $command): array|bool|null
    {
        if (! array_key_exists('allowed_commands', $sourceConfig)) {
            return null;
        }

        $allowedCommands = $sourceConfig['allowed_commands'];

        if (! is_array($allowedCommands)) {
            return false;
        }

        if ($this->isList($allowedCommands)) {
            return in_array($command, $allowedCommands, true) ? null : false;
        }

        if (! array_key_exists($command, $allowedCommands)) {
            return false;
        }

        return $allowedCommands[$command];
    }

    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function failure(int $status, string $error): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'error' => $error,
        ];
    }
}
