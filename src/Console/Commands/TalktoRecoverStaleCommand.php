<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use Mrezdev\LaravelTalkto\Services\TalktoStaleMessageRecoveryService;

class TalktoRecoverStaleCommand extends Command
{
    protected $signature = 'talkto:recover-stale
        {--dry-run : Report stale candidates without mutating or dispatching}
        {--direction= : Limit to incoming or outgoing}
        {--older-than= : Override stale threshold in minutes}
        {--limit=100 : Maximum stale messages to process}';

    protected $description = 'Recover Talkto messages stuck in stale sending or processing states.';

    public function handle(TalktoStaleMessageRecoveryService $recovery): int
    {
        $direction = $this->directionOption();

        if ($direction === false) {
            return self::FAILURE;
        }

        $olderThan = $this->olderThanOption();

        if ($olderThan === false) {
            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            $this->error('Invalid --limit. Use a value between 1 and 1000.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $summary = $recovery->recover($direction, $olderThan, $limit, $dryRun);

        $this->line('Stale Talkto recovery');
        $this->line('Candidates: '.$summary['candidates']);
        $this->line('Recovered: '.$summary['recovered']);
        $this->line('Stale processing recovered: '.$summary['stale_processing_recovered']);
        $this->line('Orphaned dispatch claims recovered: '.$summary['orphaned_dispatch_claims_recovered']);
        $this->line('Failed/dead-lettered: '.$summary['failed']);
        $this->line('Skipped: '.$summary['skipped']);
        $this->line('Dispatch claims skipped: '.$summary['dispatch_claims_skipped']);
        $this->line('Claim changed: '.$summary['claim_changed']);
        $this->line('Dispatched: '.$summary['dispatched']);
        $this->line('Direction: '.($direction ?? 'all'));
        $this->line('Older than minutes: '.$olderThan);

        if ($dryRun) {
            $this->line('Dry run: no changes were made.');
        }

        foreach ($summary['messages'] as $message) {
            $this->line(sprintf(
                '- %s %s %s',
                $message['message_id'] ?? $message['id'],
                $message['direction'] ?? 'unknown',
                $message['status'] ?? 'unknown'
            ));
        }

        return self::SUCCESS;
    }

    private function directionOption(): string|false|null
    {
        $direction = $this->option('direction');

        if ($direction === null || $direction === '') {
            return null;
        }

        if (! is_string($direction) || ! in_array($direction, ['incoming', 'outgoing'], true)) {
            $this->error('Invalid --direction. Use incoming or outgoing.');

            return false;
        }

        return $direction;
    }

    private function olderThanOption(): int|false
    {
        $value = $this->option('older-than');

        if ($value === null || $value === '') {
            $value = config('talkto.recovery.stale_after_minutes', 15);
        }

        $minutes = filter_var($value, FILTER_VALIDATE_INT);

        if (! is_int($minutes) || $minutes < 1 || $minutes > 10080) {
            $this->error('Invalid --older-than. Use a value between 1 and 10080 minutes.');

            return false;
        }

        return $minutes;
    }
}
