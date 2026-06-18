<?php

use Ibake\TalktoReliable\Jobs\ProcessIncomingTalktoMessage;
use Ibake\TalktoReliable\Jobs\SendTalktoMessage;
use Ibake\TalktoReliable\Pipelines\ProcessIncomingTalktoMessagePipeline;
use Ibake\TalktoReliable\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Ibake\TalktoReliable\Pipelines\SendOutgoingTalktoMessagePipeline;
use Ibake\TalktoReliable\Http\Controllers\TalktoReceiveController;
use Ibake\TalktoReliable\Services\TalktoOutgoingEnvelopeBuilder;
use Ibake\TalktoReliable\Services\TalktoRetryPolicy;
use Ibake\TalktoReliable\Services\TalktoSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

beforeEach(function (): void {
    PipelineArchitectureReceivePipeline::$called = false;
    PipelineArchitectureProcessPipeline::$messageId = null;
    PipelineArchitectureSendPipeline::$messageId = null;
});

test('receive controller delegates to receive pipeline', function (): void {
    app()->instance(ReceiveIncomingTalktoMessagePipeline::class, new PipelineArchitectureReceivePipeline);

    $response = app(TalktoReceiveController::class)->__invoke(
        Request::create('/api/talkto/receive', 'POST', []),
        app(TalktoSignatureVerifier::class)
    );

    expect(PipelineArchitectureReceivePipeline::$called)->toBeTrue()
        ->and($response->getStatusCode())->toBe(202)
        ->and($response->getData(true))->toMatchArray(['received' => true, 'status' => 'queued']);
});

test('incoming processing job delegates to process pipeline', function (): void {
    app()->instance(ProcessIncomingTalktoMessagePipeline::class, new PipelineArchitectureProcessPipeline);

    (new ProcessIncomingTalktoMessage(123))->handle();

    expect(PipelineArchitectureProcessPipeline::$messageId)->toBe(123);
});

test('outgoing send job delegates to send pipeline', function (): void {
    app()->instance(SendOutgoingTalktoMessagePipeline::class, new PipelineArchitectureSendPipeline);

    (new SendTalktoMessage(456))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    expect(PipelineArchitectureSendPipeline::$messageId)->toBe(456);
});

class PipelineArchitectureReceivePipeline extends ReceiveIncomingTalktoMessagePipeline
{
    public static bool $called = false;

    public function receive(Request $request, TalktoSignatureVerifier $verifier): JsonResponse
    {
        self::$called = true;

        return new JsonResponse([
            'received' => true,
            'status' => 'queued',
            'message_id' => 'pipeline-test',
        ], 202);
    }
}

class PipelineArchitectureProcessPipeline extends ProcessIncomingTalktoMessagePipeline
{
    public static ?int $messageId = null;

    public function process(int $talktoMessageId, mixed $resolver = null, ?TalktoRetryPolicy $retryPolicy = null): void
    {
        self::$messageId = $talktoMessageId;
    }
}

class PipelineArchitectureSendPipeline extends SendOutgoingTalktoMessagePipeline
{
    public static ?int $messageId = null;

    public function send(int $talktoMessageId, TalktoOutgoingEnvelopeBuilder $builder, ?TalktoRetryPolicy $retryPolicy = null): void
    {
        self::$messageId = $talktoMessageId;
    }
}
