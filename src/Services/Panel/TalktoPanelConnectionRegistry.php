<?php

namespace Mrezdev\LaravelTalkto\Services\Panel;

use Illuminate\Support\Collection;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelConnection;

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
        );
    }

    private function commandKeys(mixed $commands): array
    {
        if (! is_array($commands)) {
            return [];
        }

        $keys = array_keys($commands);
        sort($keys);

        return array_values(array_map('strval', $keys));
    }

    private function filled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
