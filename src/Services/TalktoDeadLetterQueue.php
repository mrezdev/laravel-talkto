<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Throwable;

class TalktoDeadLetterQueue
{
    public const STATUS_OPEN = 'open';

    public const STATUS_REPROCESSING = 'reprocessing';

    public const STATUS_REPROCESSED = 'reprocessed';

    public const STATUS_FAILED_REPROCESS = 'failed_reprocess';

    public const STATUS_IGNORED = 'ignored';

    public function store(
        TalktoMessage $message,
        ?string $failureReason = null,
        ?Throwable $exception = null,
        array $headers = []
    ): TalktoDeadLetter {
        $deadLetterClass = $this->deadLetterModelClass();
        $existing = $this->findExistingDeadLetter($message);

        if ($existing) {
            return $this->refreshExistingFinalFailure($existing, $message, $failureReason, $exception);
        }

        try {
            $deadLetter = $deadLetterClass::query()->create($this->deadLetterAttributes($message, $failureReason, $exception, $headers));
        } catch (QueryException $queryException) {
            if (! $this->isDuplicateDeadLetterException($queryException)) {
                throw $queryException;
            }

            $existing = $this->findExistingDeadLetter($message);

            if (! $existing) {
                throw $queryException;
            }

            return $this->refreshExistingFinalFailure($existing, $message, $failureReason, $exception);
        }

        $this->recordEvent($message, 'dead_lettered', [
            'dead_letter_id' => $deadLetter->id,
            'failed_status' => $message->overall_status,
            'retry_count' => (int) ($message->retry_count ?? 0),
        ]);

        return $deadLetter;
    }

    public function autoStoreEnabled(): bool
    {
        return (bool) config('talkto.dead_letter.enabled', true)
            && (bool) config('talkto.dead_letter.auto_store_on_final_failure', true);
    }

    public function canReprocess(TalktoDeadLetter $deadLetter, bool $force = false): bool
    {
        if (! (bool) config('talkto.dead_letter.enabled', true)) {
            return false;
        }

        if ($force) {
            return true;
        }

        if (! (bool) config('talkto.dead_letter.allow_reprocess', true)) {
            return false;
        }

        if (! in_array($deadLetter->status, [self::STATUS_OPEN, self::STATUS_FAILED_REPROCESS], true)) {
            return false;
        }

        return ((int) $deadLetter->reprocess_count) < (int) config('talkto.dead_letter.max_reprocess_attempts', 3);
    }

    public function claimForReprocess(TalktoDeadLetter $deadLetter, bool $force = false): ?TalktoDeadLetter
    {
        $deadLetterClass = $this->deadLetterModelClass();

        return DB::transaction(function () use ($deadLetterClass, $deadLetter, $force): ?TalktoDeadLetter {
            $locked = $deadLetterClass::query()
                ->whereKey($deadLetter->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $this->canReprocess($locked, $force)) {
                return null;
            }

            $locked->forceFill([
                'status' => self::STATUS_REPROCESSING,
                'reprocess_count' => ((int) $locked->reprocess_count) + 1,
                'reprocessed_at' => now(),
            ])->save();

            return $locked->fresh();
        });
    }

    public function markReprocessing(TalktoDeadLetter $deadLetter): TalktoDeadLetter
    {
        $deadLetter->forceFill([
            'status' => self::STATUS_REPROCESSING,
            'reprocess_count' => ((int) $deadLetter->reprocess_count) + 1,
            'reprocessed_at' => now(),
        ])->save();

        return $deadLetter;
    }

    public function markReprocessed(TalktoDeadLetter $deadLetter): TalktoDeadLetter
    {
        $deadLetter->forceFill([
            'status' => self::STATUS_REPROCESSED,
            'reprocessed_at' => now(),
        ])->save();

        return $deadLetter;
    }

    public function markReprocessedForMessage(TalktoMessage $message): ?TalktoDeadLetter
    {
        $deadLetterClass = $this->deadLetterModelClass();
        $messageKey = $message->getKey();
        $deadLetter = $deadLetterClass::query()
            ->where('status', self::STATUS_REPROCESSING)
            ->where(function ($query) use ($message, $messageKey): void {
                $query->where('message_id', $message->message_id);

                if ($messageKey !== null) {
                    $query->orWhere('talkto_message_id', $messageKey);
                }
            })
            ->first();

        if (! $deadLetter) {
            return null;
        }

        $deadLetter = $this->markReprocessed($deadLetter);
        $this->recordEvent($message, 'dead_letter_reprocessed', [
            'dead_letter_id' => $deadLetter->id,
            'reprocess_count' => (int) $deadLetter->reprocess_count,
        ]);

        return $deadLetter;
    }

    public function markFailedReprocess(TalktoDeadLetter $deadLetter, ?string $reason = null, ?Throwable $exception = null): TalktoDeadLetter
    {
        $deadLetter->forceFill([
            'status' => self::STATUS_FAILED_REPROCESS,
            'failure_reason' => $this->excerpt($reason ?: $deadLetter->failure_reason),
            'exception_class' => $exception ? $exception::class : $deadLetter->exception_class,
            'exception_message' => $exception ? $this->excerpt($exception->getMessage()) : $deadLetter->exception_message,
            'reprocessed_at' => now(),
        ])->save();

        return $deadLetter->fresh();
    }

    public function markIgnored(TalktoDeadLetter $deadLetter, ?string $reason = null): TalktoDeadLetter
    {
        $deadLetter->forceFill([
            'status' => self::STATUS_IGNORED,
            'failure_reason' => $this->excerpt($reason ?: $deadLetter->failure_reason),
        ])->save();

        return $deadLetter->fresh();
    }

    public function recordEvent(TalktoMessage $message, string $eventType, array $meta = []): void
    {
        $eventClass = $this->eventModelClass();

        $eventClass::query()->create([
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => $eventType,
            'old_status' => $message->overall_status,
            'new_status' => $message->overall_status,
            'meta' => $meta,
        ]);
    }

    private function findExistingDeadLetter(TalktoMessage $message): ?TalktoDeadLetter
    {
        $deadLetterClass = $this->deadLetterModelClass();

        $messageKey = $message->getKey();

        if ($messageKey !== null) {
            $existing = $deadLetterClass::query()
                ->where('talkto_message_id', $messageKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        if ($message->message_id === null || $message->message_id === '') {
            return null;
        }

        return $deadLetterClass::query()
            ->where('message_id', $message->message_id)
            ->first();
    }

    private function refreshExistingFinalFailure(
        TalktoDeadLetter $deadLetter,
        TalktoMessage $message,
        ?string $failureReason = null,
        ?Throwable $exception = null
    ): TalktoDeadLetter {
        if ($deadLetter->status !== self::STATUS_REPROCESSING) {
            return $deadLetter;
        }

        $deadLetter->forceFill([
            'status' => self::STATUS_FAILED_REPROCESS,
            'failure_reason' => $this->excerpt($failureReason ?: $message->last_error),
            'exception_class' => $exception ? $exception::class : null,
            'exception_message' => $this->excerpt($exception?->getMessage()),
            'failed_status' => $message->overall_status,
            'original_retry_count' => (int) ($message->retry_count ?? 0),
        ])->save();

        return $deadLetter;
    }

    private function deadLetterAttributes(
        TalktoMessage $message,
        ?string $failureReason = null,
        ?Throwable $exception = null,
        array $headers = []
    ): array {
        return [
            'talkto_message_id' => $message->id,
            'message_id' => $message->message_id,
            'direction' => $message->direction,
            'source' => $message->source_service,
            'target' => $message->target_service,
            'command' => $message->command,
            'payload' => $message->payload,
            'headers' => $headers === [] ? null : $headers,
            'failure_reason' => $this->excerpt($failureReason ?: $message->last_error),
            'exception_class' => $exception ? $exception::class : null,
            'exception_message' => $this->excerpt($exception?->getMessage()),
            'failed_status' => $message->overall_status,
            'original_retry_count' => (int) ($message->retry_count ?? 0),
            'status' => self::STATUS_OPEN,
        ];
    }

    private function isDuplicateDeadLetterException(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());

        $hasDuplicateIndicator = in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['1062', '19', '2067', '23505'], true)
            || str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'integrity constraint violation');

        if (! $hasDuplicateIndicator) {
            return false;
        }

        return str_contains($message, 'talkto_message_id')
            || str_contains($message, 'message_id')
            || str_contains($message, 'talkto_dead_letters_talkto_message_id_unique')
            || str_contains($message, 'talkto_dead_letters_message_id_unique');
    }

    private function deadLetterModelClass(): string
    {
        $class = config('talkto.models.dead_letter', TalktoDeadLetter::class);

        return is_string($class) && is_a($class, TalktoDeadLetter::class, true)
            ? $class
            : TalktoDeadLetter::class;
    }

    private function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }

    private function excerpt(?string $value, int $limit = 2000): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
