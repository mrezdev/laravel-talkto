<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoOutgoingTarget;

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
        $url = $this->config['url'] ?? $this->config['base_url'] ?? $this->config['rm_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function endpoint(): string
    {
        $endpoint = $this->config['endpoint'] ?? '/api/talkto/receive';

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : '/api/talkto/receive';
    }

    public function endpointUrl(): string
    {
        $url = $this->url();

        if ($url === null) {
            throw InvalidTalktoOutgoingTarget::forTarget($this->name, 'URL is not configured');
        }

        if (isset($this->config['rm_url']) && ! isset($this->config['url']) && ! isset($this->config['base_url'])) {
            return $url;
        }

        return rtrim($url, '/').'/'.ltrim($this->endpoint(), '/');
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
            'rm_url',
            'endpoint',
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
}
