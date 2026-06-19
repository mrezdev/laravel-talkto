<?php

namespace Mrezdev\LaravelTalkto\Services\Scaffolding;

use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoScaffoldNames;

final class TalktoScaffoldNameResolver
{
    public function resolveService(string $service): TalktoScaffoldNames
    {
        $serviceNames = $this->serviceNames($service);

        return new TalktoScaffoldNames(
            originalService: $serviceNames['original'],
            serviceKebab: $serviceNames['kebab'],
            serviceStudly: $serviceNames['studly'],
            serviceCamel: $serviceNames['camel'],
            serviceVariable: $serviceNames['variable'],
            serviceNamespaceSegment: $serviceNames['namespace_segment'],
        );
    }

    public function resolveOutgoing(string $service, string $command): TalktoScaffoldNames
    {
        $serviceNames = $this->serviceNames($service);

        if (str_contains(trim($command), '.')) {
            throw new InvalidArgumentException('Outgoing command name must be short and must not contain dots.');
        }

        $commandNames = $this->commandNames($command, useLastSegment: false);

        return new TalktoScaffoldNames(
            originalService: $serviceNames['original'],
            serviceKebab: $serviceNames['kebab'],
            serviceStudly: $serviceNames['studly'],
            serviceCamel: $serviceNames['camel'],
            serviceVariable: $serviceNames['variable'],
            serviceNamespaceSegment: $serviceNames['namespace_segment'],
            originalCommand: $commandNames['original'],
            commandValue: $commandNames['value'],
            commandKebab: $commandNames['kebab'],
            commandShortKebab: $commandNames['short_kebab'],
            commandStudly: $commandNames['studly'],
            commandCamel: $commandNames['camel'],
            commandEnumCase: $commandNames['studly'],
            outgoingCommandValue: $serviceNames['kebab'].'.'.$commandNames['kebab'],
            outgoingSendClass: 'Send'.$commandNames['studly'].'To'.$serviceNames['studly'],
            outgoingPayloadBuilderClass: $commandNames['studly'].'PayloadBuilder',
            outgoingTransactionalSourceActionClass: 'Prepare'.$commandNames['studly'].'SourceAction',
            outgoingClientMethod: $commandNames['camel'],
            outgoingTransactionalClientMethod: $commandNames['camel'].'Transactionally',
        );
    }

    public function resolveIncoming(string $service, string $command): TalktoScaffoldNames
    {
        $serviceNames = $this->serviceNames($service);
        $commandNames = $this->commandNames($command, useLastSegment: true);

        return new TalktoScaffoldNames(
            originalService: $serviceNames['original'],
            serviceKebab: $serviceNames['kebab'],
            serviceStudly: $serviceNames['studly'],
            serviceCamel: $serviceNames['camel'],
            serviceVariable: $serviceNames['variable'],
            serviceNamespaceSegment: $serviceNames['namespace_segment'],
            originalCommand: $commandNames['original'],
            commandValue: $commandNames['value'],
            commandKebab: $commandNames['kebab'],
            commandShortKebab: $commandNames['short_kebab'],
            commandStudly: $commandNames['studly'],
            commandCamel: $commandNames['camel'],
            commandEnumCase: $commandNames['studly'],
            incomingHandlerClass: $commandNames['studly'].'Handler',
            incomingActionClass: 'Handle'.$commandNames['studly'].'From'.$serviceNames['studly'],
            incomingValidatorClass: $commandNames['studly'].'PayloadValidator',
        );
    }

    private function serviceNames(string $service): array
    {
        $original = trim($service);

        if ($original === '') {
            throw new InvalidArgumentException('Service name cannot be empty.');
        }

        if (! preg_match('/^[A-Za-z0-9_-]+$/', $original)) {
            throw new InvalidArgumentException('Service name contains unsafe characters.');
        }

        $parts = $this->splitName($original);

        if (strtolower((string) end($parts)) === 'service' && count($parts) > 1) {
            array_pop($parts);
        }

        return [
            'original' => $original,
            'kebab' => $this->kebab($parts),
            'studly' => $this->studly($parts),
            'camel' => $this->camel($parts),
            'variable' => $this->camel($parts),
            'namespace_segment' => $this->studly($parts),
        ];
    }

    private function commandNames(string $command, bool $useLastSegment): array
    {
        $original = trim($command);

        if ($original === '') {
            throw new InvalidArgumentException('Command name cannot be empty.');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $original) || str_contains($original, '..')) {
            throw new InvalidArgumentException('Command name contains unsafe characters.');
        }

        $segments = explode('.', $original);

        if (in_array('', $segments, true)) {
            throw new InvalidArgumentException('Command name contains an empty segment.');
        }

        $short = $useLastSegment ? end($segments) : str_replace('.', '-', $original);
        $shortParts = $this->splitName((string) $short);
        $value = implode('.', array_map(fn (string $segment): string => $this->kebab($this->splitName($segment)), $segments));

        return [
            'original' => $original,
            'value' => $value,
            'kebab' => $useLastSegment ? $this->kebab($shortParts) : str_replace('.', '-', $value),
            'short_kebab' => $this->kebab($shortParts),
            'studly' => $this->studly($shortParts),
            'camel' => $this->camel($shortParts),
        ];
    }

    private function splitName(string $name): array
    {
        $prepared = preg_replace('/(?<!^)[A-Z]/', '-$0', $name);
        $parts = preg_split('/[._-]+/', (string) $prepared, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            throw new InvalidArgumentException('Name cannot be empty.');
        }

        return $parts;
    }

    private function kebab(array $parts): string
    {
        return strtolower(implode('-', $parts));
    }

    private function studly(array $parts): string
    {
        return implode('', array_map(
            fn (string $part): string => ucfirst(strtolower($part)),
            $parts,
        ));
    }

    private function camel(array $parts): string
    {
        return lcfirst($this->studly($parts));
    }
}
