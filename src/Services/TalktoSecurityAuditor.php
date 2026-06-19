<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Support\TalktoSecurityAuditSnapshot;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityFinding;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;

class TalktoSecurityAuditor
{
    private const VALID_SIGNATURE_VERSIONS = ['v1', 'v2'];

    private const SEVERITY_ORDER = [
        'info' => 0,
        'warning' => 1,
        'error' => 2,
        'critical' => 3,
    ];

    public function __construct(private readonly TalktoSecurityRedactor $redactor) {}

    public function audit(): TalktoSecurityAuditSnapshot
    {
        $findings = [];

        $this->auditSignatureSettings($findings);
        $this->auditOutgoingTargets($findings);
        $this->auditIncomingSources($findings);
        $this->auditRoutes($findings);
        $this->auditCallbacks($findings);

        $counts = $this->severityCounts($findings);
        $ok = $counts['error'] === 0 && $counts['critical'] === 0;

        return new TalktoSecurityAuditSnapshot($ok, [
            'ok' => $ok,
            'total_findings' => count($findings),
            'severity_counts' => $counts,
        ], $findings, now());
    }

    private function auditSignatureSettings(array &$findings): void
    {
        $requireSignature = (bool) config('talkto.security.require_signature', true);
        $requireTimestamp = (bool) config('talkto.security.require_timestamp', true);
        $tolerance = (int) config('talkto.security.timestamp_tolerance_seconds', 300);
        $signatureVersion = config('talkto.security.signature_version', 'v1');
        $acceptVersions = config('talkto.security.accept_versions', []);
        $replayEnabled = (bool) config('talkto.security.replay_protection.enabled', true);
        $requireNonceForV2 = (bool) config('talkto.security.replay_protection.require_nonce_for_v2', false);
        $acceptedVersions = $this->validAcceptedVersions($acceptVersions);
        $hasInvalidAcceptedVersions = $this->hasInvalidAcceptedVersions($acceptVersions);

        if (! $requireSignature) {
            $findings[] = $this->finding(
                'critical',
                'signatures_disabled',
                'Talkto request signatures are disabled.',
                'Enable talkto.security.require_signature outside local-only testing.'
            );

            if (! $requireTimestamp) {
                $findings[] = $this->finding(
                    'error',
                    'unsigned_timestamp_disabled',
                    'Unsigned requests are allowed without timestamp checks.',
                    'Keep timestamp checks enabled when unsigned requests are temporarily allowed.'
                );
            }
        }

        if ($tolerance <= 0) {
            $findings[] = $this->finding(
                'warning',
                'timestamp_tolerance_disabled',
                'Timestamp tolerance is zero or negative.',
                'Use a positive timestamp tolerance so clock skew can be checked predictably.',
                ['timestamp_tolerance_seconds' => $tolerance]
            );
        } elseif ($tolerance > 600) {
            $findings[] = $this->finding(
                'warning',
                'timestamp_tolerance_high',
                'Timestamp tolerance is higher than 10 minutes.',
                'Prefer 300 seconds or less unless peers require a documented exception.',
                ['timestamp_tolerance_seconds' => $tolerance]
            );
        }

        if (! is_string($signatureVersion) || ! in_array($signatureVersion, self::VALID_SIGNATURE_VERSIONS, true)) {
            $findings[] = $this->finding(
                'error',
                'invalid_signature_version',
                'Configured outgoing signature version is not supported.',
                'Use v1 for backward compatibility or v2 for new peers.',
                ['signature_version' => $signatureVersion]
            );
        } elseif ($signatureVersion === 'v1') {
            $findings[] = $this->finding(
                'info',
                'signature_v1_default',
                'Outgoing signatures use backward-compatible v1.',
                'Use v2 for new peers after both services support the v2 headers.'
            );
        }

        if ($acceptedVersions === [] || ! is_array($acceptVersions) || $hasInvalidAcceptedVersions) {
            $findings[] = $this->finding(
                'error',
                'invalid_accept_versions',
                'Accepted signature versions are empty or include unsupported versions.',
                'Configure talkto.security.accept_versions with v1, v2, or both.',
                ['accept_versions' => $acceptVersions]
            );
        }

        if (in_array('v1', $acceptedVersions, true)) {
            $findings[] = $this->finding(
                'warning',
                'accepts_v1_signatures',
                'Incoming verification accepts v1 signatures.',
                'Keep v1 only while old peers still need it; prefer v2 for new peers.',
                ['accept_versions' => $acceptedVersions]
            );
        } elseif ($acceptedVersions === ['v2']) {
            $findings[] = $this->finding(
                'info',
                'accepts_only_v2_signatures',
                'Incoming verification accepts only v2 signatures.',
                'Keep peer rollout notes current so old v1 senders are not surprised.',
                ['accept_versions' => $acceptedVersions]
            );
        }

        if (! $replayEnabled) {
            $findings[] = $this->finding(
                'error',
                'replay_protection_disabled',
                'Replay protection is disabled.',
                'Enable replay protection unless a host-owned control fully replaces it.'
            );
        }

        if (in_array('v2', $acceptedVersions, true) && ! $requireNonceForV2) {
            $findings[] = $this->finding(
                'warning',
                'v2_nonce_not_required',
                'v2 signatures are accepted without requiring a nonce.',
                'Require v2 nonces after all peers support the v2 nonce header.'
            );
        }
    }

    private function auditOutgoingTargets(array &$findings): void
    {
        foreach ($this->peerConfigs(config('talkto.outgoing', [])) as $name => $target) {
            $url = $target['url'] ?? null;
            $secret = $target['secret'] ?? $target['signing_secret'] ?? null;

            if (! is_string($url) || $url === '') {
                $findings[] = $this->finding(
                    'error',
                    'outgoing_target_missing_url',
                    'Outgoing target is missing a URL.',
                    'Configure a URL for each outgoing target.',
                    ['target' => $name]
                );
            }

            if (! is_string($secret) || $secret === '') {
                $findings[] = $this->finding(
                    'error',
                    'outgoing_target_missing_secret',
                    'Outgoing target is missing a shared secret.',
                    'Configure a non-empty shared secret for each outgoing target.',
                    ['target' => $name]
                );
            } elseif (strlen($secret) < 16) {
                $findings[] = $this->finding(
                    'warning',
                    'outgoing_target_short_secret',
                    'Outgoing target shared secret is short.',
                    'Use a high-entropy shared secret with at least 16 characters.',
                    ['target' => $name, 'secret_length' => strlen($secret)]
                );
            }

            $headers = $target['headers'] ?? [];

            if (is_array($headers) && $headers !== []) {
                $findings[] = $this->finding(
                    'info',
                    'outgoing_target_custom_headers',
                    'Outgoing target defines custom headers.',
                    'Confirm custom headers do not duplicate Talkto signing headers.',
                    ['target' => $name, 'headers' => $this->redactor->redactHeaders($headers)]
                );
            }
        }
    }

    private function auditIncomingSources(array &$findings): void
    {
        $requireSignature = (bool) config('talkto.security.require_signature', true);

        foreach ($this->peerConfigs(config('talkto.incoming', [])) as $name => $source) {
            $secret = $source['secret'] ?? $source['signing_secret'] ?? null;
            $allowedCommandsExists = array_key_exists('allowed_commands', $source);
            $allowedCommands = $source['allowed_commands'] ?? null;

            if ($requireSignature && (! is_string($secret) || $secret === '')) {
                $findings[] = $this->finding(
                    'error',
                    'incoming_source_missing_secret',
                    'Incoming source is missing a shared secret while signatures are required.',
                    'Configure a non-empty shared secret for each signed incoming source.',
                    ['source' => $name]
                );
            } elseif (is_string($secret) && $secret !== '' && strlen($secret) < 16) {
                $findings[] = $this->finding(
                    'warning',
                    'incoming_source_short_secret',
                    'Incoming source shared secret is short.',
                    'Use a high-entropy shared secret with at least 16 characters.',
                    ['source' => $name, 'secret_length' => strlen($secret)]
                );
            }

            if (! $allowedCommandsExists) {
                $findings[] = $this->finding(
                    'warning',
                    'incoming_source_missing_allowed_commands',
                    'Incoming source does not define allowed_commands.',
                    'Define an allowed command list so a peer cannot call unexpected commands.',
                    ['source' => $name]
                );
            } elseif (! is_array($allowedCommands) || $allowedCommands === []) {
                $findings[] = $this->finding(
                    'warning',
                    'incoming_source_empty_allowed_commands',
                    'Incoming source allowed_commands is empty or invalid.',
                    'Add explicit command entries before enabling the source in production.',
                    ['source' => $name]
                );
            }
        }
    }

    private function auditRoutes(array &$findings): void
    {
        if (! (bool) config('talkto.routes.enabled', false)) {
            return;
        }

        $middleware = config('talkto.routes.middleware', []);
        $middleware = is_array($middleware) ? $middleware : [$middleware];

        $hasThrottle = collect($middleware)->contains(function (mixed $item): bool {
            return is_string($item) && (str_contains($item, 'throttle') || str_contains($item, 'rate'));
        });

        if (! $hasThrottle) {
            $findings[] = $this->finding(
                'warning',
                'routes_without_throttle',
                'Talkto routes are enabled without throttle-like middleware.',
                'Add host route throttling or rate limiting for public Talkto endpoints.',
                ['middleware' => array_values($middleware)]
            );
        }
    }

    private function auditCallbacks(array &$findings): void
    {
        if (! (bool) config('talkto.callbacks.enabled', true)) {
            return;
        }

        if (! (bool) config('talkto.routes.enabled', false)) {
            $findings[] = $this->finding(
                'info',
                'callbacks_enabled_routes_disabled',
                'Callbacks are enabled while package routes are disabled.',
                'This is valid when the host application owns its callback route.',
            );
        }

        $command = config('talkto.callbacks.command', 'talkto.result');

        if (! is_string($command) || $command === '') {
            $findings[] = $this->finding(
                'warning',
                'callback_command_invalid',
                'Callback command is empty or invalid.',
                'Configure talkto.callbacks.command with the command peers expect.',
                ['command' => $command]
            );
        }
    }

    private function validAcceptedVersions(mixed $versions): array
    {
        if (! is_array($versions)) {
            return [];
        }

        $valid = array_values(array_unique(array_filter(
            array_map(fn (mixed $version): string => (string) $version, $versions),
            fn (string $version): bool => in_array($version, self::VALID_SIGNATURE_VERSIONS, true)
        )));

        sort($valid);

        return $valid;
    }

    private function hasInvalidAcceptedVersions(mixed $versions): bool
    {
        if (! is_array($versions)) {
            return true;
        }

        foreach ($versions as $version) {
            if (! is_string($version) || ! in_array($version, self::VALID_SIGNATURE_VERSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    private function peerConfigs(mixed $configs): array
    {
        if (! is_array($configs)) {
            return [];
        }

        $peers = [];

        foreach ($configs as $name => $config) {
            if (! is_string($name) || ! is_array($config)) {
                continue;
            }

            if (in_array($name, ['handlers', 'unknown_command_strategy'], true)) {
                continue;
            }

            $peers[$name] = $config;
        }

        return $peers;
    }

    private function severityCounts(array $findings): array
    {
        $counts = [
            'info' => 0,
            'warning' => 0,
            'error' => 0,
            'critical' => 0,
        ];

        foreach ($findings as $finding) {
            $counts[$finding->severity] = ($counts[$finding->severity] ?? 0) + 1;
        }

        return $counts;
    }

    private function finding(string $severity, string $code, string $message, string $recommendation, array $context = []): TalktoSecurityFinding
    {
        return new TalktoSecurityFinding(
            $severity,
            $code,
            $message,
            $recommendation,
            $this->redactor->redactValue($context)
        );
    }

    public static function severityMeetsThreshold(string $severity, string $threshold): bool
    {
        return (self::SEVERITY_ORDER[$severity] ?? -1) >= (self::SEVERITY_ORDER[$threshold] ?? 99);
    }
}
