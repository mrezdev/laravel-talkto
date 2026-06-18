<?php

namespace Ibake\TalktoReliable\Console\Commands;

use Ibake\TalktoReliable\Jobs\ProcessIncomingTalktoMessage;
use Ibake\TalktoReliable\Jobs\SendTalktoMessage;
use Ibake\TalktoReliable\Models\TalktoDeadLetter;
use Ibake\TalktoReliable\Models\TalktoEvent;
use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Services\TalktoDeadLetterQueue;
use Illuminate\Console\Command;

class ReprocessTalktoDeadLettersCommand extends Command
{
    protected $signature = 'talkto:dlq-reprocess
        {--id= : Dead letter row id}
        {--message-id= : Original message id}
        {--direction=all : incoming, outgoing, or all}
        {--limit=50 : Maximum dead letters to scan}
        {--dry-run : Show eligible dead letters without dispatching jobs}
        {--force : Bypass status and reprocess count limits}';

    protected $description = 'Dispatch jobs for eligible Talkto dead letter rows.';

    public function handle(TalktoDeadLetterQueue $deadLetterQueue): int
    {
        $direction = (string) $this->option('direction');

        if (! in_array($direction, ['incoming', 'outgoing', 'all'], true)) {
            $this->error('Invalid direction. Use incoming, outgoing, or all.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $deadLetterClass = $this->deadLetterModelClass();
        $query = $deadLetterClass::query()
            ->when(! $force, fn ($query) => $query->whereIn('status', [
                TalktoDeadLetterQueue::STATUS_OPEN,
                TalktoDeadLetterQueue::STATUS_FAILED_REPROCESS,
            ]))
            ->when($this->option('id') !== null, fn ($query) => $query->whereKey((int) $this->option('id')))
            ->when($this->option('message-id') !== null, fn ($query) => $query->where('message_id', (string) $this->option('message-id')))
            ->when($direction !== 'all', fn ($query) => $query->where('direction', $direction))
            ->orderBy('created_at')
            ->limit($limit);

        $scanned = 0;
        $eligible = 0;
        $dispatched = 0;
        $skipped = 0;
        $missingOriginal = 0;

        foreach ($query->get() as $deadLetter) {
            $scanned++;

            if (! $deadLetterQueue->canReprocess($deadLetter, $force)) {
                $skipped++;

                continue;
            }

            $message = $this->findOriginalMessage($deadLetter);

            if (! $message) {
                $missingOriginal++;
                $skipped++;

                if (! $dryRun) {
                    $this->recordMissingOriginalEvent($deadLetter);
                }

                continue;
            }

            if (in_array($message->overall_status, ['succeeded', 'completed'], true)) {
                $skipped++;

                if (! $dryRun) {
                    $deadLetterQueue->recordEvent($message, 'dead_letter_reprocess_skipped', [
                        'dead_letter_id' => $deadLetter->id,
                        'reason' => 'terminal_success',
                    ]);
                }

                continue;
            }

            if (! $this->hasSupportedDirection($message)) {
                $skipped++;

                if (! $dryRun) {
                    $deadLetterQueue->recordEvent($message, 'dead_letter_reprocess_skipped', [
                        'dead_letter_id' => $deadLetter->id,
                        'reason' => 'unsupported_direction',
                        'direction' => $message->direction,
                    ]);
                }

                continue;
            }

            if ($dryRun) {
                $eligible++;

                continue;
            }

            $claimed = $deadLetterQueue->claimForReprocess($deadLetter, $force);

            if (! $claimed) {
                $skipped++;

                continue;
            }

            $eligible++;
            $this->prepareOriginalMessageForReprocess($message);

            if ($this->dispatchReprocessJob($message)) {
                $deadLetterQueue->recordEvent($message, 'dead_letter_reprocess_dispatched', [
                    'dead_letter_id' => $claimed->id,
                    'direction' => $message->direction,
                    'reprocess_count' => (int) $claimed->reprocess_count,
                ]);
                $dispatched++;

                continue;
            }

            $skipped++;
            $deadLetterQueue->recordEvent($message, 'dead_letter_reprocess_skipped', [
                'dead_letter_id' => $claimed->id,
                'reason' => 'unsupported_direction',
                'direction' => $message->direction,
            ]);
        }

        $dryRunValue = $dryRun ? 'true' : 'false';

        $this->line("scanned={$scanned} eligible={$eligible} dispatched={$dispatched} skipped={$skipped} missing_original={$missingOriginal} dry_run={$dryRunValue} direction={$direction}");

        return self::SUCCESS;
    }

    private function findOriginalMessage(TalktoDeadLetter $deadLetter): ?TalktoMessage
    {
        $messageClass = $this->messageModelClass();

        if ($deadLetter->talkto_message_id !== null) {
            $message = $messageClass::query()->whereKey($deadLetter->talkto_message_id)->first();

            if ($message) {
                return $message;
            }
        }

        if ($deadLetter->message_id === null) {
            return null;
        }

        return $messageClass::query()->where('message_id', $deadLetter->message_id)->first();
    }

    private function dispatchReprocessJob(TalktoMessage $message): bool
    {
        if ($message->direction === 'outgoing') {
            $jobClass = $this->sendJobClass();
            $jobClass::dispatch($message->id);

            return true;
        }

        if ($message->direction === 'incoming') {
            $jobClass = $this->processIncomingJobClass();
            $jobClass::dispatch($message->id);

            return true;
        }

        return false;
    }

    private function hasSupportedDirection(TalktoMessage $message): bool
    {
        return in_array($message->direction, ['incoming', 'outgoing'], true);
    }

    private function prepareOriginalMessageForReprocess(TalktoMessage $message): void
    {
        $attributes = [
            'next_retry_at' => null,
            'next_attempt_at' => null,
            'locked_at' => null,
            'locked_by' => null,
        ];

        if ($message->direction === 'outgoing') {
            $attributes['transport_status'] = 'pending';
            $attributes['overall_status'] = 'waiting_to_send';
        }

        if ($message->direction === 'incoming') {
            $attributes['destination_action_status'] = 'queued';
            $attributes['overall_status'] = 'queued';
        }

        $message->forceFill($attributes)->save();
    }

    private function recordMissingOriginalEvent(TalktoDeadLetter $deadLetter): void
    {
        $eventClass = $this->eventModelClass();

        $eventClass::query()->create([
            'talkto_message_id' => null,
            'message_id' => $deadLetter->message_id,
            'service_name' => config('talkto.service', 'app'),
            'event_type' => 'dead_letter_reprocess_missing_original',
            'old_status' => $deadLetter->status,
            'new_status' => $deadLetter->status,
            'meta' => [
                'dead_letter_id' => $deadLetter->id,
            ],
        ]);
    }

    private function deadLetterModelClass(): string
    {
        $class = config('talkto.models.dead_letter', TalktoDeadLetter::class);

        return is_string($class) && is_a($class, TalktoDeadLetter::class, true)
            ? $class
            : TalktoDeadLetter::class;
    }

    private function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }

    private function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }

    private function sendJobClass(): string
    {
        $class = config('talkto.jobs.send_message', SendTalktoMessage::class);

        return is_string($class) && is_a($class, SendTalktoMessage::class, true)
            ? $class
            : SendTalktoMessage::class;
    }

    private function processIncomingJobClass(): string
    {
        $class = config('talkto.jobs.process_incoming', ProcessIncomingTalktoMessage::class);

        return is_string($class) && is_a($class, ProcessIncomingTalktoMessage::class, true)
            ? $class
            : ProcessIncomingTalktoMessage::class;
    }
}
