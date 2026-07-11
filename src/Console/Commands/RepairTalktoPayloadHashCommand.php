<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus;
use Mrezdev\LaravelTalkto\Exceptions\TalktoJsonEncodingException;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Support\TalktoModelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;

class RepairTalktoPayloadHashCommand extends Command
{
    protected $signature = 'talkto:repair-payload-hash
        {message_id : Outgoing Talkto message_id to inspect}
        {--confirm : Apply the repair instead of running a dry run}
        {--reason= : Required operator reason when --confirm is used}';

    protected $description = 'Safely repair one failed outgoing Talkto row whose stored payload hash is stale.';

    public function handle(TalktoPayloadHasher $hasher): int
    {
        $messageId = (string) $this->argument('message_id');
        $confirm = (bool) $this->option('confirm');
        $reason = trim((string) ($this->option('reason') ?? ''));
        $messageClass = $this->messageModelClass();
        $message = $messageClass::query()->where('message_id', $messageId)->first();

        if (! $message instanceof TalktoMessage) {
            $this->error('Talkto message not found.');

            return self::FAILURE;
        }

        if ($message->direction !== TalktoMessageDirection::Outgoing->value) {
            $this->error('Only outgoing Talkto messages can be repaired.');

            return self::FAILURE;
        }

        if (! $this->hasRepairableStatus($message)) {
            $this->error('Message is not in a repairable stopped state. Only failed_final and dead_lettered outgoing messages can be repaired.');

            return self::FAILURE;
        }

        $storedHash = (string) ($message->payload_hash ?? '');
        $deterministicHash = $this->deterministicHash($hasher, $message);

        if ($deterministicHash === null) {
            return self::FAILURE;
        }

        $this->line('Talkto payload hash repair');
        $this->line('message_id='.$message->message_id);
        $this->line('direction='.$message->direction);
        $this->line('overall_status='.$message->overall_status);
        $this->line('stored_payload_hash='.$storedHash);
        $this->line('deterministic_payload_hash='.$deterministicHash);

        if ($storedHash !== '' && hash_equals($storedHash, $deterministicHash)) {
            $this->line('No repair needed: stored payload hash already matches the deterministic hash.');

            return self::SUCCESS;
        }

        if (! $this->hasPayloadHashMismatchEvidence($message)) {
            $this->error('No payload hash mismatch evidence was found in message attempts, events, response, or dead letter data.');

            return self::FAILURE;
        }

        if (! $confirm) {
            $this->line('Dry run: no changes were made. Re-run with --confirm and --reason to update only payload_hash.');

            return self::SUCCESS;
        }

        if ($reason === '') {
            $this->error('The --reason option is required when --confirm is used.');

            return self::FAILURE;
        }

        TalktoModelConnection::assertSameConnection($message, $this->eventModelClass());

        return TalktoModelConnection::transaction($message, function () use ($messageClass, $message, $hasher, $reason): int {
            $locked = $messageClass::query()
                ->whereKey($message->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof TalktoMessage) {
                $this->error('Talkto message not found while applying repair.');

                return self::FAILURE;
            }

            if (! $this->hasRepairableStatus($locked)) {
                $this->error('Message is no longer eligible for repair.');

                return self::FAILURE;
            }

            $storedHash = (string) ($locked->payload_hash ?? '');
            $deterministicHash = $this->deterministicHash($hasher, $locked);

            if ($deterministicHash === null) {
                return self::FAILURE;
            }

            if ($storedHash !== '' && hash_equals($storedHash, $deterministicHash)) {
                $this->line('No repair needed: stored payload hash already matches the deterministic hash.');

                return self::SUCCESS;
            }

            if (! $this->hasPayloadHashMismatchEvidence($locked)) {
                $this->error('Message is no longer eligible for repair.');

                return self::FAILURE;
            }

            $fingerprintBefore = $locked->idempotency_fingerprint;
            $fingerprintAfter = $messageClass::idempotencyFingerprint(
                $locked->direction,
                $locked->source_service,
                $locked->target_service,
                $locked->command,
                $locked->idempotency_key
            );

            $messageClass::query()
                ->whereKey($locked->id)
                ->update([
                    'payload_hash' => $deterministicHash,
                ]);

            $eventClass = $this->eventModelClass();

            $eventClass::query()->create([
                'talkto_message_id' => $locked->id,
                'message_id' => $locked->message_id,
                'service_name' => config('talkto.service', 'app'),
                'event_type' => 'payload_hash_repaired',
                'old_status' => $locked->overall_status,
                'new_status' => $locked->overall_status,
                'meta' => [
                    'reason' => $reason,
                    'old_payload_hash' => $storedHash,
                    'new_payload_hash' => $deterministicHash,
                    'idempotency_fingerprint_changed' => $fingerprintBefore !== $fingerprintAfter,
                ],
            ]);

            $this->line('Repair applied: payload_hash updated. No job was dispatched.');

            return self::SUCCESS;
        });
    }

    private function hasRepairableStatus(TalktoMessage $message): bool
    {
        return in_array($message->overall_status, [
            TalktoMessageStatus::FailedFinal->value,
            TalktoMessageStatus::DeadLettered->value,
        ], true);
    }

    private function deterministicHash(TalktoPayloadHasher $hasher, TalktoMessage $message): ?string
    {
        try {
            return $hasher->hash($message->payload);
        } catch (TalktoJsonEncodingException) {
            $this->error('Unable to calculate the deterministic payload hash for this message.');

            return null;
        }
    }

    private function hasPayloadHashMismatchEvidence(TalktoMessage $message): bool
    {
        $values = [
            $message->last_error,
            $message->last_response,
        ];

        foreach ($message->attempts()->get() as $attempt) {
            $values[] = $attempt->error_class;
            $values[] = $attempt->error_message;
            $values[] = $attempt->response_excerpt;
            $values[] = $this->jsonText($attempt->meta);
        }

        foreach ($message->events()->get() as $event) {
            $values[] = $event->event_type;
            $values[] = $this->jsonText($event->meta);
        }

        foreach ($this->deadLettersFor($message) as $deadLetter) {
            $values[] = $deadLetter->failure_reason;
            $values[] = $deadLetter->exception_message;
        }

        return $this->containsMismatchEvidence($values);
    }

    private function containsMismatchEvidence(array $values): bool
    {
        foreach ($values as $value) {
            $text = is_scalar($value) ? strtolower((string) $value) : '';

            if (
                str_contains($text, 'payload_hash_mismatch')
                || str_contains($text, 'stored_payload_hash_mismatch')
                || str_contains($text, 'stored_payload_hash_unencodable')
            ) {
                return true;
            }
        }

        return false;
    }

    private function jsonText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    /**
     * @return iterable<int, TalktoDeadLetter>
     */
    private function deadLettersFor(TalktoMessage $message): iterable
    {
        $deadLetterClass = $this->deadLetterModelClass();

        return $deadLetterClass::query()
            ->where(function ($query) use ($message): void {
                $query->where('message_id', $message->message_id)
                    ->orWhere('talkto_message_id', $message->id);
            })
            ->get();
    }

    private function messageModelClass(): string
    {
        return app(TalktoModelResolver::class)->message();
    }

    private function eventModelClass(): string
    {
        return app(TalktoModelResolver::class)->event();
    }

    private function deadLetterModelClass(): string
    {
        return app(TalktoModelResolver::class)->deadLetter();
    }
}
