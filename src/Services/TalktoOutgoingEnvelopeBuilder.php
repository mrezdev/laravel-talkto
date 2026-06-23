<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Data\TalktoEnvelopeData;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoOutgoingTarget;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoSignatureException;

/**
 * @internal Runtime envelope builder behind outgoing delivery.
 */
class TalktoOutgoingEnvelopeBuilder
{
    public function __construct(
        private readonly TalktoSigner $signer,
        private readonly TalktoOutgoingTargetRegistryContract $targets
    ) {}

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
        $target = $this->targets->get((string) $message->target_service);

        return $this->isResultCallbackMessage($message)
            ? $target->callbackEndpointUrl()
            : $target->endpointUrl();
    }

    public function timeoutFor(Model $message): int
    {
        $target = $this->targets->get((string) $message->target_service);

        if ($this->isResultCallbackMessage($message)) {
            return $target->timeout()
                ?? (int) config('talkto.callbacks.timeout_seconds', config('talkto.http.timeout_seconds', 20));
        }

        return $target->timeout()
            ?? (int) config('talkto.http.timeout_seconds', 20);
    }

    public function httpOptionsFor(Model $message): array
    {
        $target = $this->targets->get((string) $message->target_service);

        return [
            'verify' => $target->tlsVerifyOption(),
        ];
    }

    public function isResultCallbackMessage(Model $message): bool
    {
        if (($message->direction ?? null) !== TalktoMessageDirection::Outgoing->value) {
            return false;
        }

        $callbackCommand = (string) config('talkto.callbacks.command', 'talkto.result');

        if ($callbackCommand === '') {
            $callbackCommand = 'talkto.result';
        }

        if (($message->command ?? null) !== $callbackCommand) {
            return false;
        }

        $payload = $message->payload ?? null;

        return is_array($payload)
            && $this->hasPayloadString($payload, 'original_message_id')
            && $this->hasPayloadString($payload, 'original_command')
            && $this->hasPayloadString($payload, 'status');
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
        $version = config('talkto.security.signature_version', 'v2');

        if (in_array($version, ['v1', 'v2'], true)) {
            return $version;
        }

        $safeVersion = is_scalar($version) ? (string) $version : gettype($version);

        throw new InvalidTalktoSignatureException(
            sprintf('Unsupported outgoing Talkto signature version [%s]. Supported versions: v1, v2.', $safeVersion)
        );
    }

    private function hasPayloadString(array $payload, string $key): bool
    {
        return isset($payload[$key]) && is_scalar($payload[$key]) && (string) $payload[$key] !== '';
    }
}
