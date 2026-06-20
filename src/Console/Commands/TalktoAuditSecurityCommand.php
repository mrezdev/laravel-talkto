<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;

class TalktoAuditSecurityCommand extends Command
{
    protected $signature = 'talkto:audit-security {--json : Output JSON without terminal formatting}';

    protected $description = 'Audit Talkto security configuration with PASS, WARN, and FAIL checks.';

    public function handle(): int
    {
        $checks = $this->checks();
        $summary = $this->summary($checks);
        $ok = $summary['failures'] === 0;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $ok,
                'summary' => $summary,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $check) {
            $this->line(sprintf('[%s] %s', $check['status'], $check['message']));
        }

        $this->line(sprintf(
            'Summary: %d PASS, %d WARN, %d FAIL',
            $summary['passes'],
            $summary['warnings'],
            $summary['failures']
        ));

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array{status: string, key: string, message: string, details?: array<string, mixed>}>
     */
    private function checks(): array
    {
        $checks = [];
        $acceptedVersions = $this->acceptedVersions();

        $this->auditSignatureRequirement($checks);
        $this->auditDefaultSignatureVersion($checks);
        $this->auditAcceptedSignatureVersions($checks, $acceptedVersions);
        $this->auditV2Nonce($checks, $acceptedVersions['valid']);
        $this->auditReplayProtection($checks);
        $this->auditIncomingSources($checks);
        $this->auditRoutes($checks);
        $this->auditPanel($checks);

        return $checks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function auditSignatureRequirement(array &$checks): void
    {
        if ((bool) config('talkto.security.require_signature', true)) {
            $checks[] = $this->check('PASS', 'security.require_signature', 'HMAC signatures are required.');

            return;
        }

        $checks[] = $this->check('FAIL', 'security.require_signature', 'HMAC signatures are disabled, which is unsafe.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function auditDefaultSignatureVersion(array &$checks): void
    {
        $version = config('talkto.security.signature_version', 'v2');

        if ($version === 'v2') {
            $checks[] = $this->check('PASS', 'security.signature_version', 'Default outgoing signature version is v2.');

            return;
        }

        $checks[] = $this->check('WARN', 'security.signature_version', 'Outgoing signature version is not v2; v1 is legacy/manual opt-in only.', [
            'signature_version' => is_scalar($version) ? (string) $version : get_debug_type($version),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @param  array{valid: array<int, string>, invalid: bool, raw: mixed}  $acceptedVersions
     */
    private function auditAcceptedSignatureVersions(array &$checks, array $acceptedVersions): void
    {
        if ($acceptedVersions['invalid'] || $acceptedVersions['valid'] === []) {
            $checks[] = $this->check('FAIL', 'security.accept_versions', 'Accepted signature versions are empty or invalid.');

            return;
        }

        if ($acceptedVersions['valid'] === ['v2']) {
            $checks[] = $this->check('PASS', 'security.accept_versions', 'Incoming verification accepts only v2 signatures.');

            return;
        }

        if (in_array('v1', $acceptedVersions['valid'], true)) {
            $checks[] = $this->check('WARN', 'security.accept_versions', 'v1 signatures are still accepted; this should be legacy/manual opt-in only.', [
                'accept_versions' => $acceptedVersions['valid'],
            ]);

            return;
        }

        $checks[] = $this->check('WARN', 'security.accept_versions', 'Accepted signature versions should be reviewed.', [
            'accept_versions' => $acceptedVersions['valid'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @param  array<int, string>  $acceptedVersions
     */
    private function auditV2Nonce(array &$checks, array $acceptedVersions): void
    {
        if (! in_array('v2', $acceptedVersions, true)) {
            $checks[] = $this->check('WARN', 'security.v2_nonce', 'v2 signatures are not accepted, so v2 nonce enforcement is not active.');

            return;
        }

        if ((bool) config('talkto.security.replay_protection.require_nonce_for_v2', false)) {
            $checks[] = $this->check('PASS', 'security.v2_nonce', 'v2 nonce enforcement is enabled.');

            return;
        }

        $checks[] = $this->check('WARN', 'security.v2_nonce', 'v2 signatures are accepted without requiring a nonce.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function auditReplayProtection(array &$checks): void
    {
        if ((bool) config('talkto.security.replay_protection.enabled', true)) {
            $checks[] = $this->check('PASS', 'security.replay_protection', 'Replay protection is enabled.');

            return;
        }

        $checks[] = $this->check('FAIL', 'security.replay_protection', 'Replay protection is disabled.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function auditIncomingSources(array &$checks): void
    {
        $sources = $this->peerConfigs(config('talkto.incoming', []));

        if ($sources === []) {
            $checks[] = $this->check('PASS', 'incoming.sources', 'No incoming sources are configured.');

            return;
        }

        foreach ($sources as $source => $config) {
            $secret = $config['secret'] ?? $config['signing_secret'] ?? null;

            if (is_string($secret) && $secret !== '') {
                $checks[] = $this->check('PASS', "incoming.{$source}.secret", "Incoming source `{$source}` has a shared secret.");
            } else {
                $checks[] = $this->check('FAIL', "incoming.{$source}.secret", "Incoming source `{$source}` is missing a shared secret.");
            }

            if (($config['allow_all_commands'] ?? false) === true) {
                $checks[] = $this->check('WARN', "incoming.{$source}.allowed_commands", "Incoming source `{$source}` explicitly allows all commands.");

                continue;
            }

            if (! array_key_exists('allowed_commands', $config)) {
                $checks[] = $this->check('FAIL', "incoming.{$source}.allowed_commands", "Incoming source `{$source}` has no allowed_commands and does not explicitly allow all commands.");

                continue;
            }

            if (! is_array($config['allowed_commands']) || $config['allowed_commands'] === []) {
                $checks[] = $this->check('FAIL', "incoming.{$source}.allowed_commands", "Incoming source `{$source}` has empty or invalid allowed_commands.");

                continue;
            }

            $checks[] = $this->check('PASS', "incoming.{$source}.allowed_commands", "Incoming source `{$source}` has an explicit command allowlist.");
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function auditRoutes(array &$checks): void
    {
        if (! (bool) config('talkto.routes.enabled', false)) {
            $checks[] = $this->check('PASS', 'routes.exposure', 'Talkto package routes are disabled.');

            return;
        }

        $middleware = $this->middlewareList(config('talkto.routes.middleware', []));
        $hasThrottle = $this->hasMiddlewareLike($middleware, ['throttle', 'rate']);

        if ($hasThrottle) {
            $checks[] = $this->check('PASS', 'routes.throttle', 'Talkto routes include throttle or rate-limit middleware.', [
                'middleware' => $middleware,
            ]);

            return;
        }

        $checks[] = $this->check('WARN', 'routes.throttle', 'Talkto routes are enabled without throttle or rate-limit middleware.', [
            'middleware' => $middleware,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function auditPanel(array &$checks): void
    {
        if (! (bool) config('talkto.panel.enabled', false)) {
            $checks[] = $this->check('PASS', 'panel.exposure', 'Talkto panel is disabled.');

            return;
        }

        $middleware = $this->middlewareList(config('talkto.panel.route.middleware', []));
        $hasAuth = $this->hasMiddlewareLike($middleware, ['auth', 'can', 'signed']);
        $production = app()->environment('production');

        if ($hasAuth) {
            $checks[] = $this->check('PASS', 'panel.exposure', 'Talkto panel is enabled with auth-like middleware.', [
                'middleware' => $middleware,
            ]);
        } else {
            $checks[] = $this->check($production ? 'FAIL' : 'WARN', 'panel.exposure', 'Talkto panel is enabled without auth-like middleware.', [
                'middleware' => $middleware,
            ]);
        }

        if (! (bool) config('talkto.panel.authorization.enabled', true)) {
            $checks[] = $this->check($production ? 'FAIL' : 'WARN', 'panel.authorization', 'Talkto panel authorization is disabled.');
        }

        if ((bool) config('talkto.panel.messages.show_payload', false) || (bool) config('talkto.panel.messages.show_response', false)) {
            $checks[] = $this->check('WARN', 'panel.message_visibility', 'Talkto panel payload or response visibility is enabled.');
        }

        if ((bool) config('talkto.panel.health.active_checks.enabled', false) || (bool) config('talkto.panel.actions.active_health_checks_enabled', false)) {
            $checks[] = $this->check($production ? 'WARN' : 'PASS', 'panel.active_health_checks', 'Talkto panel active health checks setting was reviewed.');
        }
    }

    /**
     * @return array{valid: array<int, string>, invalid: bool, raw: mixed}
     */
    private function acceptedVersions(): array
    {
        $versions = config('talkto.security.accept_versions', ['v2']);

        if (! is_array($versions)) {
            return ['valid' => [], 'invalid' => true, 'raw' => $versions];
        }

        $valid = [];
        $invalid = false;

        foreach ($versions as $version) {
            if (! is_string($version) || ! in_array($version, ['v1', 'v2'], true)) {
                $invalid = true;

                continue;
            }

            $valid[] = $version;
        }

        $valid = array_values(array_unique($valid));
        sort($valid);

        return ['valid' => $valid, 'invalid' => $invalid, 'raw' => $versions];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
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

    /**
     * @return array<int, string>
     */
    private function middlewareList(mixed $middleware): array
    {
        if (is_string($middleware)) {
            $middleware = array_map('trim', explode(',', $middleware));
        }

        if (! is_array($middleware)) {
            return [];
        }

        return array_values(array_filter($middleware, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /**
     * @param  array<int, string>  $middleware
     * @param  array<int, string>  $needles
     */
    private function hasMiddlewareLike(array $middleware, array $needles): bool
    {
        foreach ($middleware as $item) {
            foreach ($needles as $needle) {
                if (str_contains($item, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array{passes: int, warnings: int, failures: int}
     */
    private function summary(array $checks): array
    {
        $summary = ['passes' => 0, 'warnings' => 0, 'failures' => 0];

        foreach ($checks as $check) {
            if ($check['status'] === 'PASS') {
                $summary['passes']++;
            } elseif ($check['status'] === 'WARN') {
                $summary['warnings']++;
            } elseif ($check['status'] === 'FAIL') {
                $summary['failures']++;
            }
        }

        return $summary;
    }

    /**
     * @return array{status: string, key: string, message: string, details?: array<string, mixed>}
     */
    private function check(string $status, string $key, string $message, array $details = []): array
    {
        $check = [
            'status' => $status,
            'key' => $key,
            'message' => $message,
        ];

        if ($details !== []) {
            $check['details'] = $details;
        }

        return $check;
    }
}
