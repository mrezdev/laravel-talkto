<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;
use Throwable;

/**
 * @internal Default sender behind ResultCallbackSenderContract.
 */
class TalktoResultCallbackSender implements ResultCallbackSenderContract
{
    public function __construct(
        private readonly TalktoResultCallbackEnvelopeBuilder $builder,
        private readonly TalktoSecurityRedactor $redactor
    ) {}

    public function sendResult(Model $message, IncomingCommandResultContract $result, array $options = []): array
    {
        if (! config('talkto.callbacks.enabled', true)) {
            $this->recordEvent($message, 'result_callback_skipped', null, 'skipped', [
                'error' => 'callbacks_disabled',
            ]);

            return [
                'sent' => false,
                'status' => 'skipped',
                'message_id' => null,
                'original_message_id' => (string) ($message->message_id ?? ''),
                'error' => 'callbacks_disabled',
            ];
        }

        if (($message->direction ?? null) !== 'incoming') {
            $this->recordEvent($message, 'result_callback_failed', null, 'skipped', [
                'error' => 'invalid_direction',
            ]);

            return [
                'sent' => false,
                'status' => 'skipped',
                'message_id' => null,
                'original_message_id' => (string) ($message->message_id ?? ''),
                'error' => 'invalid_direction',
            ];
        }

        $envelope = $this->builder->buildEnvelope($message, $result, $options);
        $target = (string) $envelope['target'];

        $this->recordEvent($message, 'result_callback_sending_started', null, 'sending', [
            'callback_message_id' => $envelope['message_id'],
            'target' => $target,
            'command' => $envelope['command'],
        ]);

        try {
            $headers = $this->builder->buildHeaders($envelope);
            $endpoint = $this->builder->callbackEndpointFor($target);
            $response = Http::withHeaders($headers)
                ->timeout($this->builder->timeoutFor($target))
                ->post($endpoint, $envelope);

            if ($response->successful()) {
                $this->recordEvent($message, 'result_callback_sent', 'sending', 'sent', [
                    'callback_message_id' => $envelope['message_id'],
                    'target' => $target,
                    'http_status' => $response->status(),
                    'response_excerpt' => $this->excerpt($response->body()),
                ]);

                return [
                    'sent' => true,
                    'status' => 'sent',
                    'message_id' => $envelope['message_id'],
                    'original_message_id' => $envelope['payload']['original_message_id'] ?? null,
                    'http_status' => $response->status(),
                ];
            }

            $this->recordEvent($message, 'result_callback_failed', 'sending', 'failed', [
                'callback_message_id' => $envelope['message_id'],
                'target' => $target,
                'http_status' => $response->status(),
                'response_excerpt' => $this->excerpt($response->body()),
            ]);

            return [
                'sent' => false,
                'status' => 'failed',
                'message_id' => $envelope['message_id'],
                'original_message_id' => $envelope['payload']['original_message_id'] ?? null,
                'http_status' => $response->status(),
                'error' => 'http_error',
            ];
        } catch (Throwable $throwable) {
            $this->recordEvent($message, 'result_callback_failed', 'sending', 'failed', [
                'callback_message_id' => $envelope['message_id'],
                'target' => $target,
                'error_class' => $throwable::class,
                'error_message' => $this->excerpt($throwable->getMessage(), 500),
            ]);

            return [
                'sent' => false,
                'status' => 'failed',
                'message_id' => $envelope['message_id'],
                'original_message_id' => $envelope['payload']['original_message_id'] ?? null,
                'error' => 'send_failed',
            ];
        }
    }

    private function recordEvent(Model $message, string $eventType, ?string $oldStatus, ?string $newStatus, array $meta = []): void
    {
        $eventClass = $this->eventModelClass();

        $eventClass::query()->create([
            'talkto_message_id' => $message->id,
            'message_id' => (string) ($message->message_id ?? ''),
            'service_name' => config('talkto.service', 'app'),
            'event_type' => $eventType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'meta' => array_filter($meta, fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }

    private function excerpt(mixed $value, int $limit = 2000): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = (string) $value;

        if ($value === '') {
            return null;
        }

        return mb_substr((string) $this->redactor->redactText($value), 0, $limit);
    }
}
