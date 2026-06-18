<?php

namespace Ibake\TalktoReliable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TalktoMessage extends Model
{
    protected $table = 'talkto_messages';

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
        'payload',
        'payload_hash',
        'schema_version',
        'source_action_status',
        'transport_status',
        'destination_receive_status',
        'destination_action_status',
        'overall_status',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'last_http_status',
        'last_error',
        'last_response',
        'sent_at',
        'received_at',
        'processing_started_at',
        'completed_at',
        'failed_at',
        'locked_at',
        'locked_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function attempts(): HasMany
    {
        return $this->hasMany($this->attemptModelClass(), 'talkto_message_id');
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
