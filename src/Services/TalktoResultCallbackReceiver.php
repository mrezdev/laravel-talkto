<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract;
use Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

class TalktoResultCallbackReceiver implements ResultCallbackReceiverContract
{
    private const VALID_CALLBACK_STATUSES = [
        'succeeded',
        'skipped',
        'failed_retryable',
        'failed_final',
    ];

    public function __construct(
        private readonly TalktoSignatureVerifier $verifier,
        private readonly TalktoNonceLedger $nonceLedger
    ) {}

    public function receiveResult(array $envelope, array $headers = []): array
    {
        if (! config('talkto.callbacks.enabled', true)) {
            return $this->rejected('callbacks_disabled', null, null);
        }

        $validation = $this->validateEnvelope($envelope);

        if ($validation !== null) {
            return $this->rejected('validation_failed', null, null);
        }

        $verification = $this->verifier->verifyEnvelope($envelope, $headers);
        $callback = TalktoResultCallbackData::fromEnvelope($envelope);

        if (! ($verification['ok'] ?? false)) {
            $this->recordRejectedIfLinked($callback, (string) ($verification['error'] ?? 'verification_failed'));

            return $this->rejected((string) ($verification['error'] ?? 'verification_failed'), $callback->callbackMessageId, $callback->originalMessageId);
        }

        if (! $this->consumeNonce($verification, $callback->callbackMessageId)) {
            $this->recordRejectedIfLinked($callback, 'replay_nonce_reused');

            return $this->rejected('replay_nonce_reused', $callback->callbackMessageId, $callback->originalMessageId);
        }

        if (! in_array($callback->status, self::VALID_CALLBACK_STATUSES, true)) {
            $this->recordRejectedIfLinked($callback, 'invalid_callback_status');

            return $this->rejected('invalid_callback_status', $callback->callbackMessageId, $callback->originalMessageId);
        }

        $messageClass = $this->messageModelClass();
        $message = $messageClass::query()
            ->where('direction', 'outgoing')
            ->where('message_id', $callback->originalMessageId)
            ->first();

        if (! $message) {
            return $this->rejected('original_message_not_found', $callback->callbackMessageId, $callback->originalMessageId);
        }

        if ($callback->originalCommand !== (string) $message->command) {
            $this->recordEvent($message, 'result_callback_rejected', null, null, [
                'callback_message_id' => $callback->callbackMessageId,
                'error' => 'callback_original_command_mismatch',
                'original_command' => $callback->originalCommand,
                'message_command' => $message->command,
            ]);

            return $this->rejected('callback_original_command_mismatch', $callback->callbackMessageId, $callback->originalMessageId);
        }

        if ($callback->parentMessageId !== null && $callback->parentMessageId !== (string) $message->message_id) {
            $this->recordEvent($message, 'result_callback_rejected', null, null, [
                'callback_message_id' => $callback->callbackMessageId,
                'error' => 'callback_parent_message_mismatch',
                'parent_message_id' => $callback->parentMessageId,
            ]);

            return $this->rejected('callback_parent_message_mismatch', $callback->callbackMessageId, $callback->originalMessageId);
        }

        if ($callback->source !== (string) $message->target_service || $callback->target !== (string) $message->source_service) {
            $this->recordEvent($message, 'result_callback_rejected', null, null, [
                'callback_message_id' => $callback->callbackMessageId,
                'error' => 'callback_relationship_mismatch',
                'source' => $callback->source,
                'target' => $callback->target,
            ]);

            return $this->rejected('callback_relationship_mismatch', $callback->callbackMessageId, $callback->originalMessageId);
        }

        [$destinationStatus, $overallStatus] = $this->statusesFor($callback->status);

        if ($message->destination_action_status === $destinationStatus && $message->overall_status === $overallStatus) {
            $this->recordEvent($message, 'result_callback_duplicate', $message->overall_status, $message->overall_status, [
                'callback_message_id' => $callback->callbackMessageId,
                'status' => $callback->status,
            ]);

            return $this->accepted('duplicate', $callback, true);
        }

        DB::transaction(function () use ($message, $callback, $destinationStatus, $overallStatus): void {
            $messageClass = $this->messageModelClass();
            $message = $messageClass::query()->whereKey($message->id)->lockForUpdate()->first();

            if (! $message) {
                return;
            }

            $oldStatus = $message->overall_status;

            $message->forceFill($this->callbackStateAttributes(
                $destinationStatus,
                $overallStatus,
                $callback->resultData->errorMessage
            ))->save();

            $this->recordEvent($message, 'result_callback_received', $oldStatus, $message->overall_status, [
                'callback_message_id' => $callback->callbackMessageId,
                'status' => $callback->status,
                'source' => $callback->source,
                'command' => $callback->command,
            ]);

            $this->recordEvent($message, 'result_callback_applied', $oldStatus, $message->overall_status, [
                'callback_message_id' => $callback->callbackMessageId,
                'destination_action_status' => $destinationStatus,
                'result' => $callback->resultData->result,
                'result_meta' => $callback->resultData->meta,
            ]);
        });

        return $this->accepted('applied', $callback, false);
    }

    private function validateEnvelope(array $envelope): ?array
    {
        $validator = Validator::make($envelope, [
            'message_id' => ['required', 'string', 'max:100'],
            'source' => ['required', 'string', 'max:80'],
            'target' => ['required', 'string', 'max:80'],
            'command' => ['required', 'string', 'max:150'],
            'payload_hash' => ['required', 'string', 'max:100'],
            'payload' => ['required', 'array'],
            'payload.original_message_id' => ['required', 'string', 'max:100'],
            'payload.original_command' => ['required', 'string', 'max:150'],
            'payload.status' => ['required', 'string'],
            'payload.succeeded' => ['required', 'boolean'],
            'payload.retryable' => ['required', 'boolean'],
            'payload.skipped' => ['required', 'boolean'],
            'payload.result' => ['nullable', 'array'],
            'payload.meta' => ['nullable', 'array'],
        ]);

        return $validator->fails() ? $validator->errors()->toArray() : null;
    }

    private function statusesFor(string $status): array
    {
        return match ($status) {
            'succeeded' => ['succeeded', 'completed'],
            'skipped' => ['skipped', 'completed'],
            'failed_retryable' => ['failed_retryable', 'failed_retryable'],
            'failed_final' => ['failed_final', 'failed_final'],
            default => throw new InvalidArgumentException('Unsupported callback status.'),
        };
    }

    private function callbackStateAttributes(string $destinationStatus, string $overallStatus, ?string $errorMessage): array
    {
        $attributes = [
            'destination_action_status' => $destinationStatus,
            'overall_status' => $overallStatus,
        ];

        if ($overallStatus === 'completed') {
            return $attributes + [
                'completed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ];
        }

        if (in_array($overallStatus, ['failed_retryable', 'failed_final'], true)) {
            return $attributes + [
                'completed_at' => null,
                'failed_at' => now(),
                'last_error' => $errorMessage,
            ];
        }

        return $attributes;
    }

    private function accepted(string $status, TalktoResultCallbackData $callback, bool $duplicate): array
    {
        return [
            'accepted' => true,
            'status' => $status,
            'message_id' => $callback->callbackMessageId,
            'original_message_id' => $callback->originalMessageId,
            'duplicate' => $duplicate,
        ];
    }

    private function rejected(string $error, ?string $messageId, ?string $originalMessageId): array
    {
        return [
            'accepted' => false,
            'status' => 'rejected',
            'message_id' => $messageId,
            'original_message_id' => $originalMessageId,
            'duplicate' => false,
            'error' => $error,
        ];
    }

    private function recordRejectedIfLinked(TalktoResultCallbackData $callback, string $error): void
    {
        if ($callback->originalMessageId === '') {
            return;
        }

        $messageClass = $this->messageModelClass();
        $message = $messageClass::query()
            ->where('direction', 'outgoing')
            ->where('message_id', $callback->originalMessageId)
            ->first();

        if (! $message) {
            return;
        }

        $this->recordEvent($message, 'result_callback_rejected', null, null, [
            'callback_message_id' => $callback->callbackMessageId,
            'error' => $error,
        ]);
    }

    private function recordEvent(TalktoMessage $message, string $eventType, ?string $oldStatus, ?string $newStatus, array $meta = []): void
    {
        $eventClass = $this->eventModelClass();

        $eventClass::query()->create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => $eventType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'meta' => array_filter($meta, fn (mixed $value): bool => $value !== null),
        ]);
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

        return $this->nonceLedger->consume(
            'v2',
            (string) ($verification['source'] ?? ''),
            (string) ($verification['target'] ?? ''),
            $nonce,
            $messageId,
            is_string($verification['signed_timestamp'] ?? null) ? $verification['signed_timestamp'] : null
        );
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
}
