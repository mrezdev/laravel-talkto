<?php

namespace Mrezdev\LaravelTalkto\Support\Panel;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

class TalktoPanelJsonPresenter
{
    private const REDACTED = '[redacted]';

    private const SENSITIVE_KEYS = [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'cookie',
        'signature',
        'hmac',
        'bearer',
        'private_key',
        'access_token',
        'refresh_token',
        'x_api_key',
        'x_talkto_signature',
        'x_talkto_secret',
    ];

    public function message(TalktoMessage $message): array
    {
        return [
            'id' => $message->id,
            'message_id' => $message->message_id,
            'correlation_id' => $message->correlation_id,
            'parent_message_id' => $message->parent_message_id,
            'direction' => $message->direction,
            'source_service' => $message->source_service,
            'target_service' => $message->target_service,
            'command' => $message->command,
            'business_key' => $message->business_key,
            'idempotency_key' => $message->idempotency_key,
            'payload' => $this->payload($this->messageAttribute($message, 'payload', [])),
            'payload_hash' => $message->payload_hash,
            'schema_version' => $message->schema_version,
            'source_action_status' => $message->source_action_status,
            'transport_status' => $message->transport_status,
            'destination_receive_status' => $message->destination_receive_status,
            'destination_action_status' => $message->destination_action_status,
            'overall_status' => $message->overall_status,
            'attempts' => $message->attempts,
            'retry_count' => $message->retry_count,
            'max_attempts' => $message->max_attempts,
            'next_attempt_at' => $this->date($message->next_attempt_at),
            'next_retry_at' => $this->date($message->next_retry_at),
            'last_http_status' => $message->last_http_status,
            'last_error' => $message->last_error,
            'last_response' => $this->response($this->messageAttribute($message, 'last_response', '')),
            'sent_at' => $this->date($message->sent_at),
            'received_at' => $this->date($message->received_at),
            'processing_started_at' => $this->date($message->processing_started_at),
            'last_attempted_at' => $this->date($message->last_attempted_at),
            'completed_at' => $this->date($message->completed_at),
            'failed_at' => $this->date($message->failed_at),
            'created_at' => $this->date($message->created_at),
            'updated_at' => $this->date($message->updated_at),
        ];
    }

    public function messages(iterable $messages): array
    {
        $formatted = [];

        foreach ($messages as $message) {
            if ($message instanceof TalktoMessage) {
                $formatted[] = $this->message($message);
            }
        }

        return $formatted;
    }

    public function attempt(mixed $attempt): array
    {
        $data = $this->arrayable($attempt);

        return $this->redactArray($data);
    }

    public function attempts(iterable $attempts): array
    {
        return $this->mapIterable($attempts, fn (mixed $attempt): array => $this->attempt($attempt));
    }

    public function event(mixed $event): array
    {
        $data = $this->arrayable($event);

        if (isset($data['meta']) && is_array($data['meta'])) {
            $data['meta'] = $this->redactArray($data['meta']);
        }

        return $data;
    }

    public function events(iterable $events): array
    {
        return $this->mapIterable($events, fn (mixed $event): array => $this->event($event));
    }

    public function deadLetter(mixed $deadLetter): ?array
    {
        if ($deadLetter === null) {
            return null;
        }

        $data = $this->arrayable($deadLetter);

        if (array_key_exists('payload', $data)) {
            $data['payload'] = $this->payload($data['payload']);
        }

        if (array_key_exists('headers', $data)) {
            $data['headers'] = self::REDACTED;
        }

        return $this->redactArray($data);
    }

    public function trace(array|object $trace, bool $includePayload = false): array
    {
        $data = $this->arrayable($trace);

        if (isset($data['anchor_message']) && is_array($data['anchor_message'])) {
            $data['anchor_message'] = $this->traceMessage($data['anchor_message'], $includePayload);
        }

        if (isset($data['related_messages']) && is_array($data['related_messages'])) {
            $data['related_messages'] = array_map(
                fn (mixed $message): mixed => is_array($message) ? $this->traceMessage($message, $includePayload) : $message,
                $data['related_messages']
            );
        }

        if (isset($data['dead_letters']) && is_array($data['dead_letters'])) {
            $data['dead_letters'] = array_map(
                fn (mixed $deadLetter): mixed => is_array($deadLetter) ? $this->traceDeadLetter($deadLetter, $includePayload) : $deadLetter,
                $data['dead_letters']
            );
        }

        if (isset($data['attempts']) && is_array($data['attempts'])) {
            $data['attempts'] = array_map(
                fn (mixed $attempt): mixed => is_array($attempt) ? $this->redactArray($attempt) : $attempt,
                $data['attempts']
            );
        }

        if (isset($data['events']) && is_array($data['events'])) {
            $data['events'] = array_map(
                fn (mixed $event): mixed => is_array($event) ? $this->event($event) : $event,
                $data['events']
            );
        }

        return $this->redactArray($data);
    }

    public function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redactArray($value);

                continue;
            }

            if ($value instanceof Arrayable || $value instanceof Model) {
                $data[$key] = $this->redactArray($this->arrayable($value));
            }
        }

        return $data;
    }

    private function payload(mixed $payload): mixed
    {
        if (! (bool) config('talkto.panel.messages.show_payload', false)) {
            return $this->redactedPlaceholder($payload);
        }

        return is_array($payload) ? $this->redactArray($payload) : $payload;
    }

    private function response(mixed $response): mixed
    {
        if (! (bool) config('talkto.panel.messages.show_response', false)) {
            return $this->redactedPlaceholder($response);
        }

        if (is_array($response)) {
            return $this->redactArray($response);
        }

        if (is_string($response)) {
            $decoded = json_decode($response, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->redactArray($decoded);
            }
        }

        return $response;
    }

    private function traceMessage(array $message, bool $includePayload): array
    {
        if (array_key_exists('payload', $message)) {
            $message['payload'] = $includePayload
                ? (is_array($message['payload']) ? $this->redactArray($message['payload']) : $message['payload'])
                : $this->redactedPlaceholder($message['payload']);
        }

        if (array_key_exists('last_response', $message)) {
            $message['last_response'] = $this->response($message['last_response']);
        }

        return $this->redactArray($message);
    }

    private function traceDeadLetter(array $deadLetter, bool $includePayload): array
    {
        if (array_key_exists('payload', $deadLetter)) {
            $deadLetter['payload'] = $includePayload
                ? (is_array($deadLetter['payload']) ? $this->redactArray($deadLetter['payload']) : $deadLetter['payload'])
                : $this->redactedPlaceholder($deadLetter['payload']);
        }

        if (array_key_exists('headers', $deadLetter)) {
            $deadLetter['headers'] = self::REDACTED;
        }

        return $this->redactArray($deadLetter);
    }

    private function redactedPlaceholder(mixed $value): array|string|null
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return [
                'redacted' => true,
                'keys' => array_values(array_map('strval', array_keys($value))),
            ];
        }

        return self::REDACTED;
    }

    private function arrayable(array|object $value): array
    {
        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if ($value instanceof Model) {
            return $value->toArray();
        }

        return is_array($value) ? $value : get_object_vars($value);
    }

    private function mapIterable(iterable $items, callable $callback): array
    {
        $mapped = [];

        foreach ($items as $item) {
            $mapped[] = $callback($item);
        }

        return $mapped;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        foreach ($this->sensitiveKeys() as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function sensitiveKeys(): array
    {
        $configured = array_merge(
            $this->stringList(config('talkto.security.redacted_keys', [])),
            $this->stringList(config('talkto.panel.messages.redacted_keys', [])),
        );

        return array_values(array_unique(array_merge(self::SENSITIVE_KEYS, $configured)));
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => strtolower(str_replace(['-', ' '], '_', (string) $value)), $values),
            static fn (string $value): bool => $value !== ''
        ));
    }

    private function messageAttribute(TalktoMessage $message, string $key, mixed $missingValue): mixed
    {
        $attributes = $message->getAttributes();

        if (! array_key_exists($key, $attributes)) {
            return $missingValue;
        }

        return $message->getAttribute($key);
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return $value === null ? null : (string) $value;
    }
}
