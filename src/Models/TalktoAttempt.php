<?php

namespace Mrezdev\LaravelTalkto\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TalktoAttempt extends Model
{
    protected $table = 'talkto_attempts';

    protected $fillable = [
        'talkto_message_id',
        'message_id',
        'stage',
        'attempt_no',
        'status',
        'http_status',
        'error_class',
        'error_message',
        'request_excerpt',
        'response_excerpt',
        'duration_ms',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

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
