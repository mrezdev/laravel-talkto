<?php

namespace Mrezdev\LaravelTalkto\Support\Panel;

/**
 * @internal Optional panel implementation detail.
 */
final class TalktoPanelActiveHealthResult
{
    public function __construct(
        public readonly string $direction,
        public readonly string $service,
        public readonly bool $enabled,
        public readonly bool $configured,
        public readonly string $status,
        public readonly ?int $httpStatus = null,
        public readonly ?int $durationMs = null,
        public readonly ?string $checkedAt = null,
        public readonly array $warnings = [],
        public readonly array $meta = [],
    ) {}

    public static function disabled(TalktoPanelConnection $connection): self
    {
        return new self(
            direction: $connection->direction,
            service: $connection->service,
            enabled: false,
            configured: $connection->activeHealthConfigured,
            status: 'unknown',
            warnings: ['active_checks_disabled'],
        );
    }

    public static function notConfigured(TalktoPanelConnection $connection, array $warnings = []): self
    {
        return new self(
            direction: $connection->direction,
            service: $connection->service,
            enabled: true,
            configured: false,
            status: 'not_configured',
            warnings: array_values(array_unique($warnings)),
        );
    }

    public static function notApplicable(TalktoPanelConnection $connection, array $warnings = []): self
    {
        return new self(
            direction: $connection->direction,
            service: $connection->service,
            enabled: true,
            configured: false,
            status: 'not_applicable',
            warnings: array_values(array_unique($warnings)),
        );
    }

    public static function success(TalktoPanelConnection $connection, int $httpStatus, int $durationMs): self
    {
        return new self(
            direction: $connection->direction,
            service: $connection->service,
            enabled: true,
            configured: true,
            status: 'healthy',
            httpStatus: $httpStatus,
            durationMs: $durationMs,
            checkedAt: now()->toIso8601String(),
            meta: [
                'method' => $connection->activeHealthMethod,
            ],
        );
    }

    public static function failure(TalktoPanelConnection $connection, ?int $httpStatus, int $durationMs, string $reason, array $warnings = []): self
    {
        $status = match ($reason) {
            'unsupported_method' => 'misconfigured',
            'exception' => 'unknown',
            default => 'failing',
        };

        return new self(
            direction: $connection->direction,
            service: $connection->service,
            enabled: true,
            configured: $connection->activeHealthConfigured,
            status: $status,
            httpStatus: $httpStatus,
            durationMs: $durationMs,
            checkedAt: now()->toIso8601String(),
            warnings: array_values(array_unique($warnings)),
            meta: [
                'method' => $connection->activeHealthMethod,
                'reason' => $reason,
            ],
        );
    }

    public function toArray(): array
    {
        return [
            'direction' => $this->direction,
            'service' => $this->service,
            'enabled' => $this->enabled,
            'configured' => $this->configured,
            'status' => $this->status,
            'http_status' => $this->httpStatus,
            'duration_ms' => $this->durationMs,
            'checked_at' => $this->checkedAt,
            'warnings' => $this->warnings,
            'meta' => $this->meta,
        ];
    }
}
