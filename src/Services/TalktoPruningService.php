<?php

namespace Mrezdev\LaravelTalkto\Services;

use Carbon\CarbonInterface;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;
use Mrezdev\LaravelTalkto\Support\TalktoModelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;

/**
 * @internal Runtime service behind the prune command.
 */
class TalktoPruningService
{
    private const TYPES = ['messages', 'attempts', 'events', 'dead-letters', 'nonces'];

    private const TERMINAL_MESSAGE_STATUSES = [
        TalktoMessageStatus::Succeeded->value,
        TalktoMessageStatus::Completed->value,
        TalktoMessageStatus::Failed->value,
        TalktoMessageStatus::FailedFinal->value,
        TalktoMessageStatus::DeadLettered->value,
        TalktoMessageStatus::Cancelled->value,
        TalktoMessageStatus::Skipped->value,
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

        TalktoModelConnection::assertSameConnection(
            $messageClass,
            $this->attemptModelClass(),
            $this->eventModelClass(),
            $this->deadLetterModelClass()
        );

        return TalktoModelConnection::transaction($messageClass, function () use ($messageClass, $ids, $result): array {
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
        return app(TalktoModelResolver::class)->message();
    }

    private function attemptModelClass(): string
    {
        return app(TalktoModelResolver::class)->attempt();
    }

    private function eventModelClass(): string
    {
        return app(TalktoModelResolver::class)->event();
    }

    private function deadLetterModelClass(): string
    {
        return app(TalktoModelResolver::class)->deadLetter();
    }

    private function nonceModelClass(): string
    {
        return app(TalktoModelResolver::class)->nonce();
    }
}
