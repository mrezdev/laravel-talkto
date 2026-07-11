<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoRequestJsonException;
use Mrezdev\LaravelTalkto\Services\TalktoSignedRequestDecoder;

/**
 * @internal Package route adapter; use callback contracts instead.
 */
class TalktoResultCallbackController
{
    public function __construct(private readonly ?TalktoSignedRequestDecoder $signedRequestDecoder = null) {}

    public function __invoke(Request $request, ResultCallbackReceiverContract $receiver): JsonResponse
    {
        try {
            $envelope = $this->signedRequestDecoder()->decode($request);
        } catch (InvalidTalktoRequestJsonException $exception) {
            return new JsonResponse([
                'accepted' => false,
                'status' => 'rejected',
                'message_id' => null,
                'original_message_id' => null,
                'duplicate' => false,
                'error' => $exception->error(),
            ], $exception->status());
        }

        $result = $receiver->receiveResult($envelope, $request->headers->all());

        return new JsonResponse($result, $this->statusCode($result));
    }

    private function statusCode(array $result): int
    {
        if (($result['accepted'] ?? false) === true) {
            return 200;
        }

        return match ($result['error'] ?? null) {
            'invalid_signature', 'missing_signature_header', 'missing_timestamp', 'timestamp_outside_tolerance',
            'missing_nonce', 'unsupported_signature_version' => 401,
            'unknown_source', 'wrong_target', 'command_not_allowed', 'missing_source_secret',
            'callbacks_disabled', 'callback_relationship_mismatch', 'callback_original_command_mismatch',
            'callback_parent_message_mismatch' => 403,
            'replay_nonce_reused' => 409,
            'original_message_not_found' => 404,
            default => 422,
        };
    }

    private function signedRequestDecoder(): TalktoSignedRequestDecoder
    {
        return $this->signedRequestDecoder ?? app(TalktoSignedRequestDecoder::class);
    }
}
