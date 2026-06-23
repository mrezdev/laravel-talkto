<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoOutgoingTarget;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityAuditSnapshot;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityFinding;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;

/**
 * Read-only public service for security posture snapshots.
 */
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
        $this->auditPanel($findings);

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
        $signatureVersion = config('talkto.security.signature_version', 'v2');
        $acceptVersions = config('talkto.security.accept_versions', ['v2']);
        $replayEnabled = (bool) config('talkto.security.replay_protection.enabled', true);
        $requireNonceForV2 = (bool) config('talkto.security.replay_protection.require_nonce_for_v2', true);
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
                'Use v2 in production. v1 is legacy/manual opt-in only.',
                ['signature_version' => $signatureVersion]
            );
        } elseif ($signatureVersion === 'v1') {
            $findings[] = $this->finding(
                'warning',
                'outgoing_signature_v1',
                'Outgoing signatures use legacy v1.',
                'Use v2-only signatures for production. v1 should be a manual legacy/interoperability opt-in only.'
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
                'Accept v1 only as an explicit legacy/manual opt-in. New projects should use v2-only signatures with required nonces.',
                ['accept_versions' => $acceptedVersions]
            );
        }

        if (in_array('v1', $acceptedVersions, true) && in_array('v2', $acceptedVersions, true)) {
            $findings[] = $this->finding(
                'warning',
                'accepts_v1_v2_signatures',
                'Incoming verification accepts both v1 and v2 signatures.',
                'Accept both versions only for rare interoperability, debugging, or migration windows; prefer v2-only in production.',
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
                'Require v2 nonces for production so signed requests cannot be replayed.'
            );
        }
    }

    private function auditOutgoingTargets(array &$findings): void
    {
        foreach ($this->peerConfigs(config('talkto.outgoing', [])) as $name => $targetConfig) {
            $target = new TalktoOutgoingTarget($name, $targetConfig);

            try {
                $target->endpointUrl();
            } catch (InvalidTalktoOutgoingTarget $exception) {
                $missingUrl = str_contains($exception->getMessage(), 'URL is not configured');

                $findings[] = $this->finding(
                    'error',
                    $missingUrl ? 'outgoing_target_missing_url' : 'outgoing_target_invalid_url',
                    $missingUrl ? 'Outgoing target is missing a receive URL.' : 'Outgoing target receive URL is invalid.',
                    'Configure receive_url or base_url plus receive_endpoint for each outgoing target.',
                    ['target' => $name, 'error' => $exception->getMessage()]
                );
            }

            try {
                $target->callbackEndpointUrl();
            } catch (InvalidTalktoOutgoingTarget $exception) {
                $findings[] = $this->finding(
                    'warning',
                    'outgoing_target_callback_url_unresolved',
                    'Outgoing target callback URL could not be resolved.',
                    'Configure callback_url or base_url plus callback_endpoint when this target must receive durable callbacks.',
                    ['target' => $name, 'error' => $exception->getMessage()]
                );
            }

            $secret = $target->secret();

            if ($secret === null) {
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

            $headers = $target->headers();

            if ($headers !== []) {
                $findings[] = $this->finding(
                    'info',
                    'outgoing_target_custom_headers',
                    'Outgoing target defines custom headers.',
                    'Confirm custom headers do not duplicate Talkto signing headers.',
                    ['target' => $name, 'headers' => $this->redactor->redactHeaders($headers)]
                );
            }

            $this->auditOutgoingTargetSsl($findings, $name, $target);
        }
    }

    private function auditOutgoingTargetSsl(array &$findings, string $name, TalktoOutgoingTarget $target): void
    {
        $verifySsl = $target->verifySsl();
        $caBundle = $target->caBundle();
        $caBundleContext = $this->caBundleContext($caBundle);

        if (! $verifySsl) {
            $findings[] = $this->finding(
                'warning',
                'outgoing_target_ssl_verification_disabled',
                'Outgoing target has SSL/TLS certificate verification disabled.',
                'Keep SSL/TLS certificate verification enabled in production; disable it only for documented local, staging, or internal test peers.',
                array_merge([
                    'target' => $name,
                    'verify_ssl' => false,
                    'ca_bundle_configured' => $caBundle !== null,
                ], $caBundleContext)
            );

            if ($caBundle !== null) {
                $findings[] = $this->finding(
                    'warning',
                    'outgoing_target_ca_bundle_ignored',
                    'Outgoing target configures a CA bundle, but TLS certificate verification is disabled.',
                    'Enable verify_ssl for this target, or remove the CA bundle if the disabled verification mode is intentional and temporary.',
                    array_merge([
                        'target' => $name,
                        'verify_ssl' => false,
                    ], $caBundleContext)
                );
            }

            return;
        }

        if ($caBundle === null) {
            return;
        }

        if (! file_exists($caBundle)) {
            $findings[] = $this->finding(
                'warning',
                'outgoing_target_ca_bundle_missing',
                'Outgoing target configures a CA bundle that does not exist.',
                'Point ca_bundle to a readable CA bundle file, or remove it to use the system default trust store.',
                array_merge([
                    'target' => $name,
                    'verify_ssl' => true,
                ], $caBundleContext)
            );

            return;
        }

        if (! is_file($caBundle) || ! is_readable($caBundle)) {
            $findings[] = $this->finding(
                'warning',
                'outgoing_target_ca_bundle_unreadable',
                'Outgoing target configures a CA bundle that is not readable.',
                'Ensure ca_bundle points to a readable CA bundle file, or remove it to use the system default trust store.',
                array_merge([
                    'target' => $name,
                    'verify_ssl' => true,
                ], $caBundleContext)
            );
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

            if (($source['allow_all_commands'] ?? false) === true) {
                $findings[] = $this->finding(
                    'warning',
                    'incoming_source_all_commands_allowed',
                    'Incoming source explicitly allows all commands.',
                    'Use explicit allowed_commands entries for production sources.',
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

    private function auditPanel(array &$findings): void
    {
        if (! (bool) config('talkto.panel.enabled', false)) {
            return;
        }

        $middleware = config('talkto.panel.route.middleware', []);
        $middleware = is_array($middleware) ? $middleware : [$middleware];
        $production = app()->environment('production');

        $hasAuth = collect($middleware)->contains(function (mixed $item): bool {
            return is_string($item) && (str_contains($item, 'auth') || str_contains($item, 'can') || str_contains($item, 'signed'));
        });

        if (! $hasAuth) {
            $findings[] = $this->finding(
                $production ? 'error' : 'warning',
                'panel_without_auth_middleware',
                'Talkto panel is enabled without auth-like middleware.',
                'Keep the panel behind trusted host authentication or authorization middleware.',
                ['middleware' => array_values($middleware)]
            );
        }

        if (! (bool) config('talkto.panel.authorization.enabled', true)) {
            $findings[] = $this->finding(
                $production ? 'error' : 'warning',
                'panel_authorization_disabled',
                'Talkto panel authorization is disabled.',
                'Keep panel authorization enabled unless host middleware fully replaces it.'
            );
        }

        if ((bool) config('talkto.panel.messages.show_payload', false) || (bool) config('talkto.panel.messages.show_response', false)) {
            $findings[] = $this->finding(
                'warning',
                'panel_payload_response_visible',
                'Talkto panel payload or response visibility is enabled.',
                'Keep payload and response body visibility disabled unless operators are explicitly authorized to inspect sensitive host data.'
            );
        }

        if ((bool) config('talkto.panel.health.active_checks.enabled', false) || (bool) config('talkto.panel.actions.active_health_checks_enabled', false)) {
            $findings[] = $this->finding(
                $production ? 'warning' : 'info',
                'panel_active_health_checks_enabled',
                'Talkto panel active health checks are enabled.',
                'Use active health checks only when outbound checks and target URLs are intentionally approved.'
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

    private function caBundleContext(?string $caBundle): array
    {
        if ($caBundle === null) {
            return [];
        }

        return [
            'ca_bundle_label' => $this->pathLabel($caBundle),
            'ca_bundle_exists' => file_exists($caBundle),
            'ca_bundle_readable' => is_file($caBundle) && is_readable($caBundle),
        ];
    }

    private function pathLabel(string $path): string
    {
        return basename(str_replace('\\', '/', $path));
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
