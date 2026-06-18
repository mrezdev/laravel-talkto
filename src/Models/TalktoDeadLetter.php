<?php

namespace Mrezdev\LaravelTalkto\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TalktoDeadLetter extends Model
{
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

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $table = config('talkto.dead_letter.table', 'talkto_dead_letters');

        if (is_string($table) && $table !== '') {
            $this->setTable($table);
        }
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo($this->messageModelClass(), 'talkto_message_id');
    }

    protected function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }
}
