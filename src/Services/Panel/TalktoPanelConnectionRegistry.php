<?php

namespace Mrezdev\LaravelTalkto\Services\Panel;

use Illuminate\Support\Collection;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoOutgoingTarget;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingTarget;
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

        $secretConfigured = $this->filled($settings['secret'] ?? $settings['signing_secret'] ?? null);
        $endpoint = $this->filled($settings['endpoint'] ?? null) ? (string) $settings['endpoint'] : null;
        $commands = $this->commandKeys($settings['allowed_commands'] ?? $settings['commands'] ?? []);
        $activeHealth = $this->activeHealth($settings);
        $ssl = [
            'ssl_verify_enabled' => null,
            'ssl_verify_source' => null,
            'ca_bundle_configured' => null,
            'ca_bundle_status' => null,
            'ca_bundle_source' => null,
            'ca_bundle_label' => null,
            'ca_bundle_exists' => null,
            'ca_bundle_readable' => null,
        ];

        if (! $secretConfigured) {
            $warnings[] = 'missing_secret';
        }

        if ($direction === 'outgoing') {
            $target = new TalktoOutgoingTarget($service, $settings);
            $ssl = $this->ssl($target, $settings);
            $endpoint = $target->endpoint();
            $meta['receive_endpoint'] = $endpoint;
            $meta['callback_endpoint'] = $target->callbackEndpoint();
            $meta['transport'] = $target->transport();

            if ($target->timeout() !== null) {
                $meta['timeout_seconds'] = $target->timeout();
            }

            try {
                $meta['receive_url'] = $target->endpointUrl();
                $urlConfigured = true;
            } catch (InvalidTalktoOutgoingTarget $exception) {
                $urlConfigured = false;
                $meta['receive_url_error'] = $exception->getMessage();
            }

            try {
                $meta['callback_url'] = $target->callbackEndpointUrl();
            } catch (InvalidTalktoOutgoingTarget $exception) {
                $meta['callback_url_error'] = $exception->getMessage();
            }

            $configured = $urlConfigured && $secretConfigured;

            if (! $urlConfigured) {
                $warnings[] = 'missing_url';
            }

            array_push($warnings, ...$this->sslWarnings($ssl));
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
            sslVerifyEnabled: $ssl['ssl_verify_enabled'],
            sslVerifySource: $ssl['ssl_verify_source'],
            caBundleConfigured: $ssl['ca_bundle_configured'],
            caBundleStatus: $ssl['ca_bundle_status'],
            caBundleSource: $ssl['ca_bundle_source'],
            caBundleLabel: $ssl['ca_bundle_label'],
            caBundleExists: $ssl['ca_bundle_exists'],
            caBundleReadable: $ssl['ca_bundle_readable'],
        );
    }

    private function ssl(TalktoOutgoingTarget $target, array $settings): array
    {
        $verifyEnabled = $target->verifySsl();
        $caBundle = $target->caBundle();

        return [
            'ssl_verify_enabled' => $verifyEnabled,
            'ssl_verify_source' => $this->sslVerifySource($settings),
            'ca_bundle_configured' => $caBundle !== null,
            'ca_bundle_status' => $caBundle === null ? 'system_default' : ($verifyEnabled ? 'custom' : 'ignored'),
            'ca_bundle_source' => $this->caBundleSource($settings),
            'ca_bundle_label' => $caBundle === null ? null : $this->pathLabel($caBundle),
            'ca_bundle_exists' => $caBundle === null ? null : file_exists($caBundle),
            'ca_bundle_readable' => $caBundle === null ? null : is_file($caBundle) && is_readable($caBundle),
        ];
    }

    private function sslWarnings(array $ssl): array
    {
        $warnings = [];

        if (($ssl['ssl_verify_enabled'] ?? null) === false) {
            $warnings[] = 'ssl_verification_disabled';
        }

        if (($ssl['ca_bundle_status'] ?? null) === 'ignored') {
            $warnings[] = 'ca_bundle_ignored';
        } elseif (($ssl['ca_bundle_status'] ?? null) === 'custom') {
            if (($ssl['ca_bundle_exists'] ?? null) === false) {
                $warnings[] = 'ca_bundle_missing';
            } elseif (($ssl['ca_bundle_readable'] ?? null) === false) {
                $warnings[] = 'ca_bundle_unreadable';
            }
        }

        return $warnings;
    }

    private function sslVerifySource(array $settings): string
    {
        if (array_key_exists('verify_ssl', $settings) && $this->booleanOrNull($settings['verify_ssl']) !== null) {
            return 'target';
        }

        return $this->booleanOrNull(config('talkto.http.verify_ssl', true)) === false ? 'global' : 'default';
    }

    private function caBundleSource(array $settings): string
    {
        if ($this->filled($settings['ca_bundle'] ?? null)) {
            return 'target';
        }

        if ($this->filled(config('talkto.http.ca_bundle'))) {
            return 'global';
        }

        return 'default';
    }

    private function activeHealth(array $settings): array
    {
        $health = $settings['health'] ?? [];
        $health = is_array($health) ? $health : [];
        $baseUrl = $this->baseUrl($settings);
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

    private function booleanOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                0 => false,
                1 => true,
                default => null,
            };
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));

            return match ($value) {
                '0', 'false' => false,
                '1', 'true' => true,
                default => null,
            };
        }

        return null;
    }

    private function pathLabel(string $path): string
    {
        return basename(str_replace('\\', '/', $path));
    }

    private function baseUrl(array $settings): ?string
    {
        foreach (['base_url', 'url'] as $key) {
            $url = $settings[$key] ?? null;

            if ($this->filled($url)) {
                return trim((string) $url);
            }
        }

        $receiveUrl = $settings['receive_url'] ?? null;

        if (! $this->filled($receiveUrl)) {
            return null;
        }

        $receiveEndpoint = $settings['receive_endpoint'] ?? $settings['endpoint'] ?? '/api/talkto/receive';

        if (! $this->filled($receiveEndpoint)) {
            return null;
        }

        $normalizedReceiveUrl = rtrim(trim((string) $receiveUrl), '/');
        $normalizedReceiveEndpoint = '/'.trim((string) $receiveEndpoint, '/');

        if (! str_ends_with($normalizedReceiveUrl, $normalizedReceiveEndpoint)) {
            return null;
        }

        $baseUrl = substr($normalizedReceiveUrl, 0, -strlen($normalizedReceiveEndpoint));

        return $this->filled($baseUrl) ? $baseUrl : null;
    }
}
