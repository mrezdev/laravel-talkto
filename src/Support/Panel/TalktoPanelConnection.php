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
        ];
    }
}
