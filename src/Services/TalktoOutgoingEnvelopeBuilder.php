<?php

namespace Ibake\TalktoReliable\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class TalktoOutgoingEnvelopeBuilder
{
    public function __construct(
        private readonly TalktoSigner $signer
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
        $secret = config("talkto.outgoing.{$message->target_service}.secret");

        if ($secret === null || $secret === '') {
            throw new InvalidArgumentException("Talkto outgoing secret for target [{$message->target_service}] is not configured.");
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

        return [
            'X-Talkto-Signature' => $signature,
            'X-Talkto-Timestamp' => $timestamp,
            'X-Talkto-Message-Id' => $message->message_id,
            'X-Talkto-Protocol-Version' => '2',
        ];
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
        $baseUrl = config("talkto.outgoing.{$message->target_service}.url");
        $endpoint = config("talkto.outgoing.{$message->target_service}.endpoint", '/api/talkto/receive');

        if ($baseUrl === null || $baseUrl === '') {
            throw new InvalidArgumentException("Talkto outgoing URL for target [{$message->target_service}] is not configured.");
        }

        return rtrim((string) $baseUrl, '/') . '/' . ltrim((string) $endpoint, '/');
    }
}
