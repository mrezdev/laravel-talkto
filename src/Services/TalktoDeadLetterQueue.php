<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Database\QueryException;
use Mrezdev\LaravelTalkto\Enums\TalktoDeadLetterStatus;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Mrezdev\LaravelTalkto\Support\TalktoModelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use Throwable;

/**
 * Public service for dead-letter lifecycle integration.
 */
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

        TalktoModelConnection::assertSameConnection($message, $deadLetterClass, $this->eventModelClass());

        try {
            return TalktoModelConnection::transaction($message, function () use ($deadLetterClass, $message, $failureReason, $exception, $headers): TalktoDeadLetter {
                $lockedMessage = $this->lockMessage($message, 'store');

                if (! $lockedMessage instanceof TalktoMessage) {
                    throw new \RuntimeException('Talkto message could not be locked for dead-letter storage.');
                }

                $existing = $this->findExistingDeadLetter($lockedMessage, true, 'store');

                if ($existing) {
                    return $this->refreshExistingFinalFailure($existing, $lockedMessage, $failureReason, $exception);
                }

                $deadLetter = $deadLetterClass::query()->create($this->deadLetterAttributes($lockedMessage, $failureReason, $exception, $headers));

                $this->recordEvent($lockedMessage, 'dead_lettered', [
                    'dead_letter_id' => $deadLetter->id,
                    'failed_status' => $lockedMessage->overall_status,
                    'retry_count' => (int) ($lockedMessage->retry_count ?? 0),
                ]);

                return $deadLetter;
            });
        } catch (QueryException $queryException) {
            if (! $this->isDuplicateDeadLetterException($queryException)) {
                throw $queryException;
            }

            return TalktoModelConnection::transaction($message, function () use ($message, $failureReason, $exception, $queryException): TalktoDeadLetter {
                $lockedMessage = $this->lockMessage($message, 'store');

                if (! $lockedMessage instanceof TalktoMessage) {
                    throw $queryException;
                }

                $existing = $this->findExistingDeadLetter($lockedMessage, true, 'store');

                if (! $existing instanceof TalktoDeadLetter) {
                    throw $queryException;
                }

                return $this->refreshExistingFinalFailure($existing, $lockedMessage, $failureReason, $exception);
            });
        }
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

        if (! in_array($deadLetter->status, [TalktoDeadLetterStatus::Open->value, TalktoDeadLetterStatus::FailedReprocess->value], true)) {
            return $force && $deadLetter->status !== TalktoDeadLetterStatus::Reprocessing->value;
        }

        if ($force) {
            return true;
        }

        if (! (bool) config('talkto.dead_letter.allow_reprocess', true)) {
            return false;
        }

        return ((int) $deadLetter->reprocess_count) < (int) config('talkto.dead_letter.max_reprocess_attempts', 3);
    }

    public function claimForReprocess(TalktoDeadLetter $deadLetter, bool $force = false): ?TalktoDeadLetter
    {
        $deadLetterClass = $this->deadLetterModelClass();

        return TalktoModelConnection::transaction($deadLetter, function () use ($deadLetterClass, $deadLetter, $force): ?TalktoDeadLetter {
            $locked = $deadLetterClass::query()
                ->whereKey($deadLetter->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $this->canReprocess($locked, $force)) {
                return null;
            }

            $locked->forceFill([
                'status' => TalktoDeadLetterStatus::Reprocessing->value,
                'reprocess_count' => ((int) $locked->reprocess_count) + 1,
                'reprocessed_at' => now(),
            ])->save();

            return $locked->fresh();
        });
    }

    public function markReprocessing(TalktoDeadLetter $deadLetter): TalktoDeadLetter
    {
        $deadLetter->forceFill([
            'status' => TalktoDeadLetterStatus::Reprocessing->value,
            'reprocess_count' => ((int) $deadLetter->reprocess_count) + 1,
            'reprocessed_at' => now(),
        ])->save();

        return $deadLetter;
    }

    public function markReprocessed(TalktoDeadLetter $deadLetter): TalktoDeadLetter
    {
        $deadLetter->forceFill([
            'status' => TalktoDeadLetterStatus::Reprocessed->value,
            'reprocessed_at' => now(),
        ])->save();

        return $deadLetter;
    }

    public function markReprocessedForMessage(TalktoMessage $message): ?TalktoDeadLetter
    {
        TalktoModelConnection::assertSameConnection($message, $this->deadLetterModelClass(), $this->eventModelClass());

        $deadLetterClass = $this->deadLetterModelClass();

        return TalktoModelConnection::transaction($message, function () use ($deadLetterClass, $message): ?TalktoDeadLetter {
            $lockedMessage = $this->lockMessage($message, 'mark_reprocessed');

            if (! $lockedMessage instanceof TalktoMessage) {
                return null;
            }

            $messageKey = $lockedMessage->getKey();
            $deadLetter = $deadLetterClass::query()
                ->where('status', TalktoDeadLetterStatus::Reprocessing->value)
                ->where(function ($query) use ($lockedMessage, $messageKey): void {
                    $query->where('message_id', $lockedMessage->message_id);

                    if ($messageKey !== null) {
                        $query->orWhere('talkto_message_id', $messageKey);
                    }
                })
                ->lockForUpdate()
                ->first();

            if (! $deadLetter) {
                return null;
            }

            $this->fireDeadLetterLockedHook($deadLetter, 'mark_reprocessed');

            $deadLetter = $this->markReprocessed($deadLetter);
            $this->recordEvent($lockedMessage, 'dead_letter_reprocessed', [
                'dead_letter_id' => $deadLetter->id,
                'reprocess_count' => (int) $deadLetter->reprocess_count,
            ]);

            return $deadLetter;
        });
    }

    public function markFailedReprocess(TalktoDeadLetter $deadLetter, ?string $reason = null, ?Throwable $exception = null): TalktoDeadLetter
    {
        $deadLetter->forceFill([
            'status' => TalktoDeadLetterStatus::FailedReprocess->value,
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
            'status' => TalktoDeadLetterStatus::Ignored->value,
            'failure_reason' => $this->excerpt($reason ?: $deadLetter->failure_reason),
        ])->save();

        return $deadLetter->fresh();
    }

    public function recordEvent(TalktoMessage $message, string $eventType, array $meta = []): void
    {
        $eventClass = $this->eventModelClass();

        TalktoModelConnection::assertSameConnection($message, $eventClass);

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

    private function findExistingDeadLetter(TalktoMessage $message, bool $lock = false, ?string $operation = null): ?TalktoDeadLetter
    {
        $deadLetterClass = $this->deadLetterModelClass();

        $messageKey = $message->getKey();

        if ($messageKey !== null) {
            $query = $deadLetterClass::query()
                ->where('talkto_message_id', $messageKey);

            if ($lock) {
                $query->lockForUpdate();
            }

            $existing = $query->first();

            if ($existing) {
                if ($lock) {
                    $this->fireDeadLetterLockedHook($existing, $operation);
                }

                return $existing;
            }
        }

        if ($message->message_id === null || $message->message_id === '') {
            return null;
        }

        $query = $deadLetterClass::query()
            ->where('message_id', $message->message_id);

        if ($lock) {
            $query->lockForUpdate();
        }

        $existing = $query->first();

        if ($existing && $lock) {
            $this->fireDeadLetterLockedHook($existing, $operation);
        }

        return $existing;
    }

    private function refreshExistingFinalFailure(
        TalktoDeadLetter $deadLetter,
        TalktoMessage $message,
        ?string $failureReason = null,
        ?Throwable $exception = null
    ): TalktoDeadLetter {
        if ($deadLetter->status !== TalktoDeadLetterStatus::Reprocessing->value) {
            return $deadLetter;
        }

        $deadLetter->forceFill([
            'status' => TalktoDeadLetterStatus::FailedReprocess->value,
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
            'status' => TalktoDeadLetterStatus::Open->value,
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
        return app(TalktoModelResolver::class)->deadLetter();
    }

    private function lockMessage(TalktoMessage $message, string $operation): ?TalktoMessage
    {
        $locked = $message->newQuery()
            ->whereKey($message->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked instanceof TalktoMessage) {
            TalktoDispatchTestHooks::fire('dlq.message_locked', [
                'operation' => $operation,
                'message_db_id' => $locked->id,
                'message_id' => $locked->message_id,
            ]);
        }

        return $locked instanceof TalktoMessage ? $locked : null;
    }

    private function fireDeadLetterLockedHook(TalktoDeadLetter $deadLetter, ?string $operation): void
    {
        TalktoDispatchTestHooks::fire('dlq.dead_letter_locked', [
            'operation' => $operation,
            'dead_letter_id' => $deadLetter->id,
            'message_db_id' => $deadLetter->talkto_message_id,
            'message_id' => $deadLetter->message_id,
        ]);
    }

    private function eventModelClass(): string
    {
        return app(TalktoModelResolver::class)->event();
    }

    private function excerpt(?string $value, int $limit = 2000): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
