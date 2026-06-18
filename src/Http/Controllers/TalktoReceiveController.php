<?php

namespace Ibake\TalktoReliable\Http\Controllers;

use Ibake\TalktoReliable\Jobs\ProcessIncomingTalktoMessage;
use Ibake\TalktoReliable\Models\TalktoEvent;
use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Services\TalktoSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TalktoReceiveController
{
    public function __invoke(Request $request, TalktoSignatureVerifier $verifier): JsonResponse
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
            return new JsonResponse([
                'received' => true,
                'duplicate' => true,
                'status' => $existingMessage->overall_status,
                'message_id' => $existingMessage->message_id,
            ], 200);
        }

        $idempotencyKey = $envelope['idempotency_key'] ?? null;

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $processedMessage = $messageClass::query()
                ->where('idempotency_key', $idempotencyKey)
                ->whereIn('overall_status', ['completed', 'succeeded'])
                ->first();

            if ($processedMessage) {
                return new JsonResponse([
                    'received' => true,
                    'duplicate' => true,
                    'status' => 'already_processed',
                    'message_id' => $processedMessage->message_id,
                ], 200);
            }
        }

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

        $jobClass = $this->processIncomingJobClass();
        $jobClass::dispatch($message->id)->afterCommit();

        return new JsonResponse([
            'received' => true,
            'status' => 'queued',
            'message_id' => $message->message_id,
        ], 202);
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
