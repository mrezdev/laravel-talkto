<?php

namespace Mrezdev\LaravelTalkto\Services\Panel;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelActiveHealthResult;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;
use Throwable;

class TalktoPanelActiveHealthChecker
{
    public function __construct(
        private readonly TalktoPanelConnectionRegistry $registry,
        private readonly TalktoSecurityRedactor $redactor,
    ) {
    }

    public function check(TalktoPanelConnection $connection, bool $force = false): TalktoPanelActiveHealthResult
    {
        if (! $this->enabled()) {
            return TalktoPanelActiveHealthResult::disabled($connection);
        }

        if ($connection->direction === 'incoming' && ! $connection->activeHealthConfigured) {
            return TalktoPanelActiveHealthResult::notApplicable($connection, ['incoming_active_check_not_applicable']);
        }

        if (! $connection->activeHealthConfigured || $connection->activeHealthUrl === null || $connection->activeHealthUrl === '') {
            return TalktoPanelActiveHealthResult::notConfigured($connection, ['active_health_url_missing']);
        }

        $method = strtoupper((string) ($connection->activeHealthMethod ?: 'GET'));

        if (! in_array($method, $this->allowedMethods(), true)) {
            return TalktoPanelActiveHealthResult::failure($connection, null, 0, 'unsupported_method', ['unsupported_method']);
        }

        $cacheSeconds = $this->cacheSeconds();

        if (! $force && $cacheSeconds > 0) {
            return Cache::remember(
                $this->cacheKey($connection),
                $cacheSeconds,
                fn (): TalktoPanelActiveHealthResult => $this->performCheck($connection, $method)
            );
        }

        return $this->performCheck($connection, $method);
    }

    public function checkAll(bool $force = false): Collection
    {
        return $this->registry->all()
            ->map(fn (TalktoPanelConnection $connection): TalktoPanelActiveHealthResult => $this->check($connection, $force))
            ->values();
    }

    public function enabled(): bool
    {
        return (bool) config('talkto.panel.health.active_checks.enabled', false)
            || (bool) config('talkto.panel.actions.active_health_checks_enabled', false);
    }

    private function performCheck(TalktoPanelConnection $connection, string $method): TalktoPanelActiveHealthResult
    {
        $started = microtime(true);

        try {
            $response = Http::timeout($this->timeoutSeconds($connection))
                ->send($method, $connection->activeHealthUrl);
            $durationMs = $this->durationMs($started);
            $status = $response->status();

            if ($status >= 200 && $status < 400) {
                return TalktoPanelActiveHealthResult::success($connection, $status, $durationMs);
            }

            return TalktoPanelActiveHealthResult::failure($connection, $status, $durationMs, 'http_status', ["http_status={$status}"]);
        } catch (Throwable $throwable) {
            return TalktoPanelActiveHealthResult::failure($connection, null, $this->durationMs($started), 'exception', [
                'exception='.$this->safeExceptionMessage($throwable),
            ]);
        }
    }

    private function timeoutSeconds(TalktoPanelConnection $connection): int
    {
        $timeout = $connection->activeHealthMeta['timeout_seconds']
            ?? config('talkto.panel.health.active_checks.timeout_seconds', 3);

        return max(1, (int) $timeout);
    }

    private function cacheSeconds(): int
    {
        return max(0, (int) config('talkto.panel.health.active_checks.cache_seconds', 30));
    }

    private function allowedMethods(): array
    {
        $methods = config('talkto.panel.health.active_checks.allowed_methods', ['GET', 'HEAD']);

        if (! is_array($methods) || $methods === []) {
            return ['GET', 'HEAD'];
        }

        return array_values(array_unique(array_map(
            fn (mixed $method): string => strtoupper((string) $method),
            $methods
        )));
    }

    private function cacheKey(TalktoPanelConnection $connection): string
    {
        return 'talkto.panel.active_health.'
            .$connection->direction.'.'
            .$connection->service.'.'
            .sha1((string) $connection->activeHealthMethod.'|'.(string) $connection->activeHealthUrl);
    }

    private function durationMs(float $started): int
    {
        return max(0, (int) round((microtime(true) - $started) * 1000));
    }

    private function safeExceptionMessage(Throwable $throwable): string
    {
        $message = $this->redactor->redactText($throwable->getMessage()) ?? 'Health check failed.';
        $message = trim($message);

        if ($message === '') {
            return 'Health check failed.';
        }

        return mb_substr($message, 0, 300);
    }
}
