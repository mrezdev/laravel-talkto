<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoOutgoingTarget;

/**
 * Public value object describing a configured outgoing target.
 */
class TalktoOutgoingTarget
{
    public function __construct(
        private readonly string $name,
        private readonly array $config
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function url(): ?string
    {
        if (array_key_exists('receive_url', $this->config)) {
            return $this->configuredAbsoluteUrl('receive_url');
        }

        $url = $this->baseUrl();

        return $url;
    }

    public function endpoint(): string
    {
        foreach (['receive_endpoint', 'endpoint'] as $key) {
            $endpoint = $this->config[$key] ?? null;

            if (is_string($endpoint) && trim($endpoint) !== '') {
                return trim($endpoint);
            }
        }

        return '/api/talkto/receive';
    }

    public function endpointUrl(): string
    {
        if (array_key_exists('receive_url', $this->config)) {
            return $this->configuredAbsoluteUrl('receive_url');
        }

        $url = $this->baseUrl();

        if ($url === null) {
            throw InvalidTalktoOutgoingTarget::forTarget($this->name, 'URL is not configured');
        }

        return $this->joinUrl($url, $this->endpoint());
    }

    public function callbackEndpoint(): string
    {
        $endpoint = $this->config['callback_endpoint']
            ?? config('talkto.callbacks.endpoint', '/api/talkto/callback');

        return is_string($endpoint) && trim($endpoint) !== '' ? trim($endpoint) : '/api/talkto/callback';
    }

    public function callbackEndpointUrl(): string
    {
        if (array_key_exists('callback_url', $this->config)) {
            return $this->configuredAbsoluteUrl('callback_url');
        }

        $baseUrl = $this->baseUrl();

        if ($baseUrl !== null) {
            return $this->joinUrl($baseUrl, $this->callbackEndpoint());
        }

        $receiveUrl = $this->configuredReceiveUrl('receive_url');

        if ($receiveUrl !== null) {
            return $this->deriveCallbackUrlFromReceiveUrl($receiveUrl, 'receive_url');
        }

        throw InvalidTalktoOutgoingTarget::forTarget(
            $this->name,
            'durable callback URL is not configured; configure callback_url or base_url/url plus callback_endpoint'
        );
    }

    public function secret(): ?string
    {
        $secret = $this->config['secret'] ?? $this->config['signing_secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function headers(): array
    {
        $headers = $this->config['headers'] ?? [];

        return is_array($headers) ? $headers : [];
    }

    public function timeout(): ?int
    {
        $timeout = $this->config['timeout'] ?? $this->config['timeout_seconds'] ?? null;

        if ($timeout === null || $timeout === '') {
            return null;
        }

        return max(1, (int) $timeout);
    }

    public function transport(): string
    {
        $transport = $this->config['transport'] ?? $this->config['mode'] ?? 'reliable';

        return is_string($transport) && $transport !== '' ? $transport : 'reliable';
    }

    public function options(): array
    {
        $reserved = [
            'url',
            'base_url',
            'receive_url',
            'callback_url',
            'receive_endpoint',
            'endpoint',
            'callback_endpoint',
            'secret',
            'signing_secret',
            'headers',
            'timeout',
            'timeout_seconds',
            'transport',
            'mode',
        ];

        return array_diff_key($this->config, array_flip($reserved));
    }

    public function raw(): array
    {
        return $this->config;
    }

    private function baseUrl(): ?string
    {
        foreach (['base_url', 'url'] as $key) {
            if (array_key_exists($key, $this->config)) {
                return $this->configuredAbsoluteUrl($key);
            }
        }

        return null;
    }

    private function configuredReceiveUrl(string $key): ?string
    {
        if (! array_key_exists($key, $this->config)) {
            return null;
        }

        return $this->configuredAbsoluteUrl($key);
    }

    private function configuredAbsoluteUrl(string $key): string
    {
        $url = $this->config[$key] ?? null;

        if (! is_string($url) || trim($url) === '') {
            throw InvalidTalktoOutgoingTarget::forTarget($this->name, "{$key} must be a non-empty absolute HTTP URL");
        }

        $url = trim($url);
        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || trim((string) $parts['host']) === ''
        ) {
            throw InvalidTalktoOutgoingTarget::forTarget($this->name, "{$key} must be an absolute HTTP or HTTPS URL");
        }

        return $url;
    }

    private function deriveCallbackUrlFromReceiveUrl(string $receiveUrl, string $sourceKey): string
    {
        $receiveEndpoint = $this->normalizeEndpointPath($this->endpoint());
        $callbackEndpoint = $this->normalizeEndpointPath($this->callbackEndpoint());
        $normalizedReceiveUrl = rtrim($receiveUrl, '/');
        $normalizedReceiveSuffix = '/'.$receiveEndpoint;

        if (! str_ends_with($normalizedReceiveUrl, $normalizedReceiveSuffix)) {
            throw InvalidTalktoOutgoingTarget::forTarget(
                $this->name,
                "durable callback URL could not be inferred from {$sourceKey}; configure callback_url or base_url/url plus callback_endpoint"
            );
        }

        $baseUrl = substr($normalizedReceiveUrl, 0, -strlen($normalizedReceiveSuffix));

        return $this->joinUrl($baseUrl, $callbackEndpoint);
    }

    private function joinUrl(string $baseUrl, string $endpoint): string
    {
        return rtrim($baseUrl, '/').'/'.$this->normalizeEndpointPath($endpoint);
    }

    private function normalizeEndpointPath(string $endpoint): string
    {
        return trim($endpoint, '/');
    }
}
