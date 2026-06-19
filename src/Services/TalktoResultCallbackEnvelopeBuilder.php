<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoOutgoingTarget;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoSignatureException;

class TalktoResultCallbackEnvelopeBuilder
{
    public function __construct(
        private readonly TalktoSigner $signer,
        private readonly TalktoOutgoingTargetRegistryContract $targets
    ) {}

    public function buildEnvelope(Model $message, IncomingCommandResultContract $result, array $options = []): array
    {
        return TalktoResultCallbackData::fromIncomingMessageResult($message, $result, $options)->toEnvelope();
    }

    public function buildHeaders(array $envelope, ?string $timestamp = null): array
    {
        $timestamp ??= now()->toIso8601String();
        $target = $this->targets->get((string) $envelope['target']);
        $secret = $target->secret();

        if ($secret === null) {
            throw InvalidTalktoOutgoingTarget::forTarget((string) $envelope['target'], 'secret is not configured');
        }

        if ($this->signatureVersion() === 'v2') {
            return $this->buildV2Headers($envelope, $target->headers(), $timestamp, (string) $secret);
        }

        $signature = $this->signer->sign(
            (string) $envelope['message_id'],
            $timestamp,
            (string) $envelope['source'],
            (string) $envelope['target'],
            (string) $envelope['command'],
            (string) $envelope['payload_hash'],
            (string) $secret
        );

        return array_merge($target->headers(), [
            'X-Talkto-Signature' => $signature,
            'X-Talkto-Timestamp' => $timestamp,
            'X-Talkto-Message-Id' => (string) $envelope['message_id'],
            'X-Talkto-Protocol-Version' => '2',
        ]);
    }

    public function callbackEndpointFor(string $targetService): string
    {
        return $this->targets->get($targetService)->callbackEndpointUrl();
    }

    public function timeoutFor(string $targetService): int
    {
        return $this->targets->get($targetService)->timeout()
            ?? (int) config('talkto.callbacks.timeout_seconds', config('talkto.http.timeout_seconds', 20));
    }

    private function buildV2Headers(array $envelope, array $customHeaders, string $timestamp, string $secret): array
    {
        $nonce = (string) Str::uuid();
        $signature = $this->signer->signV2(
            $timestamp,
            $nonce,
            (string) $envelope['message_id'],
            (string) $envelope['source'],
            (string) $envelope['target'],
            (string) $envelope['command'],
            (string) $envelope['payload_hash'],
            $secret
        );

        return array_merge($customHeaders, [
            'X-Talkto-Signature' => $signature,
            'X-Talkto-Timestamp' => $timestamp,
            'X-Talkto-Message-Id' => (string) $envelope['message_id'],
            'X-Talkto-Protocol-Version' => '2',
            (string) config('talkto.security.signature_version_header', 'X-Talkto-Signature-Version') => 'v2',
            'X-Talkto-Payload-Hash' => (string) $envelope['payload_hash'],
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
