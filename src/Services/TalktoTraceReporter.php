<?php

namespace Mrezdev\LaravelTalkto\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoTraceSnapshot;
use Throwable;

class TalktoTraceReporter
{
    private const SECRET_KEYS = [
        'secret',
        'token',
        'password',
        'signature',
        'authorization',
        'api_key',
        'key',
    ];

    public function traceByMessageId(string $messageId, int $limit = 100, bool $includePayload = false): TalktoTraceSnapshot
    {
        $limit = $this->normalizeLimit($limit);
        $warnings = [];
        $truncated = false;

        if (! $this->tableExists($this->messageModelClass(), 'messages', $warnings)) {
            return $this->snapshot(false, ['message_id' => $messageId], null, null, [], [], [], [], [], $warnings, false, $limit);
        }

        $messageClass = $this->messageModelClass();
        $anchor = $messageClass::query()
            ->where('message_id', $messageId)
            ->orderBy('created_at')
            ->first();

        if (! $anchor) {
            return $this->snapshot(false, ['message_id' => $messageId], null, null, [], [], [], [], [], $warnings, false, $limit);
        }

        $relatedQuery = $messageClass::query()
            ->where(function ($query) use ($anchor, $messageId): void {
                $query->where('message_id', $messageId)
                    ->orWhere('parent_message_id', $messageId);

                if (($anchor->correlation_id ?? null) !== null && (string) $anchor->correlation_id !== '') {
                    $query->orWhere('correlation_id', (string) $anchor->correlation_id);
                }

                if (($anchor->parent_message_id ?? null) !== null && (string) $anchor->parent_message_id !== '') {
                    $query->orWhere('message_id', (string) $anchor->parent_message_id);
                }
            })
            ->orderBy('created_at')
            ->orderBy('id');

        $relatedMessages = $this->limited($relatedQuery, $limit, $truncated);

        return $this->buildSnapshot(
            ['message_id' => $messageId],
            $anchor,
            $relatedMessages,
            $limit,
            $includePayload,
            $warnings,
            $truncated
        );
    }

    public function traceByCorrelationId(string $correlationId, int $limit = 100, bool $includePayload = false): TalktoTraceSnapshot
    {
        $limit = $this->normalizeLimit($limit);
        $warnings = [];
        $truncated = false;

        if (! $this->tableExists($this->messageModelClass(), 'messages', $warnings)) {
            return $this->snapshot(false, ['correlation_id' => $correlationId], null, $correlationId, [], [], [], [], [], $warnings, false, $limit);
        }

        $messageClass = $this->messageModelClass();
        $relatedMessages = $this->limited(
            $messageClass::query()
                ->where('correlation_id', $correlationId)
                ->orderBy('created_at')
                ->orderBy('id'),
            $limit,
            $truncated
        );

        $anchor = $relatedMessages->first();

        return $this->buildSnapshot(
            ['correlation_id' => $correlationId],
            $anchor,
            $relatedMessages,
            $limit,
            $includePayload,
            $warnings,
            $truncated,
            $correlationId
        );
    }

    private function buildSnapshot(
        array $query,
        ?Model $anchor,
        Collection $relatedMessages,
        int $limit,
        bool $includePayload,
        array $warnings,
        bool $truncated,
        ?string $correlationId = null
    ): TalktoTraceSnapshot {
        $messageIds = $relatedMessages
            ->pluck('message_id')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
        $databaseIds = $relatedMessages
            ->pluck('id')
            ->filter(fn (mixed $value): bool => $value !== null)
            ->values()
            ->all();

        $attempts = $this->attempts($messageIds, $databaseIds, $limit, $warnings, $truncated);
        $events = $this->events($messageIds, $databaseIds, $limit, $warnings, $truncated);
        $deadLetters = $this->deadLetters($messageIds, $databaseIds, $limit, $includePayload, $warnings, $truncated);

        $related = $relatedMessages
            ->map(fn (Model $message): array => $this->messageArray($message, $includePayload))
            ->all();

        $timeline = $this->timeline($related, $attempts, $events, $deadLetters, $limit, $truncated);

        return $this->snapshot(
            $anchor !== null,
            $query,
            $anchor ? $this->messageArray($anchor, $includePayload) : null,
            $correlationId ?? ($anchor ? $this->nullableString($anchor->correlation_id ?? null) : null),
            $related,
            $attempts,
            $events,
            $deadLetters,
            $timeline,
            array_values(array_unique($warnings)),
            $truncated,
            $limit
        );
    }

    private function attempts(array $messageIds, array $databaseIds, int $limit, array &$warnings, bool &$truncated): array
    {
        if (($messageIds === [] && $databaseIds === []) || ! $this->tableExists($this->attemptModelClass(), 'attempts', $warnings)) {
            return [];
        }

        return $this->limited(
            $this->attemptModelClass()::query()
                ->where(function ($query) use ($messageIds, $databaseIds): void {
                    if ($messageIds !== []) {
                        $query->whereIn('message_id', $messageIds);
                    }

                    if ($databaseIds !== []) {
                        $method = $messageIds === [] ? 'whereIn' : 'orWhereIn';
                        $query->{$method}('talkto_message_id', $databaseIds);
                    }
                })
                ->orderBy('created_at')
                ->orderBy('id'),
            $limit,
            $truncated
        )->map(fn (Model $attempt): array => [
            'id' => $attempt->id,
            'talkto_message_id' => $attempt->talkto_message_id,
            'message_id' => $attempt->message_id,
            'stage' => $attempt->stage,
            'attempt_no' => $attempt->attempt_no,
            'status' => $attempt->status,
            'http_status' => $attempt->http_status,
            'error_class' => $attempt->error_class,
            'error_message' => $this->redactText($attempt->error_message),
            'request_excerpt' => $this->redactText($attempt->request_excerpt),
            'response_excerpt' => $this->redactText($attempt->response_excerpt),
            'duration_ms' => $attempt->duration_ms,
            'meta' => $this->redactValue($attempt->meta ?? []),
            'created_at' => $this->date($attempt->created_at),
            'updated_at' => $this->date($attempt->updated_at),
        ])->all();
    }

    private function events(array $messageIds, array $databaseIds, int $limit, array &$warnings, bool &$truncated): array
    {
        if (($messageIds === [] && $databaseIds === []) || ! $this->tableExists($this->eventModelClass(), 'events', $warnings)) {
            return [];
        }

        return $this->limited(
            $this->eventModelClass()::query()
                ->where(function ($query) use ($messageIds, $databaseIds): void {
                    if ($messageIds !== []) {
                        $query->whereIn('message_id', $messageIds);
                    }

                    if ($databaseIds !== []) {
                        $method = $messageIds === [] ? 'whereIn' : 'orWhereIn';
                        $query->{$method}('talkto_message_id', $databaseIds);
                    }
                })
                ->orderBy('created_at')
                ->orderBy('id'),
            $limit,
            $truncated
        )->map(fn (Model $event): array => [
            'id' => $event->id,
            'talkto_message_id' => $event->talkto_message_id,
            'message_id' => $event->message_id,
            'service_name' => $event->service_name,
            'event_type' => $event->event_type,
            'old_status' => $event->old_status,
            'new_status' => $event->new_status,
            'meta' => $this->redactValue($event->meta ?? []),
            'created_at' => $this->date($event->created_at),
            'updated_at' => $this->date($event->updated_at),
        ])->all();
    }

    private function deadLetters(array $messageIds, array $databaseIds, int $limit, bool $includePayload, array &$warnings, bool &$truncated): array
    {
        if (($messageIds === [] && $databaseIds === []) || ! $this->tableExists($this->deadLetterModelClass(), 'dead_letters', $warnings)) {
            return [];
        }

        return $this->limited(
            $this->deadLetterModelClass()::query()
                ->where(function ($query) use ($messageIds, $databaseIds): void {
                    if ($messageIds !== []) {
                        $query->whereIn('message_id', $messageIds);
                    }

                    if ($databaseIds !== []) {
                        $method = $messageIds === [] ? 'whereIn' : 'orWhereIn';
                        $query->{$method}('talkto_message_id', $databaseIds);
                    }
                })
                ->orderBy('created_at')
                ->orderBy('id'),
            $limit,
            $truncated
        )->map(fn (Model $deadLetter): array => [
            'id' => $deadLetter->id,
            'talkto_message_id' => $deadLetter->talkto_message_id,
            'message_id' => $deadLetter->message_id,
            'direction' => $deadLetter->direction,
            'source' => $deadLetter->source,
            'target' => $deadLetter->target,
            'command' => $deadLetter->command,
            'payload' => $this->payload($deadLetter->payload ?? [], $includePayload),
            'headers' => '[redacted]',
            'failure_reason' => $this->redactText($deadLetter->failure_reason),
            'exception_class' => $deadLetter->exception_class,
            'exception_message' => $this->redactText($deadLetter->exception_message),
            'failed_status' => $deadLetter->failed_status,
            'original_retry_count' => $deadLetter->original_retry_count,
            'reprocess_count' => $deadLetter->reprocess_count,
            'reprocessed_at' => $this->date($deadLetter->reprocessed_at),
            'status' => $deadLetter->status,
            'created_at' => $this->date($deadLetter->created_at),
            'updated_at' => $this->date($deadLetter->updated_at),
        ])->all();
    }

    private function messageArray(Model $message, bool $includePayload): array
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
            'business_key' => $this->redactText($message->business_key),
            'idempotency_key' => $this->redactText($message->idempotency_key),
            'payload' => $this->payload($message->payload ?? [], $includePayload),
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
            'last_error' => $this->redactText($message->last_error),
            'last_response' => $this->redactText($message->last_response),
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

    private function timeline(array $messages, array $attempts, array $events, array $deadLetters, int $limit, bool &$truncated): array
    {
        $entries = [];

        foreach ($messages as $message) {
            $entries[] = [
                'type' => 'message',
                'message_id' => $message['message_id'],
                'at' => $message['created_at'],
                'status' => $message['overall_status'],
                'summary' => 'message '.$message['direction'].' '.$message['overall_status'],
            ];

            foreach (['sent_at', 'received_at', 'processing_started_at', 'completed_at', 'failed_at'] as $field) {
                if ($message[$field] !== null) {
                    $entries[] = [
                        'type' => 'message_'.$field,
                        'message_id' => $message['message_id'],
                        'at' => $message[$field],
                        'status' => $message['overall_status'],
                        'summary' => str_replace('_', ' ', $field),
                    ];
                }
            }
        }

        foreach ($attempts as $attempt) {
            $entries[] = [
                'type' => 'attempt',
                'message_id' => $attempt['message_id'],
                'at' => $attempt['created_at'],
                'status' => $attempt['status'],
                'summary' => 'attempt '.$attempt['stage'].' #'.$attempt['attempt_no'].' '.$attempt['status'],
            ];
        }

        foreach ($events as $event) {
            $entries[] = [
                'type' => 'event',
                'message_id' => $event['message_id'],
                'at' => $event['created_at'],
                'event_type' => $event['event_type'],
                'summary' => 'event '.$event['event_type'],
            ];
        }

        foreach ($deadLetters as $deadLetter) {
            $entries[] = [
                'type' => 'dead_letter',
                'message_id' => $deadLetter['message_id'],
                'at' => $deadLetter['created_at'],
                'status' => $deadLetter['status'],
                'summary' => 'dead letter '.$deadLetter['status'],
            ];
        }

        usort($entries, fn (array $a, array $b): int => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));

        if (count($entries) > $limit) {
            $truncated = true;

            return array_slice($entries, 0, $limit);
        }

        return $entries;
    }

    private function payload(mixed $payload, bool $includePayload): array|string|null
    {
        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            return $includePayload ? $this->redactValue($payload) : '[redacted]';
        }

        if ($includePayload) {
            return $this->redactValue($payload);
        }

        return [
            'redacted' => true,
            'keys' => array_values(array_map('strval', array_keys($payload))),
        ];
    }

    private function redactValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSecretKey($key)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = $this->redactValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $redacted;
        }

        if (is_string($value)) {
            return $this->redactText($value);
        }

        return $value;
    }

    private function redactText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($text === null || $text === '') {
            return $text;
        }

        foreach ($this->configuredSecrets() as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        $text = preg_replace(
            '/\b(authorization|x-talkto-signature|signature|api[_-]?key|token|password|secret)(\s*[:=]\s*)([^,\s;]+)/i',
            '$1$2[redacted]',
            $text
        ) ?? $text;

        return $text;
    }

    private function isSecretKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        foreach (self::SECRET_KEYS as $secretKey) {
            if ($normalized === $secretKey || str_contains($normalized, $secretKey)) {
                return true;
            }
        }

        return false;
    }

    private function configuredSecrets(): array
    {
        $secrets = [];

        foreach ([config('talkto.outgoing', []), config('talkto.incoming', [])] as $targets) {
            if (! is_array($targets)) {
                continue;
            }

            foreach ($targets as $target) {
                if (! is_array($target)) {
                    continue;
                }

                foreach (['secret', 'signing_secret'] as $key) {
                    $secret = $target[$key] ?? null;

                    if (is_string($secret) && $secret !== '') {
                        $secrets[] = $secret;
                    }
                }
            }
        }

        return array_values(array_unique($secrets));
    }

    private function limited($query, int $limit, bool &$truncated): Collection
    {
        $items = $query->limit($limit + 1)->get();

        if ($items->count() > $limit) {
            $truncated = true;

            return $items->take($limit)->values();
        }

        return $items;
    }

    private function tableExists(string $modelClass, string $section, array &$warnings): bool
    {
        try {
            $table = (new $modelClass)->getTable();

            if (Schema::hasTable($table)) {
                return true;
            }
        } catch (Throwable) {
            $table = 'unknown';
        }

        $warnings[] = $section.'_table_missing';

        return false;
    }

    private function snapshot(
        bool $found,
        array $query,
        ?array $anchorMessage,
        ?string $correlationId,
        array $relatedMessages,
        array $attempts,
        array $events,
        array $deadLetters,
        array $timeline,
        array $warnings,
        bool $truncated,
        int $limit
    ): TalktoTraceSnapshot {
        return new TalktoTraceSnapshot(
            $found,
            $query,
            $anchorMessage,
            $correlationId,
            $relatedMessages,
            $attempts,
            $events,
            $deadLetters,
            $timeline,
            $warnings,
            $truncated,
            $limit
        );
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min(500, $limit));
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return $value === null ? null : (string) $value;
    }

    private function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }

    private function attemptModelClass(): string
    {
        $class = config('talkto.models.attempt', TalktoAttempt::class);

        return is_string($class) && is_a($class, TalktoAttempt::class, true)
            ? $class
            : TalktoAttempt::class;
    }

    private function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }

    private function deadLetterModelClass(): string
    {
        $class = config('talkto.models.dead_letter', TalktoDeadLetter::class);

        return is_string($class) && is_a($class, TalktoDeadLetter::class, true)
            ? $class
            : TalktoDeadLetter::class;
    }
}
