<?php

namespace Ibake\TalktoReliable\Http\Controllers;

use Ibake\TalktoReliable\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Ibake\TalktoReliable\Services\TalktoSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TalktoReceiveController
{
    public function __invoke(Request $request, TalktoSignatureVerifier $verifier): JsonResponse
    {
        return app(ReceiveIncomingTalktoMessagePipeline::class)->receive($request, $verifier);
    }
}
