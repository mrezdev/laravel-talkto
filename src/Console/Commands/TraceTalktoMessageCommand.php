<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use Mrezdev\LaravelTalkto\Services\TalktoTraceReporter;
use Mrezdev\LaravelTalkto\Support\TalktoTraceSnapshot;

class TraceTalktoMessageCommand extends Command
{
    protected $signature = 'talkto:trace
        {message_id? : Message id to trace}
        {--correlation= : Correlation id to trace}
        {--json : Output JSON}
        {--limit=100 : Maximum entries per section}
        {--payload : Include redacted payload values}';

    protected $description = 'Show a read-only Talkto message or correlation timeline.';

    public function handle(TalktoTraceReporter $reporter): int
    {
        $messageId = $this->argument('message_id');
        $correlationId = $this->option('correlation');

        if (! is_string($messageId) && ! is_string($correlationId)) {
            $this->error('Provide a message_id or --correlation.');

            return self::FAILURE;
        }

        $limit = $this->validatedLimit();

        if ($limit === null) {
            return self::FAILURE;
        }

        $includePayload = (bool) $this->option('payload');

        if (is_string($messageId) && $messageId !== '') {
            $snapshot = $reporter->traceByMessageId($messageId, $limit, $includePayload);

            if (is_string($correlationId) && $correlationId !== '' && ! (bool) $this->option('json')) {
                $this->warn('Both message_id and --correlation were provided; tracing by message_id.');
            }
        } else {
            $snapshot = $reporter->traceByCorrelationId((string) $correlationId, $limit, $includePayload);
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderHuman($snapshot);

        return self::SUCCESS;
    }

    private function validatedLimit(): ?int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 500) {
            $this->error('Invalid --limit. Use a value between 1 and 500.');

            return null;
        }

        return $limit;
    }

    private function renderHuman(TalktoTraceSnapshot $snapshot): void
    {
        $data = $snapshot->toArray();

        $this->line('Talkto trace');
        $this->line('found='.($data['found'] ? 'yes' : 'no').' limit='.$data['limit'].' truncated='.($data['truncated'] ? 'yes' : 'no'));

        if ($data['correlation_id'] !== null) {
            $this->line('correlation_id='.$data['correlation_id']);
        }

        if (is_array($data['anchor_message'])) {
            $anchor = $data['anchor_message'];
            $this->line('anchor='.$anchor['message_id'].' direction='.$anchor['direction'].' status='.$anchor['overall_status'].' command='.$anchor['command']);
        }

        $this->line(
            'related_messages='.count($data['related_messages'])
            .' attempts='.count($data['attempts'])
            .' events='.count($data['events'])
            .' dead_letters='.count($data['dead_letters'])
        );

        if ($data['timeline'] !== []) {
            $this->table(
                ['at', 'type', 'message_id', 'status/event', 'summary'],
                array_map(fn (array $entry): array => [
                    $entry['at'] ?? '',
                    $entry['type'] ?? '',
                    $entry['message_id'] ?? '',
                    $entry['status'] ?? $entry['event_type'] ?? '',
                    $entry['summary'] ?? '',
                ], $data['timeline'])
            );
        }

        foreach ($data['warnings'] as $warning) {
            $this->warn((string) $warning);
        }
    }
}
