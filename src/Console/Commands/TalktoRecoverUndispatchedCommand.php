<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Services\TalktoUndispatchedInspector;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class TalktoRecoverUndispatchedCommand extends Command
{
    protected $signature = 'talkto:recover-undispatched
        {--direction= : Limit to incoming or outgoing}
        {--message-id= : Inspect one public message ID, including noninitial states}
        {--min-age=30 : Minimum message age in minutes (0..525600)}
        {--limit=100 : Maximum observations (1..1000)}
        {--json : Output sanitized JSON}
        {--execute : Unsupported: fails closed without querying or dispatching}';

    protected $description = 'Inspect possible Talkto dispatch gaps without changing or requeueing messages.';

    public function handle(TalktoUndispatchedInspector $inspector): int
    {
        if ($this->option('execute')) {
            $this->error('Recovery execution is blocked: existing evidence cannot prove that queue publication did not occur. No messages were dispatched.');

            return self::FAILURE;
        }

        $age = filter_var($this->option('min-age'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($age) || ! is_int($limit)) {
            $this->error('Invalid diagnostic age or limit. Use integer values.');

            return self::FAILURE;
        }
        $direction = $this->option('direction');
        $messageId = $this->option('message-id');
        try {
            $report = $inspector->inspect($direction === '' ? null : $direction, $messageId, $age, $limit);
        } catch (InvalidArgumentException) {
            $this->error('Invalid diagnostic filters or unsupported model scope. See --help for the supported bounds.');

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Dispatch diagnostics are unavailable. Check the configured model connection and scopes separately. No messages were dispatched.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
        } else {
            $this->line('Read-only diagnostics. Publication is unknown; recovery is blocked.');
            $this->table(['Message ID', 'Direction', 'Status', 'Classification', 'Recovery eligible'], array_map(
                fn (array $row): array => array_map(
                    fn (mixed $value): string => OutputFormatter::escape((string) $value),
                    [$row['message_id'], $row['direction'], $row['overall_status'], $row['classification'], 'no']
                ),
                $report['candidates']
            ));
            $this->line('Observations: '.$report['count'].' (bounded snapshot, not a missing-job count).');
        }

        return self::SUCCESS;
    }
}
