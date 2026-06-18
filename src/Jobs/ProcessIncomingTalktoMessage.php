<?php

namespace Mrezdev\LaravelTalkto\Jobs;

use Mrezdev\LaravelTalkto\Pipelines\ProcessIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIncomingTalktoMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $talktoMessageId) {}

    public function handle(mixed $resolver = null, ?TalktoRetryPolicy $retryPolicy = null): void
    {
        app(ProcessIncomingTalktoMessagePipeline::class)->process($this->talktoMessageId, $resolver, $retryPolicy);
    }
}
