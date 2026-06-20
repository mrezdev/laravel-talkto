<?php

namespace Mrezdev\LaravelTalkto\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoNonce;

class TalktoPruningService
{
    private const TYPES = ['messages', 'attempts', 'events', 'dead-letters', 'nonces'];

    private const TERMINAL_MESSAGE_STATUSES = [
        'succeeded',
        'completed',
        'failed',
        'failed_final',
        'dead_lettered',
        'cancelled',
        'skipped',
    ];

    public function prune(string $type, ?int $olderThanSeconds, int $limit, bool $dryRun): array
    {
        $types = $type === 'all' ? self::TYPES : [$type];
        $summary = [
            'type' => $type,
            'dry_run' => $dryRun,
            'limit' => $limit,
            'totals' => [
                'candidates' => 0,
                'deleted' => 0,
            ],
            'types' => [],
        ];

        foreach ($types as $selectedType) {
            $result = $this->pruneType($selectedType, $olderThanSeconds, $limit, $dryRun);
            $summary['types'][$selectedType] = $result;
            $summary['totals']['candidates'] += $result['candidates'];
            $summary['totals']['deleted'] += $result['deleted'];
        }

        return $summary;
    }

    private function pruneType(string $type, ?int $olderThanSeconds, int $limit, bool $dryRun): array
    {
        $cutoff = $this->cutoffFor($type, $olderThanSeconds);

        return match ($type) {
            'messages' => $this->pruneMessages($cutoff, $limit, $dryRun),
            'attempts' => $this->pruneSimple($this->attemptModelClass(), $cutoff, $limit, $dryRun),
            'events' => $this->pruneSimple($this->eventModelClass(), $cutoff, $limit, $dryRun),
            'dead-letters' => $this->pruneSimple($this->deadLetterModelClass(), $cutoff, $limit, $dryRun),
            'nonces' => $this->pruneExpiredNonces($cutoff, $limit, $dryRun),
            default => [
                'candidates' => 0,
                'deleted' => 0,
                'cutoff' => $cutoff->toIso8601String(),
            ],
        };
    }

    private function pruneExpiredNonces(CarbonInterface $cutoff, int $limit, bool $dryRun): array
    {
        $modelClass = $this->nonceModelClass();

        $ids = $modelClass::query()
            ->where('expires_at', '<=', now())
            ->orWhere('created_at', '<=', $cutoff)
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $deleted = 0;

        if (! $dryRun && $ids !== []) {
            $deleted = $modelClass::query()
                ->whereKey($ids)
                ->delete();
        }

        return [
            'candidates' => count($ids),
            'deleted' => $deleted,
            'cutoff' => $cutoff->toIso8601String(),
        ];
    }

    private function pruneSimple(string $modelClass, CarbonInterface $cutoff, int $limit, bool $dryRun): array
    {
        $query = $modelClass::query()
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit($limit);

        $ids = $query->pluck('id')->all();
        $deleted = 0;

        if (! $dryRun && $ids !== []) {
            $deleted = $modelClass::query()
                ->whereKey($ids)
                ->delete();
        }

        return [
            'candidates' => count($ids),
            'deleted' => $deleted,
            'cutoff' => $cutoff->toIso8601String(),
        ];
    }

    private function pruneMessages(CarbonInterface $cutoff, int $limit, bool $dryRun): array
    {
        $messageClass = $this->messageModelClass();

        $messages = $messageClass::query()
            ->where('created_at', '<=', $cutoff)
            ->whereIn('overall_status', self::TERMINAL_MESSAGE_STATUSES)
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'message_id']);

        $ids = $messages->pluck('id')->all();

        $result = [
            'candidates' => count($ids),
            'deleted' => 0,
            'cutoff' => $cutoff->toIso8601String(),
            'related_attempts_deleted' => 0,
            'related_events_deleted' => 0,
            'related_dead_letters_deleted' => 0,
        ];

        if ($dryRun || $ids === []) {
            return $result;
        }

        return DB::transaction(function () use ($messageClass, $ids, $result): array {
            $messages = $messageClass::query()
                ->whereKey($ids)
                ->whereIn('overall_status', self::TERMINAL_MESSAGE_STATUSES)
                ->get(['id', 'message_id']);

            $ids = $messages->pluck('id')->all();
            $messageIds = $messages->pluck('message_id')->filter()->values()->all();

            if ($ids === []) {
                return $result;
            }

            $result['related_attempts_deleted'] = $this->deleteRelated($this->attemptModelClass(), $ids, $messageIds);
            $result['related_events_deleted'] = $this->deleteRelated($this->eventModelClass(), $ids, $messageIds);
            $result['related_dead_letters_deleted'] = $this->deleteRelated($this->deadLetterModelClass(), $ids, $messageIds);
            $result['deleted'] = $messageClass::query()
                ->whereKey($ids)
                ->whereIn('overall_status', self::TERMINAL_MESSAGE_STATUSES)
                ->delete();

            return $result;
        });
    }

    private function deleteRelated(string $modelClass, array $messageDatabaseIds, array $messageIds): int
    {
        return $modelClass::query()
            ->where(function ($query) use ($messageDatabaseIds, $messageIds): void {
                $query->whereIn('talkto_message_id', $messageDatabaseIds);

                if ($messageIds !== []) {
                    $query->orWhereIn('message_id', $messageIds);
                }
            })
            ->delete();
    }

    private function cutoffFor(string $type, ?int $olderThanSeconds): CarbonInterface
    {
        $seconds = $olderThanSeconds ?? ($this->retentionDaysFor($type) * 86400);

        return now()->subSeconds(max(1, $seconds));
    }

    private function retentionDaysFor(string $type): int
    {
        $key = match ($type) {
            'messages' => 'messages_days',
            'attempts' => 'attempts_days',
            'events' => 'events_days',
            'dead-letters' => 'dead_letters_days',
            'nonces' => 'nonces_days',
            default => 'messages_days',
        };

        $days = (int) config("talkto.retention.{$key}", 90);

        return $days > 0 ? $days : 90;
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

    private function nonceModelClass(): string
    {
        $class = config('talkto.models.nonce', TalktoNonce::class);

        return is_string($class) && is_a($class, TalktoNonce::class, true)
            ? $class
            : TalktoNonce::class;
    }
}
