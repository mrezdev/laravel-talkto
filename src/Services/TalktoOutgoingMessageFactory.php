<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Throwable;

class TalktoOutgoingMessageFactory
{
    public function __construct(
        private readonly TalktoPayloadHasher $payloadHasher,
        private readonly TalktoOutgoingTargetRegistryContract $targets
    ) {}

    public function create(
        string $target,
        string $command,
        mixed $payload = [],
        array $options = []
    ): TalktoMessage {
        $resolvedTarget = $this->resolvedTargetName($target);

        if (! $this->targets->resolve($target)) {
            throw new InvalidArgumentException("Talkto outgoing target [{$target}] is not configured.");
        }

        $normalizedPayload = $this->normalizePayload($payload);
        $payloadHash = $this->payloadHasher->hash($normalizedPayload);
        $sourceService = config('talkto.service', 'app');
        $businessKey = $options['business_key'] ?? null;
        $idempotencyKey = $options['idempotency_key'] ?? null;
        $messageId = $options['message_id'] ?? Str::uuid()->toString();
        $correlationId = $options['correlation_id'] ?? Str::uuid()->toString();
        $sourceActionStatus = $options['source_action_status'] ?? 'succeeded_assumed';
        $transportStatus = array_key_exists('transport_status', $options) ? $options['transport_status'] : 'pending';
        $destinationReceiveStatus = array_key_exists('destination_receive_status', $options) ? $options['destination_receive_status'] : null;
        $destinationActionStatus = array_key_exists('destination_action_status', $options) ? $options['destination_action_status'] : null;
        $overallStatus = $options['overall_status'] ?? 'waiting_to_send';
        $messageClass = $this->messageModelClass();
        $eventClass = $this->eventModelClass();
        $idempotencyFingerprint = $messageClass::idempotencyFingerprint(
            'outgoing',
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

        try {
            return DB::transaction(function () use (
                $messageClass,
                $eventClass,
                $messageId,
                $correlationId,
                $options,
                $sourceService,
                $resolvedTarget,
                $command,
                $businessKey,
                $idempotencyKey,
                $idempotencyFingerprint,
                $normalizedPayload,
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
                    'parent_message_id' => $options['parent_message_id'] ?? null,
                    'direction' => 'outgoing',
                    'source_service' => $sourceService,
                    'target_service' => $resolvedTarget,
                    'command' => $command,
                    'business_key' => $businessKey,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_fingerprint' => $idempotencyFingerprint,
                    'payload' => $normalizedPayload,
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
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }

    protected function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }

    private function normalizePayload(mixed $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        if (is_array($payload)) {
            return $payload;
        }

        if ($payload instanceof Arrayable) {
            return $payload->toArray();
        }

        if ($payload instanceof JsonSerializable) {
            $serialized = $payload->jsonSerialize();

            if ($serialized === null || is_array($serialized)) {
                return $serialized;
            }

            if (is_scalar($serialized)) {
                return ['value' => $serialized];
            }

            throw new InvalidArgumentException('Talkto payload must be arrayable, json serializable, scalar, array, or null.');
        }

        if (is_scalar($payload)) {
            return ['value' => $payload];
        }

        throw new InvalidArgumentException('Talkto payload must be arrayable, json serializable, scalar, array, or null.');
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
