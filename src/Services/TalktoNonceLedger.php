<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Database\QueryException;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;

/**
 * @internal Replay-protection ledger behind v2 nonce enforcement.
 */
class TalktoNonceLedger
{
    public function nonceHash(string $signatureVersion, string $source, string $target, string $nonce): string
    {
        return hash('sha256', implode('|', [
            $signatureVersion,
            $source,
            $target,
            $nonce,
        ]));
    }

    public function consume(
        string $signatureVersion,
        string $source,
        string $target,
        string $nonce,
        ?string $messageId = null,
        ?string $signedTimestamp = null
    ): bool {
        if (! (bool) config('talkto.security.replay_protection.enabled', true)) {
            return true;
        }

        if ($signatureVersion !== 'v2' || $nonce === '') {
            return false;
        }

        $now = now();
        $days = (int) config('talkto.retention.nonces_days', 7);
        $expiresAt = $now->copy()->addDays($days > 0 ? $days : 7);
        $modelClass = $this->nonceModelClass();

        try {
            return (bool) $modelClass::query()->insert([
                'nonce_hash' => $this->nonceHash($signatureVersion, $source, $target, $nonce),
                'source_service' => $source,
                'target_service' => $target,
                'message_id' => $messageId,
                'signature_version' => $signatureVersion,
                'signed_timestamp' => $signedTimestamp,
                'used_at' => $now,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            if ($this->isDuplicateNonceException($exception, (new $modelClass)->getTable())) {
                return false;
            }

            throw $exception;
        }
    }

    private function isDuplicateNonceException(QueryException $exception, string $nonceTable): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());
        $table = strtolower($nonceTable);

        $isDuplicateConstraint = in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['19', '1062'], true)
            || str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate entry');

        return $isDuplicateConstraint
            && (str_contains($message, 'nonce_hash') || str_contains($message, $table));
    }

    private function nonceModelClass(): string
    {
        return app(TalktoModelResolver::class)->nonce();
    }
}
