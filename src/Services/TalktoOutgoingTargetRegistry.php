<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoOutgoingTarget;
use Mrezdev\LaravelTalkto\Exceptions\UnknownTalktoOutgoingTarget;

/**
 * Public registry for programmatic outgoing target registration.
 */
class TalktoOutgoingTargetRegistry implements TalktoOutgoingTargetRegistryContract
{
    private array $targets = [];

    public function register(string $name, array|TalktoOutgoingTarget $target): void
    {
        $validator = app(TalktoEnvelopeFieldValidator::class);
        $validator->validateIdentifier('target_service', $name);
        $validator->validateTalktoHeaders($this->headersForTarget($target), $this->configuredTalktoHeaderNames());

        $name = trim($name);

        if ($name === '') {
            return;
        }

        $this->targets[$name] = $target;
    }

    public function has(string $name): bool
    {
        return $this->resolve($name) !== null;
    }

    public function get(string $name): TalktoOutgoingTarget
    {
        $target = $this->resolve($name);

        if (! $target) {
            throw UnknownTalktoOutgoingTarget::forTarget($name);
        }

        return $target;
    }

    public function resolve(string $name): ?TalktoOutgoingTarget
    {
        $resolvedName = $this->resolveAlias($name);
        app(TalktoEnvelopeFieldValidator::class)->validateIdentifier('target_service', $resolvedName);
        $targets = $this->all();

        if (! array_key_exists($resolvedName, $targets)) {
            return null;
        }

        $target = $targets[$resolvedName];

        if ($target instanceof TalktoOutgoingTarget) {
            app(TalktoEnvelopeFieldValidator::class)->validateTalktoHeaders($target->headers(), $this->configuredTalktoHeaderNames());

            return $target;
        }

        if (! is_array($target)) {
            throw InvalidTalktoOutgoingTarget::forTarget($resolvedName, 'configuration must be an array');
        }

        $target = new TalktoOutgoingTarget($resolvedName, $target);
        app(TalktoEnvelopeFieldValidator::class)->validateTalktoHeaders($target->headers(), $this->configuredTalktoHeaderNames());

        return $target;
    }

    public function all(): array
    {
        $configured = config('talkto.outgoing', []);
        $configured = is_array($configured) ? $configured : [];

        return array_merge($configured, $this->targets);
    }

    private function resolveAlias(string $name): string
    {
        $alias = config("talkto.aliases.{$name}");

        return is_string($alias) && $alias !== '' ? $alias : $name;
    }

    private function headersForTarget(array|TalktoOutgoingTarget $target): array
    {
        if ($target instanceof TalktoOutgoingTarget) {
            return $target->headers();
        }

        $headers = $target['headers'] ?? [];

        return is_array($headers) ? $headers : [];
    }

    private function configuredTalktoHeaderNames(): array
    {
        return [
            'signature_version_header_name' => config('talkto.security.signature_version_header', 'X-Talkto-Signature-Version'),
            'nonce_header_name' => config('talkto.security.nonce_header', 'X-Talkto-Nonce'),
        ];
    }
}
