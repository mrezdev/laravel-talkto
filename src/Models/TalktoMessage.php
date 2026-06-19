<?php

namespace Mrezdev\LaravelTalkto\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mrezdev\LaravelTalkto\Models\Concerns\UsesTalktoDatabase;

class TalktoMessage extends Model
{
    use UsesTalktoDatabase;

    protected $fillable = [
        'message_id',
        'correlation_id',
        'parent_message_id',
        'direction',
        'source_service',
        'target_service',
        'command',
        'business_key',
        'idempotency_key',
        'idempotency_fingerprint',
        'payload',
        'payload_hash',
        'schema_version',
        'source_action_status',
        'transport_status',
        'destination_receive_status',
        'destination_action_status',
        'overall_status',
        'attempts',
        'retry_count',
        'max_attempts',
        'next_attempt_at',
        'next_retry_at',
        'last_http_status',
        'last_error',
        'last_response',
        'sent_at',
        'received_at',
        'processing_started_at',
        'last_attempted_at',
        'completed_at',
        'failed_at',
        'locked_at',
        'locked_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'next_attempt_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public static function idempotencyFingerprint(
        ?string $direction,
        ?string $sourceService,
        ?string $targetService,
        ?string $command,
        ?string $idempotencyKey
    ): ?string {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        return hash('sha256', implode('|', [
            (string) $direction,
            (string) $sourceService,
            (string) $targetService,
            (string) $command,
            $idempotencyKey,
        ]));
    }

    protected static function booted(): void
    {
        static::saving(function (TalktoMessage $message): void {
            $message->idempotency_fingerprint = static::idempotencyFingerprint(
                $message->direction,
                $message->source_service,
                $message->target_service,
                $message->command,
                $message->idempotency_key
            );
        });
    }

    public function attempts(): HasMany
    {
        return $this->hasMany($this->attemptModelClass(), 'talkto_message_id');
    }

    public function getTable()
    {
        return $this->talktoTable('messages', 'talkto_messages');
    }

    public function events(): HasMany
    {
        return $this->hasMany($this->eventModelClass(), 'talkto_message_id');
    }

    public function isIncoming(): bool
    {
        return $this->direction === 'incoming';
    }

    public function isOutgoing(): bool
    {
        return $this->direction === 'outgoing';
    }

    public function isCompleted(): bool
    {
        return $this->overall_status === 'completed';
    }

    public function isRetryable(): bool
    {
        return $this->attempts < $this->max_attempts && $this->overall_status !== 'completed';
    }

    protected function attemptModelClass(): string
    {
        $class = config('talkto.models.attempt', TalktoAttempt::class);

        return is_string($class) && is_a($class, TalktoAttempt::class, true)
            ? $class
            : TalktoAttempt::class;
    }

    protected function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }
}
