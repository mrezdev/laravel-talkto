<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use Mrezdev\LaravelTalkto\Services\TalktoDispatchIntentService;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class TalktoDispatchIntentsCommand extends Command
{
    protected $signature = 'talkto:dispatch-intents {--execute : Publish safely claimable intents; requires recovery enabled} {--direction=} {--message-id=} {--min-age=30} {--limit=100} {--json}';

    protected $description = 'Inspect durable initial dispatch intents; explicitly publish only safe, owned intents.';

    public function handle(TalktoDispatchIntentService $service): int
    {
        $age = filter_var($this->option('min-age'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($age) || ! is_int($limit)) {
            $this->error('Invalid integer age or limit.');

            return self::FAILURE;
        }
        try {
            $method = $this->option('execute') ? 'execute' : 'inspect';
            $report = $service->{$method}($this->option('direction') ?: null, $this->option('message-id'), $age, $limit);
        } catch (Throwable) {
            $this->error('Dispatch intents are unavailable, disabled, or incompatible with the current transaction, schema, or filters. No legacy fallback was performed.');

            return self::FAILURE;
        }
        $this->output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
