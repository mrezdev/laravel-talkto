<?php

namespace Mrezdev\LaravelTalkto\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mrezdev\LaravelTalkto\Models\Concerns\UsesTalktoDatabase;

class TalktoDeadLetter extends Model
{
    use UsesTalktoDatabase;

    protected $fillable = [
        'talkto_message_id',
        'message_id',
        'direction',
        'source',
        'target',
        'command',
        'payload',
        'headers',
        'failure_reason',
        'exception_class',
        'exception_message',
        'failed_status',
        'original_retry_count',
        'reprocess_count',
        'reprocessed_at',
        'status',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'reprocessed_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo($this->messageModelClass(), 'talkto_message_id');
    }

    public function getTable()
    {
        return $this->talktoDeadLetterTable();
    }

    protected function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }
}
