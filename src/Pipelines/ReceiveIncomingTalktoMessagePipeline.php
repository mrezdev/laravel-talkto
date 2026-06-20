<?php

namespace Mrezdev\LaravelTalkto\Pipelines;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoNonceLedger;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Throwable;

class ReceiveIncomingTalktoMessagePipeline
{
    public function receive(Request $request, TalktoSignatureVerifier $verifier): JsonResponse
    {
        $envelope = $request->all();
        $messageClass = $this->messageModelClass();

        $validator = Validator::make($envelope, [
            'message_id' => ['required', 'string', 'max:100'],
            'source' => ['required', 'string', 'max:80'],
            'target' => ['required', 'string', 'max:80'],
            'command' => ['required', 'string', 'max:150'],
            'payload_hash' => ['required', 'string', 'max:100'],
            'protocol_version' => ['nullable', 'integer'],
            'correlation_id' => ['nullable', 'string', 'max:100'],
            'parent_message_id' => ['nullable', 'string', 'max:100'],
            'business_key' => ['nullable', 'string', 'max:191'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
            'schema_version' => ['nullable', 'integer', 'min:1'],
            'created_at' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return new JsonResponse([
                'received' => false,
                'status' => 'validation_failed',
                'error' => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $verification = $verifier->verifyEnvelope($envelope, $request->headers->all());

        if (! ($verification['ok'] ?? false)) {
            return new JsonResponse([
                'received' => false,
                'status' => 'rejected',
                'error' => $verification['error'] ?? 'verification_failed',
            ], (int) ($verification['status'] ?? 401));
        }

        $messageId = (string) $envelope['message_id'];
        $existingMessage = $messageClass::query()
            ->where('message_id', $messageId)
            ->first();

        if ($existingMessage) {
            return $this->duplicateResponse($existingMessage, 'already_received');
        }

        if (! $this->consumeNonce($verification, $messageId)) {
            return new JsonResponse([
                'received' => false,
                'status' => 'rejected',
                'error' => 'replay_nonce_reused',
            ], 409);
        }

        $idempotencyFingerprint = $this->idempotencyFingerprint($envelope);

        if ($idempotencyFingerprint !== null) {
            $idempotentMessage = $messageClass::query()
                ->where('idempotency_fingerprint', $idempotencyFingerprint)
                ->whereIn('overall_status', $this->idempotencyProtectedStatuses())
                ->first();

            if ($idempotentMessage) {
                return $this->duplicateResponse(
                    $idempotentMessage,
                    $this->idempotencyDuplicateStatus((string) $idempotentMessage->overall_status)
                );
            }
        }

        try {
            $message = DB::transaction(function () use ($envelope, $messageClass): TalktoMessage {
                $eventClass = $this->eventModelClass();

                $message = $messageClass::create([
                    'message_id' => $envelope['message_id'],
                    'correlation_id' => $envelope['correlation_id'] ?? null,
                    'parent_message_id' => $envelope['parent_message_id'] ?? null,
                    'direction' => 'incoming',
                    'source_service' => $envelope['source'],
                    'target_service' => $envelope['target'],
                    'command' => $envelope['command'],
                    'business_key' => $envelope['business_key'] ?? null,
                    'idempotency_key' => $envelope['idempotency_key'] ?? null,
                    'idempotency_fingerprint' => $this->idempotencyFingerprint($envelope),
                    'payload' => $envelope['payload'] ?? null,
                    'payload_hash' => $envelope['payload_hash'],
                    'schema_version' => $envelope['schema_version'] ?? 1,
                    'source_action_status' => null,
                    'transport_status' => null,
                    'destination_receive_status' => 'received',
                    'destination_action_status' => 'queued',
                    'overall_status' => 'queued',
                    'received_at' => now(),
                ]);

                $eventClass::create([
                    'talkto_message_id' => $message->id,
                    'message_id' => $message->message_id,
                    'service_name' => config('talkto.service', 'app'),
                    'event_type' => 'message_received',
                    'old_status' => null,
                    'new_status' => 'queued',
                    'meta' => array_filter([
                        'source' => $envelope['source'],
                        'target' => $envelope['target'],
                        'command' => $envelope['command'],
                        'business_key' => $envelope['business_key'] ?? null,
                    ], fn (mixed $value): bool => $value !== null),
                ]);

                return $message;
            });
        } catch (Throwable $exception) {
            if (! $exception instanceof QueryException) {
                throw $exception;
            }

            $messageTable = (new $messageClass)->getTable();

            if ($this->isDuplicateMessageIdException($exception, $messageTable)) {
                $existingMessage = $messageClass::query()
                    ->where('message_id', $messageId)
                    ->first();

                if (! $existingMessage) {
                    throw $exception;
                }

                return $this->duplicateResponse($existingMessage, 'already_received');
            }

            if (! $this->isDuplicateIdempotencyFingerprintException($exception, $messageTable) || $idempotencyFingerprint === null) {
                throw $exception;
            }

            $existingMessage = $messageClass::query()
                ->where('idempotency_fingerprint', $idempotencyFingerprint)
                ->first();

            if (! $existingMessage) {
                throw $exception;
            }

            return $this->duplicateResponse(
                $existingMessage,
                $this->idempotencyDuplicateStatus((string) $existingMessage->overall_status)
            );
        }

        $jobClass = $this->processIncomingJobClass();
        $jobClass::dispatch($message->id)->afterCommit();

        return new JsonResponse([
            'received' => true,
            'status' => 'queued',
            'message_id' => $message->message_id,
        ], 202);
    }

    private function duplicateResponse(TalktoMessage $message, string $status): JsonResponse
    {
        return new JsonResponse([
            'received' => true,
            'duplicate' => true,
            'status' => $status,
            'message_id' => $message->message_id,
        ], 200);
    }

    private function consumeNonce(array $verification, string $messageId): bool
    {
        if (($verification['signature_version'] ?? null) !== 'v2') {
            return true;
        }

        $nonce = $verification['nonce'] ?? null;

        if (! is_string($nonce) || $nonce === '') {
            return ! (bool) config('talkto.security.replay_protection.enabled', true);
        }

        // Existing message_id duplicates are answered before this point to
        // preserve the package's idempotent duplicate response. New requests,
        // including idempotency-key duplicates, must consume a fresh nonce.
        return app(TalktoNonceLedger::class)->consume(
            'v2',
            (string) ($verification['source'] ?? ''),
            (string) ($verification['target'] ?? ''),
            $nonce,
            $messageId,
            is_string($verification['signed_timestamp'] ?? null) ? $verification['signed_timestamp'] : null
        );
    }

    private function idempotencyProtectedStatuses(): array
    {
        return [
            'queued',
            'processing',
            'waiting_to_send',
            'failed_retryable',
            'completed',
            'succeeded',
        ];
    }

    private function idempotencyDuplicateStatus(string $overallStatus): string
    {
        return match ($overallStatus) {
            'completed', 'succeeded' => 'already_processed',
            'queued', 'processing', 'waiting_to_send', 'failed_retryable' => 'already_accepted',
            default => 'already_received',
        };
    }

    private function idempotencyFingerprint(array $envelope): ?string
    {
        return TalktoMessage::idempotencyFingerprint(
            'incoming',
            (string) $envelope['source'],
            (string) $envelope['target'],
            (string) $envelope['command'],
            $envelope['idempotency_key'] ?? null
        );
    }

    private function isDuplicateMessageIdException(QueryException $exception, string $messageTable): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());
        $table = strtolower($messageTable);

        $isDuplicateConstraint = in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['19', '1062'], true)
            || str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate entry');

        return $isDuplicateConstraint
            && str_contains($message, $table)
            && str_contains($message, 'message_id');
    }

    private function isDuplicateIdempotencyFingerprintException(QueryException $exception, string $messageTable): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());

        $isDuplicateConstraint = in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['19', '1062'], true)
            || str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate entry');

        return $isDuplicateConstraint
            && str_contains($message, 'idempotency_fingerprint');
    }

    private function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }

    private function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }

    private function processIncomingJobClass(): string
    {
        $class = config('talkto.jobs.process_incoming', ProcessIncomingTalktoMessage::class);

        return is_string($class) && is_a($class, ProcessIncomingTalktoMessage::class, true)
            ? $class
            : ProcessIncomingTalktoMessage::class;
    }
}
