<?php

namespace Mrezdev\LaravelTalkto\Services;

use Closure;
use Composer\InstalledVersions;
use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoReceiveController;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoResultCallbackController;
use Mrezdev\LaravelTalkto\Support\TalktoModelConnection;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;
use Throwable;

/**
 * @internal Read-only local readiness inspector, resolved only by the Doctor command.
 */
class TalktoDoctor
{
    private array $checks = [];

    public function __construct(
        private readonly TalktoModelResolver $models,
        private readonly TalktoEnvelopeFieldValidator $validator,
        private readonly TalktoOutgoingTargetRegistryContract $targets,
        private readonly TalktoIncomingHandlerRegistryContract $handlers,
        private readonly TalktoSecurityRedactor $redactor
    ) {}

    public function inspect(): array
    {
        $this->checks = [];
        $this->environment();
        $this->storage();
        $this->security();
        $this->peers();
        $this->runtime();
        $this->optionalFeatures();

        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
        foreach ($this->checks as $check) {
            $summary[$check['status']]++;
        }

        return ['status' => $summary['fail'] === 0 ? 'ready' : 'not_ready', 'summary' => $summary, 'checks' => $this->checks];
    }

    private function environment(): void
    {
        $requirements = [];
        try {
            $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            $requirements = $manifest['require'] ?? [];
        } catch (Throwable) {
            // A missing manifest is reported through the version checks below.
        }

        foreach (['php' => ['PHP', PHP_VERSION], 'illuminate/support' => ['Laravel', app()->version()]] as $package => [$label, $version]) {
            $requirement = $requirements[$package] ?? null;
            $supported = is_string($requirement) && $this->supportsVersion($version, $requirement);
            $this->add('environment', $package === 'php' ? 'php_version' : 'laravel_version', $supported ? 'pass' : 'fail', $label, $version,
                $supported ? null : 'Version does not satisfy the package requirement, or its requirement cannot be read.');
        }

        $version = null;
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('mrezdev/laravel-talkto')
            && InstalledVersions::getRootPackage()['name'] !== 'mrezdev/laravel-talkto') {
            $version = InstalledVersions::getPrettyVersion('mrezdev/laravel-talkto');
        }
        $this->add('environment', 'talkto_version', $version === null ? 'info' : 'pass', 'Talkto version', $version ?? 'dev/source checkout');

        $service = config('talkto.service');
        $valid = $this->verify('environment', 'service_name', 'Service name', function () use ($service): void {
            $this->identifier('source_service', $service);
        }, is_string($service) ? $service : 'missing', 'Configure a non-empty service name satisfying Talkto identifier rules.');
        if (! $valid) {
            $this->checks[array_key_last($this->checks)]['value'] = 'missing or invalid';
        }
    }

    private function supportsVersion(string $version, string $requirement): bool
    {
        // Read the caret ranges used by the package manifest, without a Semver dependency.
        foreach (explode('|', $requirement) as $range) {
            if (preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?$/', trim($range), $parts) === 1
                && version_compare(ltrim($version, 'v'), substr(trim($range), 1), '>=')
                && version_compare(ltrim($version, 'v'), ((int) $parts[1] + 1).'.0.0', '<')) {
                return true;
            }
        }

        return false;
    }

    private function storage(): void
    {
        $classes = [
            'messages' => $this->models->message(), 'attempts' => $this->models->attempt(),
            'events' => $this->models->event(), 'dead_letters' => $this->models->deadLetter(), 'nonces' => $this->models->nonce(),
        ];
        $this->verify('storage', 'database_connection', 'Database connection', function () use ($classes): void {
            (new $classes['messages'])->getConnection()->select('select 1');
        }, (string) ((new $classes['messages'])->getConnectionName() ?? config('database.default')), 'Connection is unavailable; credentials and exception details are omitted.');

        foreach ($classes as $family => $class) {
            $this->verify('storage', $family.'_table', ucwords(str_replace('_', ' ', $family)).' table', function () use ($class): void {
                $model = new $class;
                if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
                    throw new InvalidArgumentException;
                }
            }, (new $class)->getTable(), 'Required table is missing or cannot be inspected.');
        }

        $this->verify('storage', 'model_connections', 'Storage model connections', function () use ($classes): void {
            TalktoModelConnection::assertSameConnection($classes['messages'], $classes['attempts'], $classes['events'], $classes['dead_letters']);
        }, 'consistent', 'Messages, attempts, events and dead letters must share a connection for atomic writes.');
    }

    private function security(): void
    {
        $version = config('talkto.security.signature_version', 'v2');
        $this->add('security', 'signature_version', $version === 'v2' ? 'pass' : ($version === 'v1' ? 'warn' : 'fail'), 'Signature version',
            in_array($version, ['v1', 'v2'], true) ? $version : 'invalid', $version === 'v1' ? 'Legacy/manual opt-in.' : null);

        $accepted = config('talkto.security.accept_versions', ['v2']);
        $valid = is_array($accepted) && $accepted !== []
            && array_filter($accepted, fn ($item): bool => ! is_string($item) || ! in_array($item, ['v1', 'v2'], true)) === [];
        $this->add('security', 'accepted_signatures', ! $valid ? 'fail' : (in_array('v1', $accepted, true) ? 'warn' : 'pass'), 'Accepted signatures',
            $valid ? implode(', ', $accepted) : 'invalid', $valid && in_array('v1', $accepted, true) ? 'Legacy v1 is accepted.' : null);

        foreach (['require_signature' => 'Required signatures', 'replay_protection.enabled' => 'Replay protection'] as $key => $label) {
            $enabled = (bool) config('talkto.security.'.$key, true);
            $this->add('security', $key, $enabled ? 'pass' : 'fail', $label, $enabled ? 'enabled' : 'disabled');
        }
        $nonce = (bool) config('talkto.security.replay_protection.require_nonce_for_v2', true);
        $acceptsV2 = is_array($accepted) && in_array('v2', $accepted, true);
        $this->add('security', 'v2_nonce_protection', ! $acceptsV2 ? 'info' : ($nonce ? 'pass' : 'warn'), 'V2 nonce protection',
            ! $acceptsV2 ? 'v2 not accepted' : ($nonce ? 'enabled' : 'optional'), 'Nonce table readiness is checked under Storage.');

        $target = new TalktoOutgoingTarget('doctor', []);
        $this->tls('security', 'tls_verification', 'TLS verification', $target);
        $this->add('security', 'detailed_security_audit', 'info', 'Detailed security audit', 'php artisan talkto:security-audit');
    }

    private function peers(): void
    {
        $outgoing = $this->targets->all();
        if (! is_array(config('talkto.outgoing', []))) {
            $this->add('peers', 'outgoing_config', 'fail', 'Outgoing configuration', 'invalid', 'Expected a peer configuration array.');
        }
        foreach ($outgoing as $name => $configuration) {
            $name = (string) $name;
            $safeName = $this->safePeerName($name);
            $target = null;
            $valid = $this->verify('peers', 'outgoing.'.$safeName, 'Outgoing: '.$safeName, function () use ($name, &$target): void {
                $this->identifier('target_service', $name);
                $target = $this->targets->get($name);
                $target->endpointUrl();
                if ($target->secret() === null) {
                    throw new InvalidArgumentException;
                }
            }, 'configured', 'Check target identifier, receive URL, secret and custom signing headers.');
            if ($valid && $target instanceof TalktoOutgoingTarget) {
                $this->tls('peers', 'outgoing.'.$safeName.'.tls', 'Outgoing TLS: '.$safeName, $target);
            }
        }

        $incoming = config('talkto.incoming', []);
        $sources = is_array($incoming) ? array_diff_key($incoming, array_flip(['handlers', 'unknown_command_strategy'])) : [];
        if (! is_array($incoming)) {
            $this->add('peers', 'incoming_config', 'fail', 'Incoming configuration', 'invalid', 'Expected a source configuration array.');
        }
        $handlers = $this->handlers->all();
        foreach ($handlers as $command => $handler) {
            $this->verify('peers', 'handler.'.$this->safePeerName((string) $command), 'Incoming handler: '.$this->safePeerName((string) $command), function () use ($command, $handler): void {
                $this->identifier('command', $command);
                $this->handler($handler);
            }, 'configured', 'Handler class is missing or does not implement TalktoIncomingCommandHandler.');
        }

        $commandCount = 0;
        foreach ($sources as $name => $source) {
            $safeName = $this->safePeerName((string) $name);
            $commands = is_array($source) ? ($source['allowed_commands'] ?? []) : [];
            $count = is_array($commands) ? count($commands) : 0;
            $commandCount += $count;
            $valid = $this->verify('peers', 'incoming.'.$safeName, 'Incoming: '.$safeName, function () use ($name, $source, $commands, $handlers): void {
                $this->identifier('source_service', $name);
                if (! is_array($source) || ! is_string($source['secret'] ?? $source['signing_secret'] ?? null)
                    || ($source['secret'] ?? $source['signing_secret']) === '') {
                    throw new InvalidArgumentException;
                }
                if (! array_key_exists('allowed_commands', $source) && ($source['allow_all_commands'] ?? false) === true) {
                    return;
                }
                if (! is_array($commands) || $commands === []) {
                    throw new InvalidArgumentException;
                }
                foreach ($commands as $command => $settings) {
                    $listed = array_is_list($commands);
                    $command = $listed ? $settings : $command;
                    $this->identifier('command', $command);
                    if (! $listed && $settings !== null && ! is_array($settings)) {
                        throw new InvalidArgumentException;
                    }
                    if (isset($handlers[$command])) {
                        $this->handler($handlers[$command]);
                    } elseif (! $listed && $settings !== null) {
                        if (! is_array($settings)) {
                            throw new InvalidArgumentException;
                        }
                        if (isset($settings['handler'])) {
                            $this->handler($settings['handler']);
                        } elseif (($settings['driver'] ?? null) !== 'none') {
                            throw new InvalidArgumentException;
                        }
                    }
                }
            }, $count.' commands configured', 'Check source identifier, secret, effective command allowlist, driver and handler references.');
            if ($valid && ($source['allow_all_commands'] ?? false) === true && ! array_key_exists('allowed_commands', $source)) {
                $this->add('peers', 'incoming.'.$safeName.'.allow_all', 'warn', 'Incoming allow-all: '.$safeName, 'enabled', 'No explicit command allowlist.');
            }
            if ($valid && is_array($commands)) {
                $required = count(array_filter($commands, fn ($settings): bool => is_array($settings) && ($settings['idempotency'] ?? null) === 'required'));
                $this->add('peers', 'incoming.'.$safeName.'.idempotency', 'info', 'Incoming idempotency: '.$safeName, $required.' commands require a key', 'Other commands retain optional idempotency.');
            }
        }
        foreach (['outgoing_count' => ['Outgoing peers', count($outgoing)], 'incoming_count' => ['Incoming sources', count($sources)], 'incoming_commands' => ['Incoming commands', $commandCount]] as $key => [$label, $value]) {
            $this->add('peers', $key, 'info', $label, $value);
        }
    }

    private function handler(mixed $class): void
    {
        if (! is_string($class) || ! class_exists($class) || ! is_a($class, TalktoIncomingCommandHandler::class, true)) {
            throw new InvalidArgumentException;
        }
    }

    private function tls(string $category, string $key, string $label, TalktoOutgoingTarget $target): void
    {
        $verified = $target->verifySsl();
        $bundle = $target->caBundle();
        $usable = $bundle === null || (preg_match('~^[a-z][a-z0-9+.-]*://~i', $bundle) !== 1 && is_file($bundle) && is_readable($bundle));
        $this->add($category, $key, ! $verified || ! $usable ? 'warn' : 'pass', $label, $verified ? 'enabled' : 'disabled',
            ! $verified ? 'Certificate verification is disabled.' : (! $usable ? 'Configured CA bundle is not a readable file.' : null));
    }

    private function runtime(): void
    {
        $connection = config('queue.default');
        $queue = is_string($connection) ? config('queue.connections.'.$connection) : null;
        $driver = is_array($queue) ? ($queue['driver'] ?? null) : null;
        $status = ! is_string($driver) || $driver === '' ? 'fail' : ($driver === 'sync' ? (app()->environment('production', 'staging') ? 'warn' : 'info') : ($driver === 'null' ? 'warn' : 'pass'));
        $this->add('runtime', 'queue_connection', $status, 'Queue connection', is_string($connection) ? $connection : 'missing',
            $driver === 'sync' ? 'Synchronous processing; worker availability is not inspected.' : ($driver === 'null' ? 'This queue driver discards jobs.' : null));

        if (! (bool) config('talkto.routes.enabled', false)) {
            $this->add('runtime', 'package_routes', 'info', 'Package routes', 'disabled', 'Host-owned routes may be used.');
        } else {
            $this->verify('runtime', 'package_routes', 'Package routes', function (): void {
                $this->route(config('talkto.routes.receive_name', 'talkto.receive'), 'POST', TalktoReceiveController::class);
                if ((bool) config('talkto.callbacks.enabled', true)) {
                    $this->route(config('talkto.routes.callback_name', 'talkto.callback'), 'POST', TalktoResultCallbackController::class);
                }
            }, 'enabled', 'Expected Talkto POST routes are missing or inconsistent.');
        }
        $this->add('runtime', 'package_migrations', 'info', 'Package migrations', (bool) config('talkto.migrations.enabled', false) ? 'enabled' : 'disabled', 'Readiness depends on existing tables; no migrations are run.');
    }

    private function optionalFeatures(): void
    {
        if (! (bool) config('talkto.callbacks.enabled', true)) {
            $this->add('optional_features', 'result_callbacks', 'info', 'Result callbacks', 'disabled');
        } else {
            $this->verify('optional_features', 'result_callbacks', 'Result callbacks', function (): void {
                $command = config('talkto.callbacks.command', 'talkto.result');
                if ($command !== null && ! is_scalar($command)) {
                    throw new InvalidArgumentException;
                }
                $command = (string) $command;
                $this->identifier('command', $command === '' ? 'talkto.result' : $command);
                if ((bool) config('talkto.callbacks.auto_dispatch', true)) {
                    foreach ($this->incomingSources() as $name => $source) {
                        $target = $this->targets->get((string) $name);
                        $target->callbackEndpointUrl();
                        if ($target->secret() === null) {
                            throw new InvalidArgumentException;
                        }
                    }
                }
            }, 'enabled', 'Check callback command and reverse outgoing target URL, secret and headers for each incoming source.');
        }

        if (config('talkto.panel.enabled', false) !== true) {
            $this->add('optional_features', 'operations_panel', 'info', 'Operations panel', 'disabled');
        } else {
            $this->verify('optional_features', 'operations_panel', 'Operations panel', function (): void {
                $prefix = config('talkto.panel.route.name', 'talkto.panel.');
                $prefix = is_string($prefix) ? $prefix : 'talkto.panel.';
                foreach (['index', 'messages.index', 'messages.show', 'messages.trace', 'messages.callback-status', 'connections.index'] as $suffix) {
                    $this->route($prefix.$suffix, 'GET');
                }
                foreach (['messages.retry', 'dead-letters.reprocess', 'connections.check'] as $suffix) {
                    $this->route($prefix.$suffix, 'POST');
                }
            }, 'enabled', 'Expected panel routes are missing or inconsistent.');
        }
    }

    private function incomingSources(): array
    {
        $incoming = config('talkto.incoming', []);

        return is_array($incoming) ? array_diff_key($incoming, array_flip(['handlers', 'unknown_command_strategy'])) : [];
    }

    private function route(mixed $name, string $method, ?string $controller = null): void
    {
        $route = is_string($name) ? app('router')->getRoutes()->getByName($name) : null;
        if ($route === null || ! in_array($method, $route->methods(), true)
            || ($controller !== null && $route->getControllerClass() !== $controller)) {
            throw new InvalidArgumentException;
        }
    }

    private function identifier(string $field, mixed $value): void
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException;
        }
        $this->validator->validateIdentifier($field, $value);
    }

    private function safePeerName(string $name): string
    {
        try {
            $this->identifier('source_service', $name);

            return $name;
        } catch (Throwable) {
            return 'invalid identifier';
        }
    }

    private function verify(string $category, string $key, string $label, Closure $operation, string $value, string $failure): bool
    {
        try {
            $operation();
            $this->add($category, $key, 'pass', $label, $value);

            return true;
        } catch (Throwable) {
            $this->add($category, $key, 'fail', $label, 'unavailable or invalid', $failure);

            return false;
        }
    }

    private function add(string $category, string $key, string $status, string $label, string|int $value, ?string $message = null): void
    {
        $this->checks[] = [
            'category' => $category, 'key' => $this->redactor->redactText($key), 'status' => $status,
            'label' => $this->redactor->redactText($label), 'value' => is_string($value) ? $this->redactor->redactText($value) : $value,
            'message' => $this->redactor->redactText($message),
        ];
    }
}
