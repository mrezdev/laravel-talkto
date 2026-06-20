<?php

namespace Mrezdev\LaravelTalkto\Models;

use Illuminate\Database\Eloquent\Model;
use Mrezdev\LaravelTalkto\Models\Concerns\UsesTalktoDatabase;

class TalktoNonce extends Model
{
    use UsesTalktoDatabase;

    protected $fillable = [
        'nonce_hash',
        'source_service',
        'target_service',
        'message_id',
        'signature_version',
        'signed_timestamp',
        'used_at',
        'expires_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function getTable()
    {
        return $this->talktoTable('nonces', 'talkto_nonces');
    }
}
