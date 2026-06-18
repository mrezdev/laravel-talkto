<?php

namespace Mrezdev\LaravelTalkto\Jobs;

use Mrezdev\LaravelTalkto\Pipelines\SendOutgoingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTalktoMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $talktoMessageId) {}

    public function handle(TalktoOutgoingEnvelopeBuilder $builder, ?TalktoRetryPolicy $retryPolicy = null): void
    {
        app(SendOutgoingTalktoMessagePipeline::class)->send($this->talktoMessageId, $builder, $retryPolicy);
    }
}
