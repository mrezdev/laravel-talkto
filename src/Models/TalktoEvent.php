<?php

namespace Mrezdev\LaravelTalkto\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mrezdev\LaravelTalkto\Models\Concerns\UsesTalktoDatabase;

class TalktoEvent extends Model
{
    use UsesTalktoDatabase;

    protected $fillable = [
        'talkto_message_id',
        'message_id',
        'service_name',
        'event_type',
        'old_status',
        'new_status',
        'meta',
        'reported_to_central_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'reported_to_central_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo($this->messageModelClass(), 'talkto_message_id');
    }

    public function getTable()
    {
        return $this->talktoTable('events', 'talkto_events');
    }

    protected function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }
}
