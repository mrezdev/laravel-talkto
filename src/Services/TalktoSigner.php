<?php

namespace Mrezdev\LaravelTalkto\Services;

/**
 * Advanced public utility for deterministic Talkto signature creation.
 */
class TalktoSigner
{
    public function __construct(private readonly ?TalktoEnvelopeFieldValidator $fieldValidator = null) {}

    public function canonicalString(
        string $messageId,
        string $timestamp,
        string $source,
        string $target,
        string $command,
        string $payloadHash
    ): string {
        $this->validator()->validateIdentifiers([
            'message_id' => $messageId,
            'timestamp' => $timestamp,
            'source_service' => $source,
            'target_service' => $target,
            'command' => $command,
            'payload_hash' => $payloadHash,
        ]);

        return implode('.', [
            $messageId,
            $timestamp,
            $source,
            $target,
            $command,
            $payloadHash,
        ]);
    }

    public function sign(
        string $messageId,
        string $timestamp,
        string $source,
        string $target,
        string $command,
        string $payloadHash,
        string $secret
    ): string {
        return hash_hmac(
            'sha256',
            $this->canonicalString($messageId, $timestamp, $source, $target, $command, $payloadHash),
            $secret
        );
    }

    public function verify(
        string $signature,
        string $messageId,
        string $timestamp,
        string $source,
        string $target,
        string $command,
        string $payloadHash,
        string $secret
    ): bool {
        return hash_equals(
            $this->sign($messageId, $timestamp, $source, $target, $command, $payloadHash, $secret),
            $signature
        );
    }

    public function canonicalStringV2(
        string $timestamp,
        ?string $nonce,
        string $messageId,
        string $source,
        string $target,
        string $command,
        string $payloadHash
    ): string {
        $this->validator()->validateIdentifiers([
            'signature_version' => 'v2',
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'message_id' => $messageId,
            'source_service' => $source,
            'target_service' => $target,
            'command' => $command,
            'payload_hash' => $payloadHash,
        ]);

        return implode("\n", [
            'v2',
            $timestamp,
            $nonce ?? '',
            $messageId,
            $source,
            $target,
            $command,
            $payloadHash,
        ]);
    }

    public function signV2(
        string $timestamp,
        ?string $nonce,
        string $messageId,
        string $source,
        string $target,
        string $command,
        string $payloadHash,
        string $secret
    ): string {
        return hash_hmac(
            'sha256',
            $this->canonicalStringV2($timestamp, $nonce, $messageId, $source, $target, $command, $payloadHash),
            $secret
        );
    }

    public function verifyV2(
        string $signature,
        string $timestamp,
        ?string $nonce,
        string $messageId,
        string $source,
        string $target,
        string $command,
        string $payloadHash,
        string $secret
    ): bool {
        return hash_equals(
            $this->signV2($timestamp, $nonce, $messageId, $source, $target, $command, $payloadHash, $secret),
            $signature
        );
    }

    private function validator(): TalktoEnvelopeFieldValidator
    {
        return $this->fieldValidator ?? app(TalktoEnvelopeFieldValidator::class);
    }
}
