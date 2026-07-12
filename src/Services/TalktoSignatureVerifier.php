<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Exceptions\TalktoInvalidEnvelopeFieldException;

/**
 * @internal Runtime signature verifier behind receive and callback endpoints.
 */
class TalktoSignatureVerifier
{
    public function __construct(
        private readonly TalktoPayloadHasher $payloadHasher,
        private readonly TalktoSigner $signer,
        private readonly ?TalktoEnvelopeFieldValidator $fieldValidator = null
    ) {}

    public function verifyEnvelope(array $envelope, array $headers): array
    {
        foreach (['message_id', 'source', 'target', 'command', 'payload_hash'] as $field) {
            if (! array_key_exists($field, $envelope) || $envelope[$field] === null || $envelope[$field] === '') {
                return $this->failure(422, 'missing_required_field');
            }
        }

        $requireSignature = (bool) config('talkto.security.require_signature', true);
        $normalizedHeaders = $this->normalizeHeaders($headers);

        try {
            $this->validateEnvelopeFields($envelope);
            $this->validator()->validateTalktoHeaders($normalizedHeaders, $this->configuredTalktoHeaderNames());
        } catch (TalktoInvalidEnvelopeFieldException $exception) {
            return $this->failure(422, $exception->errorCode, $exception->field);
        }

        $messageId = (string) $envelope['message_id'];
        $source = (string) $envelope['source'];
        $target = (string) $envelope['target'];
        $command = (string) $envelope['command'];
        $payloadHash = (string) $envelope['payload_hash'];
        $version = $this->signatureVersion($normalizedHeaders);
        $timestamp = $this->headerValue($normalizedHeaders, 'x-talkto-timestamp');

        if (! in_array($version, $this->acceptedVersions(), true)) {
            return $this->failure(401, 'unsupported_signature_version');
        }

        if ($requireSignature) {
            $signature = $this->headerValue($normalizedHeaders, 'x-talkto-signature');
            $headerMessageId = $this->headerValue($normalizedHeaders, 'x-talkto-message-id');
            $nonce = $this->headerValue($normalizedHeaders, (string) config('talkto.security.nonce_header', 'X-Talkto-Nonce'));

            if ($signature === null || $timestamp === null || $headerMessageId === null) {
                return $this->failure(401, 'missing_signature_header');
            }

            if ($headerMessageId !== $messageId) {
                return $this->failure(401, 'invalid_signature');
            }

            if (! $this->timestampIsWithinTolerance($timestamp)) {
                return $this->failure(401, 'timestamp_outside_tolerance');
            }

            if (
                $version === 'v2'
                && (bool) config('talkto.security.replay_protection.require_nonce_for_v2', true)
                && $nonce === null
            ) {
                return $this->failure(401, 'missing_nonce');
            }
        } else {
            $signature = null;
            $timestamp = null;
            $nonce = null;

            if ((bool) config('talkto.security.require_timestamp', true)) {
                $timestamp = $this->headerValue($normalizedHeaders, 'x-talkto-timestamp');

                if ($timestamp === null) {
                    return $this->failure(401, 'missing_timestamp');
                }

                if (! $this->timestampIsWithinTolerance($timestamp)) {
                    return $this->failure(401, 'timestamp_outside_tolerance');
                }
            }
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

        $payloadHashHeader = $this->headerValue($normalizedHeaders, 'x-talkto-payload-hash');

        if ($payloadHashHeader !== null && ! hash_equals($payloadHashHeader, $payloadHash)) {
            return $this->failure(422, 'payload_hash_mismatch');
        }

        if ($requireSignature) {
            $secret = $sourceConfig['secret'] ?? $sourceConfig['signing_secret'] ?? null;

            if ($secret === null || $secret === '') {
                return $this->failure(403, 'missing_source_secret');
            }

            $validSignature = $version === 'v2'
                ? $this->signer->verifyV2($signature, $timestamp, $nonce, $messageId, $source, $target, $command, $payloadHash, (string) $secret)
                : $this->signer->verify($signature, $messageId, $timestamp, $source, $target, $command, $payloadHash, (string) $secret);

            if (! $validSignature) {
                return $this->failure(401, 'invalid_signature');
            }
        }

        return [
            'ok' => true,
            'status' => 200,
            'error' => null,
            'signature_version' => $version,
            'signed_timestamp' => $timestamp,
            'nonce' => $nonce,
            'source' => $source,
            'target' => $target,
        ];
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (array_key_exists($normalizedKey, $normalized)) {
                $normalized[$normalizedKey] = array_merge(
                    $this->headerValueList($normalized[$normalizedKey]),
                    $this->headerValueList($value)
                );

                continue;
            }

            $normalized[$normalizedKey] = $value;
        }

        return $normalized;
    }

    private function headerValue(array $headers, string $key): ?string
    {
        $value = $headers[strtolower($key)] ?? null;

        if (is_array($value)) {
            if (count($value) !== 1) {
                return null;
            }

            $value = reset($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function headerValueList(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [$value];
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

    private function signatureVersion(array $headers): string
    {
        $header = (string) config('talkto.security.signature_version_header', 'X-Talkto-Signature-Version');
        $version = $this->headerValue($headers, $header);

        return $version === 'v2' ? 'v2' : ($version === null ? 'v1' : $version);
    }

    private function acceptedVersions(): array
    {
        $versions = config('talkto.security.accept_versions', ['v2']);

        if (! is_array($versions) || $versions === []) {
            return ['v2'];
        }

        return array_values(array_filter($versions, fn (mixed $version): bool => is_string($version) && $version !== ''));
    }

    private function commandConfig(array $sourceConfig, string $command): array|bool|null
    {
        if (! array_key_exists('allowed_commands', $sourceConfig)) {
            return ($sourceConfig['allow_all_commands'] ?? false) === true ? null : false;
        }

        $allowedCommands = $sourceConfig['allowed_commands'];

        if (! is_array($allowedCommands) || $allowedCommands === []) {
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

    private function validateEnvelopeFields(array $envelope): void
    {
        $this->validator()->validateIdentifiers([
            'message_id' => $envelope['message_id'],
            'source_service' => $envelope['source'],
            'target_service' => $envelope['target'],
            'command' => $envelope['command'],
            'payload_hash' => $envelope['payload_hash'],
            'correlation_id' => $envelope['correlation_id'] ?? null,
            'parent_message_id' => $envelope['parent_message_id'] ?? null,
        ]);
    }

    private function validator(): TalktoEnvelopeFieldValidator
    {
        return $this->fieldValidator ?? app(TalktoEnvelopeFieldValidator::class);
    }

    private function configuredTalktoHeaderNames(): array
    {
        return [
            'signature_version_header_name' => config('talkto.security.signature_version_header', 'X-Talkto-Signature-Version'),
            'nonce_header_name' => config('talkto.security.nonce_header', 'X-Talkto-Nonce'),
        ];
    }

    private function failure(int $status, string $error, ?string $field = null): array
    {
        return array_filter([
            'ok' => false,
            'status' => $status,
            'error' => $error,
            'field' => $field,
        ], fn (mixed $value): bool => $value !== null);
    }
}
