<?php

namespace Mrezdev\LaravelTalkto\Services;

class TalktoSigner
{
    public function canonicalString(
        string $messageId,
        string $timestamp,
        string $source,
        string $target,
        string $command,
        string $payloadHash
    ): string {
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
}
