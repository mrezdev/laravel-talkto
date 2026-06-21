<?php

namespace Mrezdev\LaravelTalkto\Services\Panel;

use Illuminate\Support\Collection;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelConnection;

/**
 * @internal Optional panel implementation detail.
 */
class TalktoPanelConnectionRegistry
{
    public function outgoing(): Collection
    {
        return $this->connectionsFromConfig('outgoing', config('talkto.outgoing', []));
    }

    public function incoming(): Collection
    {
        return $this->connectionsFromConfig('incoming', config('talkto.incoming', []), [
            'handlers',
            'unknown_command_strategy',
        ]);
    }

    public function all(): Collection
    {
        return $this->outgoing()
            ->concat($this->incoming())
            ->values();
    }

    private function connectionsFromConfig(string $direction, mixed $config, array $ignoredKeys = []): Collection
    {
        if (! is_array($config)) {
            return collect();
        }

        return collect($config)
            ->reject(fn (mixed $value, string|int $service): bool => in_array((string) $service, $ignoredKeys, true))
            ->map(fn (mixed $settings, string|int $service): TalktoPanelConnection => $this->connection(
                direction: $direction,
                service: (string) $service,
                settings: $settings,
            ))
            ->sortBy([
                fn (TalktoPanelConnection $connection): string => $connection->direction,
                fn (TalktoPanelConnection $connection): string => $connection->service,
            ])
            ->values();
    }

    private function connection(string $direction, string $service, mixed $settings): TalktoPanelConnection
    {
        $warnings = [];
        $meta = [];

        if (! is_array($settings)) {
            return new TalktoPanelConnection(
                direction: $direction,
                service: $service,
                configured: false,
                urlConfigured: false,
                secretConfigured: false,
                endpoint: null,
                commands: [],
                warnings: ['malformed_config'],
                meta: ['config_type' => get_debug_type($settings)]
            );
        }

        $secretConfigured = $this->filled($settings['secret'] ?? null);
        $endpoint = $this->filled($settings['endpoint'] ?? null) ? (string) $settings['endpoint'] : null;
        $commands = $this->commandKeys($settings['allowed_commands'] ?? $settings['commands'] ?? []);
        $activeHealth = $this->activeHealth($settings);

        if (! $secretConfigured) {
            $warnings[] = 'missing_secret';
        }

        if ($direction === 'outgoing') {
            $urlConfigured = $this->filled($settings['url'] ?? $settings['base_url'] ?? null);
            $configured = $urlConfigured && $secretConfigured;

            if (! $urlConfigured) {
                $warnings[] = 'missing_url';
            }

            if ($endpoint === null) {
                $warnings[] = 'missing_endpoint';
            }
        } else {
            $urlConfigured = false;
            $configured = $secretConfigured && $commands !== [];

            if ($commands === []) {
                $warnings[] = 'missing_allowed_commands';
            }

            $meta['url_not_applicable'] = true;
        }

        if (($activeHealth['method_supported'] ?? true) === false) {
            $warnings[] = 'unsupported_active_health_method';
        }

        return new TalktoPanelConnection(
            direction: $direction,
            service: $service,
            configured: $configured,
            urlConfigured: $urlConfigured,
            secretConfigured: $secretConfigured,
            endpoint: $endpoint,
            commands: $commands,
            warnings: $warnings,
            meta: $meta,
            activeHealthConfigured: $activeHealth['configured'],
            activeHealthMethod: $activeHealth['method'],
            activeHealthUrl: $activeHealth['url'],
            activeHealthMeta: $activeHealth['meta'],
        );
    }

    private function activeHealth(array $settings): array
    {
        $health = $settings['health'] ?? [];
        $health = is_array($health) ? $health : [];
        $baseUrl = $this->filled($settings['url'] ?? $settings['base_url'] ?? null)
            ? (string) ($settings['url'] ?? $settings['base_url'])
            : null;
        $url = null;
        $source = null;

        if ($this->filled($health['url'] ?? null)) {
            $url = (string) $health['url'];
            $source = 'health.url';
        } elseif ($this->filled($settings['health_url'] ?? null)) {
            $url = (string) $settings['health_url'];
            $source = 'health_url';
        } elseif ($this->filled($settings['health_endpoint'] ?? null)) {
            $url = $this->resolveHealthEndpoint((string) $settings['health_endpoint'], $baseUrl);
            $source = 'health_endpoint';
        }

        $method = strtoupper(trim((string) ($health['method'] ?? $settings['health_method'] ?? 'GET')));
        $method = $method === '' ? 'GET' : $method;
        $timeout = $health['timeout'] ?? $settings['health_timeout'] ?? null;
        $methodSupported = in_array($method, ['GET', 'HEAD'], true);
        $meta = [
            'url_source' => $source,
            'method_supported' => $methodSupported,
        ];

        if ($timeout !== null && is_numeric($timeout)) {
            $meta['timeout_seconds'] = max(1, (int) $timeout);
        }

        if (! $methodSupported) {
            $meta['warning'] = 'unsupported_method';
        }

        return [
            'configured' => $this->filled($url),
            'url' => $this->filled($url) ? $url : null,
            'method' => $method,
            'method_supported' => $methodSupported,
            'meta' => array_filter($meta, fn (mixed $value): bool => $value !== null),
        ];
    }

    private function resolveHealthEndpoint(string $endpoint, ?string $baseUrl): string
    {
        if (preg_match('/^https?:\/\//i', $endpoint) === 1 || $baseUrl === null) {
            return $endpoint;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($endpoint, '/');
    }

    private function commandKeys(mixed $commands): array
    {
        if (! is_array($commands)) {
            return [];
        }

        $keys = array_keys($commands);
        sort($keys);

        return array_map('strval', $keys);
    }

    private function filled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
