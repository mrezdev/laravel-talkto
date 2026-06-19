<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Data\TalktoEnvelopeData;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoSignatureException;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoOutgoingTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TalktoOutgoingEnvelopeBuilder
{
    public function __construct(
        private readonly TalktoSigner $signer,
        private readonly TalktoOutgoingTargetRegistryContract $targets
    ) {
    }

    public function buildEnvelope(Model $message): array
    {
        return TalktoEnvelopeData::fromMessage($message)->toArray();
    }

    public function buildHeaders(Model $message, ?string $timestamp = null): array
    {
        $timestamp ??= now()->toIso8601String();
        $target = $this->targets->get((string) $message->target_service);
        $secret = $target->secret();

        if ($secret === null) {
            throw InvalidTalktoOutgoingTarget::forTarget((string) $message->target_service, 'secret is not configured');
        }

        $version = $this->signatureVersion();

        if ($version === 'v2') {
            return $this->buildV2Headers($message, $target->headers(), $timestamp, (string) $secret);
        }

        $signature = $this->signer->sign(
            $message->message_id,
            $timestamp,
            $message->source_service,
            $message->target_service,
            $message->command,
            $message->payload_hash,
            (string) $secret
        );

        return array_merge($target->headers(), [
            'X-Talkto-Signature' => $signature,
            'X-Talkto-Timestamp' => $timestamp,
            'X-Talkto-Message-Id' => $message->message_id,
            'X-Talkto-Protocol-Version' => '2',
        ]);
    }

    public function build(Model $message): array
    {
        return [
            'envelope' => $this->buildEnvelope($message),
            'headers' => $this->buildHeaders($message),
        ];
    }

    public function endpointFor(Model $message): string
    {
        return $this->targets->get((string) $message->target_service)->endpointUrl();
    }

    public function timeoutFor(Model $message): int
    {
        return $this->targets->get((string) $message->target_service)->timeout()
            ?? (int) config('talkto.http.timeout_seconds', 20);
    }

    private function buildV2Headers(Model $message, array $customHeaders, string $timestamp, string $secret): array
    {
        $nonce = (string) Str::uuid();
        $signature = $this->signer->signV2(
            $timestamp,
            $nonce,
            $message->message_id,
            $message->source_service,
            $message->target_service,
            $message->command,
            $message->payload_hash,
            $secret
        );

        return array_merge($customHeaders, [
            'X-Talkto-Signature' => $signature,
            'X-Talkto-Timestamp' => $timestamp,
            'X-Talkto-Message-Id' => $message->message_id,
            'X-Talkto-Protocol-Version' => '2',
            (string) config('talkto.security.signature_version_header', 'X-Talkto-Signature-Version') => 'v2',
            'X-Talkto-Payload-Hash' => $message->payload_hash,
            (string) config('talkto.security.nonce_header', 'X-Talkto-Nonce') => $nonce,
        ]);
    }

    private function signatureVersion(): string
    {
        $version = config('talkto.security.signature_version', 'v1');

        if (in_array($version, ['v1', 'v2'], true)) {
            return $version;
        }

        $safeVersion = is_scalar($version) ? (string) $version : gettype($version);

        throw new InvalidTalktoSignatureException(
            sprintf('Unsupported outgoing Talkto signature version [%s]. Supported versions: v1, v2.', $safeVersion)
        );
    }
}
