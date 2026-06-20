<?php

namespace Mrezdev\LaravelTalkto\Support;

/**
 * Public helper for redacting sensitive Talkto payload, header, and config values.
 */
class TalktoSecurityRedactor
{
    private const REDACTED = '[redacted]';

    private const DEFAULT_SECRET_KEYS = [
        'secret',
        'token',
        'password',
        'signature',
        'authorization',
        'api_key',
        'key',
        'private_key',
        'bearer',
    ];

    private const HEADER_SECRET_KEYS = [
        'authorization',
        'x_talkto_signature',
        'x_talkto_nonce',
        'cookie',
        'set_cookie',
    ];

    public function redactValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSecretKey($key)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = $this->redactValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $redacted;
        }

        if (is_object($value)) {
            return $this->redactValue((array) $value, $key);
        }

        if (is_string($value)) {
            return $this->redactText($value);
        }

        return $value;
    }

    public function redactText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_scalar($value)
            ? (string) $value
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($text === false) {
            return null;
        }

        if ($text === '') {
            return $text;
        }

        foreach ($this->configuredSecrets() as $secret) {
            $text = str_replace($secret, self::REDACTED, $text);
        }

        $patterns = [
            '/^(\s*(?:Authorization|X-Talkto-Signature|X-Talkto-Nonce|Cookie|Set-Cookie)\s*:\s*).+$/mi' => '$1'.self::REDACTED,
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i' => 'Bearer '.self::REDACTED,
            '/(["\'])(authorization|x-talkto-signature|x-talkto-nonce|cookie|set-cookie|signature|api[_-]?key|token|password|private[_-]?key|bearer|secret)\1\s*:\s*(["\']).*?\3/i' => '$1$2$1:$3'.self::REDACTED.'$3',
            '/(["\'])(authorization|x-talkto-signature|x-talkto-nonce|cookie|set-cookie|signature|api[_-]?key|token|password|private[_-]?key|bearer|secret)\1\s*=>\s*(["\']).*?\3/i' => '$1$2$1 => $3'.self::REDACTED.'$3',
            '/\b(authorization|x-talkto-signature|x-talkto-nonce|cookie|set-cookie|signature|api[_-]?key|token|password|private[_-]?key|bearer|secret)(\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^,\s;}]+)/i' => '$1$2'.self::REDACTED,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }

    public function redactHeaders(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $key => $value) {
            $redacted[$key] = $this->isHeaderSecretKey((string) $key)
                ? self::REDACTED
                : $this->redactValue($value, is_string($key) ? $key : null);
        }

        return $redacted;
    }

    public function configuredSecrets(): array
    {
        $secrets = [];

        foreach ([config('talkto.outgoing', []), config('talkto.incoming', [])] as $peers) {
            if (! is_array($peers)) {
                continue;
            }

            foreach ($peers as $peer) {
                if (! is_array($peer)) {
                    continue;
                }

                foreach (['secret', 'signing_secret'] as $key) {
                    $secret = $peer[$key] ?? null;

                    if (is_string($secret) && $secret !== '') {
                        $secrets[] = $secret;
                    }
                }
            }
        }

        return array_values(array_unique($secrets));
    }

    private function isSecretKey(string $key): bool
    {
        $normalized = $this->normalizeKey($key);

        foreach ($this->redactedKeys() as $secretKey) {
            if ($normalized === $secretKey || str_contains($normalized, $secretKey)) {
                return true;
            }
        }

        return false;
    }

    private function isHeaderSecretKey(string $key): bool
    {
        $normalized = $this->normalizeKey($key);

        return in_array($normalized, self::HEADER_SECRET_KEYS, true) || $this->isSecretKey($key);
    }

    private function redactedKeys(): array
    {
        $configured = config('talkto.security.redacted_keys', []);

        if (! is_array($configured)) {
            $configured = [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $key): string => $this->normalizeKey((string) $key),
            array_merge(self::DEFAULT_SECRET_KEYS, $configured)
        ))));
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['-', ' '], '_', $key));
    }
}
