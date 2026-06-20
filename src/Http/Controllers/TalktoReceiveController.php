<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mrezdev\LaravelTalkto\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoSignatureVerifier;

/**
 * @internal Package route adapter; use configured routes and contracts instead.
 */
class TalktoReceiveController
{
    public function __invoke(Request $request, TalktoSignatureVerifier $verifier): JsonResponse
    {
        return app(ReceiveIncomingTalktoMessagePipeline::class)->receive($request, $verifier);
    }
}
