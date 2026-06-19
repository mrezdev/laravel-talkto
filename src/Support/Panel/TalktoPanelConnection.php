<?php

namespace Mrezdev\LaravelTalkto\Support\Panel;

class TalktoPanelConnection
{
    public function __construct(
        public readonly string $direction,
        public readonly string $service,
        public readonly bool $configured,
        public readonly bool $urlConfigured,
        public readonly bool $secretConfigured,
        public readonly ?string $endpoint = null,
        public readonly array $commands = [],
        public readonly array $warnings = [],
        public readonly array $meta = [],
        public readonly bool $activeHealthConfigured = false,
        public readonly ?string $activeHealthMethod = null,
        public readonly ?string $activeHealthUrl = null,
        public readonly array $activeHealthMeta = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'direction' => $this->direction,
            'service' => $this->service,
            'configured' => $this->configured,
            'url_configured' => $this->urlConfigured,
            'secret_configured' => $this->secretConfigured,
            'endpoint' => $this->endpoint,
            'commands' => $this->commands,
            'warnings' => $this->warnings,
            'meta' => $this->meta,
            'active_health_configured' => $this->activeHealthConfigured,
            'active_health_method' => $this->activeHealthMethod,
            'active_health_url' => $this->redactUrl($this->activeHealthUrl),
            'active_health_meta' => $this->activeHealthMeta,
        ];
    }

    private function redactUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return preg_replace('/([?&][^=]*(?:secret|token|password|signature|api[_-]?key|authorization|bearer|private[_-]?key)[^=]*=)[^&]*/i', '$1[redacted]', $url) ?? $url;
        }

        $query = $parts['query'] ?? null;

        if ($query === null || $query === '') {
            return $url;
        }

        parse_str($query, $params);

        foreach ($params as $key => $value) {
            if ($this->isSensitiveQueryKey((string) $key)) {
                $params[$key] = '[redacted]';
            }
        }

        $redactedQuery = str_replace('%5Bredacted%5D', '[redacted]', http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        $withoutQuery = preg_replace('/\?.*/', '', $url) ?? $url;

        return $redactedQuery === '' ? $withoutQuery : $withoutQuery.'?'.$redactedQuery;
    }

    private function isSensitiveQueryKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        foreach (['secret', 'token', 'password', 'signature', 'authorization', 'api_key', 'key', 'private_key', 'bearer'] as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
