<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use Mrezdev\LaravelTalkto\Services\TalktoPruningService;

class TalktoPruneCommand extends Command
{
    protected $signature = 'talkto:prune
        {--type=all : messages, attempts, events, dead-letters, nonces, or all}
        {--older-than= : Override retention age, for example 90d, 12h, or 90 for days}
        {--dry-run : Report pruning candidates without deleting records}
        {--limit=100 : Maximum records to delete per type}';

    protected $description = 'Safely prune old Talkto messages, attempts, events, dead letters, and nonce ledger rows.';

    public function handle(TalktoPruningService $pruning): int
    {
        $type = (string) $this->option('type');

        if (! in_array($type, ['messages', 'attempts', 'events', 'dead-letters', 'nonces', 'all'], true)) {
            $this->error('Invalid --type. Use messages, attempts, events, dead-letters, nonces, or all.');

            return self::FAILURE;
        }

        $olderThan = $this->olderThanOption();

        if ($olderThan === false) {
            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 100000) {
            $this->error('Invalid --limit. Use a value between 1 and 100000.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $summary = $pruning->prune($type, $olderThan, $limit, $dryRun);

        $this->line('Talkto pruning');
        $this->line('Type: '.$type);
        $this->line('Dry run: '.($dryRun ? 'yes' : 'no'));

        foreach ($summary['types'] as $selectedType => $result) {
            $label = $this->label($selectedType);

            if ($dryRun) {
                $this->line(sprintf('%s: %d candidates', $label, $result['candidates']));

                continue;
            }

            $this->line(sprintf('%s deleted: %d', $label, $result['deleted']));

            if ($selectedType === 'messages') {
                $this->line(sprintf('Related attempts deleted: %d', $result['related_attempts_deleted']));
                $this->line(sprintf('Related events deleted: %d', $result['related_events_deleted']));
                $this->line(sprintf('Related dead letters deleted: %d', $result['related_dead_letters_deleted']));
            }
        }

        if ($dryRun) {
            $this->line('No changes were made.');
        }

        return self::SUCCESS;
    }

    private function olderThanOption(): int|false|null
    {
        $value = $this->option('older-than');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            $this->error('Invalid --older-than. Use values like 30d, 12h, or 90 for days.');

            return false;
        }

        $value = strtolower(trim((string) $value));

        if (preg_match('/^\d+$/', $value) === 1) {
            $amount = (int) $value;

            if ($amount < 1) {
                $this->error('Invalid --older-than. Use a value greater than zero.');

                return false;
            }

            return $amount * 86400;
        }

        if (preg_match('/^(\d+)([dh])$/', $value, $matches) !== 1) {
            $this->error('Invalid --older-than. Use values like 30d, 12h, or 90 for days.');

            return false;
        }

        $amount = (int) $matches[1];

        if ($amount < 1) {
            $this->error('Invalid --older-than. Use a value greater than zero.');

            return false;
        }

        return $matches[2] === 'h'
            ? $amount * 3600
            : $amount * 86400;
    }

    private function label(string $type): string
    {
        return match ($type) {
            'dead-letters' => 'Dead letters',
            default => ucfirst($type),
        };
    }
}
