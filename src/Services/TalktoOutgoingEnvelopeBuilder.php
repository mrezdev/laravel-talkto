<?php

namespace Ibake\TalktoReliable\Services;

use Ibake\TalktoReliable\Contracts\TalktoOutgoingTargetRegistryContract;
use Ibake\TalktoReliable\Exceptions\InvalidTalktoOutgoingTarget;
use Illuminate\Database\Eloquent\Model;

class TalktoOutgoingEnvelopeBuilder
{
    public function __construct(
        private readonly TalktoSigner $signer,
        private readonly TalktoOutgoingTargetRegistryContract $targets
    ) {
    }

    public function buildEnvelope(Model $message): array
    {
        return [
            'protocol_version' => 2,
            'message_id' => $message->message_id,
            'correlation_id' => $message->correlation_id,
            'parent_message_id' => $message->parent_message_id,
            'source' => $message->source_service,
            'target' => $message->target_service,
            'command' => $message->command,
            'business_key' => $message->business_key,
            'idempotency_key' => $message->idempotency_key,
            'schema_version' => $message->schema_version ?: 1,
            'created_at' => optional($message->created_at)->toIso8601String(),
            'payload_hash' => $message->payload_hash,
            'payload' => $message->payload,
        ];
    }

    public function buildHeaders(Model $message, ?string $timestamp = null): array
    {
        $timestamp ??= now()->toIso8601String();
        $target = $this->targets->get((string) $message->target_service);
        $secret = $target->secret();

        if ($secret === null) {
            throw InvalidTalktoOutgoingTarget::forTarget((string) $message->target_service, 'secret is not configured');
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
}
