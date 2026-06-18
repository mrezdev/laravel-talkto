<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers;

use Mrezdev\LaravelTalkto\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TalktoReceiveController
{
    public function __invoke(Request $request, TalktoSignatureVerifier $verifier): JsonResponse
    {
        return app(ReceiveIncomingTalktoMessagePipeline::class)->receive($request, $verifier);
    }
}
