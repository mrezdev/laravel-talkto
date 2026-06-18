<?php

namespace Ibake\TalktoReliable\Http\Controllers;

use Ibake\TalktoReliable\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Ibake\TalktoReliable\Services\TalktoSignatureVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TalktoReceiveController
{
    public function __invoke(Request $request, TalktoSignatureVerifier $verifier): JsonResponse
    {
        return app(ReceiveIncomingTalktoMessagePipeline::class)->receive($request, $verifier);
    }

    private function isDuplicateMessageIdException(QueryException $exception, string $messageTable): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());
        $table = strtolower($messageTable);

        $isDuplicateConstraint = in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['19', '1062'], true)
            || str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate entry');

        return $isDuplicateConstraint
            && str_contains($message, $table)
            && str_contains($message, 'message_id');
    }
}
