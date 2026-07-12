<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoCurrentServiceGuard;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoDispatchClaimingService;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchClaim;
use Mrezdev\LaravelTalkto\Support\TalktoDispatchTestHooks;
use Mrezdev\LaravelTalkto\Support\TalktoModelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;

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

    public function handle(
        TalktoDeadLetterQueue $deadLetterQueue,
        TalktoDispatchClaimingService $dispatchClaims,
        TalktoCurrentServiceGuard $currentServiceGuard
    ): int {
        $direction = (string) $this->option('direction');

        if (! in_array($direction, ['incoming', 'outgoing', 'all'], true)) {
            $this->error('Invalid direction. Use incoming, outgoing, or all.');

            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            $this->error('Invalid --limit. Use a value between 1 and 1000.');

            return self::FAILURE;
        }

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
        $claimedCount = 0;
        $failedClaim = 0;
        $failedDispatch = 0;

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

            if (! $currentServiceGuard->allowsProcessing($message)) {
                $skipped++;

                if (! $dryRun) {
                    $deadLetterQueue->recordEvent($message, 'dead_letter_reprocess_skipped', [
                        'dead_letter_id' => $deadLetter->id,
                        'reason' => 'wrong_service',
                    ]);
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

            $claim = $dispatchClaims->claimDeadLetterForReprocess($deadLetter, $force, 'dlq-command');

            if (! $claim->claimed || ! $claim->message || ! $claim->deadLetter) {
                $skipped++;

                if (in_array($claim->status, ['dead_letter_not_reprocessable', 'missing_dead_letter'], true)) {
                    $failedClaim++;
                }

                $this->recordSkippedClaim($deadLetterQueue, $claim, $deadLetter);

                continue;
            }

            $claimedCount++;
            $eligible++;

            try {
                TalktoDispatchTestHooks::fire('dispatch.before_queue', [
                    'operation' => 'dlq-command',
                    'message_db_id' => $claim->message->id,
                    'message_id' => $claim->message->message_id,
                    'direction' => $claim->message->direction,
                    'claim_id' => $claim->claimId,
                    'dead_letter_id' => $claim->deadLetter->id,
                ]);

                $dispatchedSuccessfully = $this->dispatchReprocessJob($claim->message);
            } catch (\Throwable $throwable) {
                $dispatchedSuccessfully = false;
                $compensated = $dispatchClaims->compensateDeadLetterClaim($claim, 'Dispatch failed.', $throwable);
                $deadLetterQueue->recordEvent($claim->message, 'dead_letter_reprocess_dispatch_failed', [
                    'dead_letter_id' => $claim->deadLetter->id,
                    'direction' => $claim->message->direction,
                    'exception_class' => $throwable::class,
                    'compensated' => $compensated,
                ]);
            }

            if ($dispatchedSuccessfully) {
                $deadLetterQueue->recordEvent($claim->message, 'dead_letter_reprocess_dispatched', [
                    'dead_letter_id' => $claim->deadLetter->id,
                    'direction' => $claim->message->direction,
                    'reprocess_count' => (int) $claim->deadLetter->reprocess_count,
                    'claim_id' => $claim->claimId,
                ]);
                $dispatched++;

                continue;
            }

            $skipped++;
            $failedDispatch++;
        }

        $dryRunValue = $dryRun ? 'true' : 'false';

        $this->line("scanned={$scanned} eligible={$eligible} dispatched={$dispatched} skipped={$skipped} missing_original={$missingOriginal} claimed={$claimedCount} failed_claim={$failedClaim} failed_dispatch={$failedDispatch} dry_run={$dryRunValue} direction={$direction}");

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

    private function recordSkippedClaim(TalktoDeadLetterQueue $deadLetterQueue, TalktoDispatchClaim $claim, TalktoDeadLetter $deadLetter): void
    {
        if ($claim->status === 'missing_original') {
            $this->recordMissingOriginalEvent($deadLetter);

            return;
        }

        if (! $claim->message instanceof TalktoMessage) {
            return;
        }

        if ($claim->status === 'terminal_success') {
            $deadLetterQueue->recordEvent($claim->message, 'dead_letter_reprocess_skipped', [
                'dead_letter_id' => $deadLetter->id,
                'reason' => 'terminal_success',
            ]);

            return;
        }

        if ($claim->status === 'unsupported_direction') {
            $deadLetterQueue->recordEvent($claim->message, 'dead_letter_reprocess_skipped', [
                'dead_letter_id' => $deadLetter->id,
                'reason' => 'unsupported_direction',
                'direction' => $claim->message->direction,
            ]);

            return;
        }

        if ($claim->status === 'wrong_service') {
            $deadLetterQueue->recordEvent($claim->message, 'dead_letter_reprocess_skipped', [
                'dead_letter_id' => $deadLetter->id,
                'reason' => 'wrong_service',
            ]);
        }
    }

    private function recordMissingOriginalEvent(TalktoDeadLetter $deadLetter): void
    {
        $eventClass = $this->eventModelClass();

        TalktoModelConnection::assertSameConnection($deadLetter, $eventClass);

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
        return app(TalktoModelResolver::class)->deadLetter();
    }

    private function messageModelClass(): string
    {
        return app(TalktoModelResolver::class)->message();
    }

    private function eventModelClass(): string
    {
        return app(TalktoModelResolver::class)->event();
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
