<?php

namespace Mrezdev\LaravelTalkto\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Pipelines\SendOutgoingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoCurrentServiceGuard;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;

/**
 * @internal Queue job used by the package outgoing send pipeline.
 */
class SendTalktoMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $talktoMessageId) {}

    public function handle(TalktoOutgoingEnvelopeBuilder $builder, ?TalktoRetryPolicy $retryPolicy = null): void
    {
        $message = $this->findMessage();
        $guard = app(TalktoCurrentServiceGuard::class);

        if ($message instanceof TalktoMessage && ! $guard->allowsOutgoingProcessing($message)) {
            Log::warning('Talkto outgoing job skipped because message belongs to another service.', $guard->logContext($message, static::class));

            return;
        }

        app(SendOutgoingTalktoMessagePipeline::class)->send($this->talktoMessageId, $builder, $retryPolicy);
    }

    private function findMessage(): ?TalktoMessage
    {
        $class = config('talkto.models.message', TalktoMessage::class);
        $class = is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;

        $model = new $class;

        if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            return null;
        }

        $message = $class::query()->whereKey($this->talktoMessageId)->first();

        return $message instanceof TalktoMessage ? $message : null;
    }
}
