<?php

namespace Mrezdev\LaravelTalkto\Models;

use Illuminate\Database\Eloquent\Model;
use Mrezdev\LaravelTalkto\Models\Concerns\UsesTalktoDatabase;

/**
 * @internal Local initial queue-publication ledger, separate from message state.
 */
class TalktoDispatchIntent extends Model
{
    use UsesTalktoDatabase;

    protected $guarded = [];

    protected $casts = [
        'available_at' => 'datetime',
        'claimed_at' => 'datetime',
        'claim_expires_at' => 'datetime',
        'publication_started_at' => 'datetime',
        'published_at' => 'datetime',
        'publication_attempts' => 'integer',
    ];

    public function getTable()
    {
        return $this->talktoTable('dispatch_intents', 'talkto_dispatch_intents');
    }
}
