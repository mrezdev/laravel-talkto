<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoModelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use Throwable;

/**
 * Creates outgoing Talkto message records through the documented public API.
 */
class TalktoOutgoingMessageFactory
{
    public function __construct(
        private readonly TalktoPayloadHasher $payloadHasher,
        private readonly TalktoOutgoingTargetRegistryContract $targets,
        private readonly ?TalktoPayloadFreezer $payloadFreezer = null,
        private readonly ?TalktoEnvelopeFieldValidator $fieldValidator = null
    ) {}

    public function create(
        string $target,
        string $command,
        mixed $payload = [],
        array $options = []
    ): TalktoMessage {
        $resolvedTarget = $this->resolvedTargetName($target);
        $this->validator()->validateIdentifier('target_service', $resolvedTarget);

        $resolvedTargetConfig = $this->targets->resolve($target);

        if (! $resolvedTargetConfig) {
            throw new InvalidArgumentException("Talkto outgoing target [{$target}] is not configured.");
        }

        $this->validator()->validateTalktoHeaders($resolvedTargetConfig->headers(), $this->configuredTalktoHeaderNames());

        $configuredSourceService = $options['source_service'] ?? config('talkto.service', 'app');
        $sourceService = is_string($configuredSourceService) && $configuredSourceService !== ''
            ? $configuredSourceService
            : config('talkto.service', 'app');
        $businessKey = $options['business_key'] ?? null;
        $idempotencyKey = $options['idempotency_key'] ?? null;
        $messageId = $options['message_id'] ?? Str::uuid()->toString();
        $correlationId = $options['correlation_id'] ?? Str::uuid()->toString();
        $parentMessageId = $options['parent_message_id'] ?? null;

        $this->validator()->validateIdentifiers([
            'source_service' => $sourceService,
            'target_service' => $resolvedTarget,
            'command' => $command,
            'message_id' => $messageId,
            'correlation_id' => $correlationId,
            'parent_message_id' => $parentMessageId,
        ]);

        $sourceActionStatus = $options['source_action_status'] ?? TalktoMessageStatus::SucceededAssumed->value;
        $transportStatus = array_key_exists('transport_status', $options) ? $options['transport_status'] : TalktoMessageStatus::Pending->value;
        $destinationReceiveStatus = array_key_exists('destination_receive_status', $options) ? $options['destination_receive_status'] : null;
        $destinationActionStatus = array_key_exists('destination_action_status', $options) ? $options['destination_action_status'] : null;
        $overallStatus = $options['overall_status'] ?? TalktoMessageStatus::WaitingToSend->value;
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();
        $idempotencyFingerprint = $messageClass::idempotencyFingerprint(
            TalktoMessageDirection::Outgoing->value,
            $sourceService,
            $resolvedTarget,
            $command,
            $idempotencyKey
        );

        if ($idempotencyFingerprint !== null) {
            $existingMessage = $messageClass::query()
                ->where('idempotency_fingerprint', $idempotencyFingerprint)
                ->first();

            if ($existingMessage instanceof TalktoMessage) {
                return $existingMessage;
            }
        }

        TalktoModelConnection::assertSameConnection($messageClass, $eventClass);

        $frozenPayload = $this->payloadFreezer()->freezePayload($payload);
        $payloadHash = $this->payloadHasher->hash($frozenPayload);

        try {
            return TalktoModelConnection::transaction($messageClass, function () use (
                $messageClass,
                $eventClass,
                $messageId,
                $correlationId,
                $parentMessageId,
                $options,
                $sourceService,
                $resolvedTarget,
                $command,
                $businessKey,
                $idempotencyKey,
                $idempotencyFingerprint,
                $frozenPayload,
                $payloadHash,
                $sourceActionStatus,
                $transportStatus,
                $destinationReceiveStatus,
                $destinationActionStatus,
                $overallStatus
            ): TalktoMessage {
                $message = $messageClass::create([
                    'message_id' => $messageId,
                    'correlation_id' => $correlationId,
                    'parent_message_id' => $parentMessageId,
                    'direction' => TalktoMessageDirection::Outgoing->value,
                    'source_service' => $sourceService,
                    'target_service' => $resolvedTarget,
                    'command' => $command,
                    'business_key' => $businessKey,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_fingerprint' => $idempotencyFingerprint,
                    'payload' => $frozenPayload,
                    'payload_hash' => $payloadHash,
                    'schema_version' => $options['schema_version'] ?? 1,
                    'source_action_status' => $sourceActionStatus,
                    'transport_status' => $transportStatus,
                    'destination_receive_status' => $destinationReceiveStatus,
                    'destination_action_status' => $destinationActionStatus,
                    'overall_status' => $overallStatus,
                    'attempts' => 0,
                    'max_attempts' => $options['max_attempts'] ?? config('talkto.retry.max_attempts', 5),
                    'next_attempt_at' => $options['next_attempt_at'] ?? null,
                    'last_error' => $options['last_error'] ?? null,
                    'sent_at' => $options['sent_at'] ?? null,
                    'failed_at' => $options['failed_at'] ?? null,
                ]);

                $eventClass::create([
                    'talkto_message_id' => $message->id,
                    'message_id' => $message->message_id,
                    'service_name' => $sourceService,
                    'event_type' => 'message_created',
                    'old_status' => null,
                    'new_status' => $message->overall_status,
                    'meta' => array_filter([
                        'flow_name' => $options['flow_name'] ?? null,
                        'source' => $sourceService,
                        'target' => $resolvedTarget,
                        'command' => $command,
                        'business_key' => $businessKey,
                        'idempotency_key' => $idempotencyKey,
                        'source_result' => $options['source_result'] ?? null,
                        'source_meta' => $options['source_meta'] ?? null,
                    ], fn (mixed $value): bool => $value !== null),
                ]);

                return $message;
            });
        } catch (Throwable $exception) {
            if (! $exception instanceof QueryException) {
                throw $exception;
            }

            if ($idempotencyFingerprint === null || ! $this->isDuplicateIdempotencyFingerprintException($exception)) {
                throw $exception;
            }

            $existingMessage = $messageClass::query()
                ->where('idempotency_fingerprint', $idempotencyFingerprint)
                ->first();

            if (! $existingMessage instanceof TalktoMessage) {
                throw $exception;
            }

            return $existingMessage;
        }
    }

    private function resolvedTargetName(string $target): string
    {
        $alias = config("talkto.aliases.{$target}");

        return is_string($alias) && $alias !== '' ? $alias : $target;
    }

    protected function messageModelClass(): string
    {
        return app(TalktoModelResolver::class)->message();
    }

    protected function eventModelClass(): string
    {
        return app(TalktoModelResolver::class)->event();
    }

    private function payloadFreezer(): TalktoPayloadFreezer
    {
        return $this->payloadFreezer ?? app(TalktoPayloadFreezer::class);
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

    private function isDuplicateIdempotencyFingerprintException(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());

        return (
            in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['19', '1062'], true)
            || str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate entry')
        ) && str_contains($message, 'idempotency_fingerprint');
    }
}
