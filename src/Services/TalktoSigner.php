<?php

namespace Ibake\TalktoReliable\Services;

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
}
