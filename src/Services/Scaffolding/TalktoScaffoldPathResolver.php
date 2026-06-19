<?php

namespace Mrezdev\LaravelTalkto\Services\Scaffolding;

use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoScaffoldPaths;

final class TalktoScaffoldPathResolver
{
    public function __construct(
        private readonly TalktoScaffoldNameResolver $names,
    ) {}

    public function resolveOutgoing(
        string $service,
        string $command,
        string $basePath = 'app/Talkto',
        string $baseNamespace = 'App\\Talkto',
    ): TalktoScaffoldPaths {
        $names = $this->names->resolveOutgoing($service, $command);
        $basePath = $this->normalizeBasePath($basePath);
        $baseNamespace = $this->normalizeBaseNamespace($baseNamespace);
        $servicePath = $basePath.'/Outgoing/'.$names->serviceNamespaceSegment;
        $commandPath = $servicePath.'/Commands/'.$names->commandStudly;
        $serviceNamespace = $baseNamespace.'\\Outgoing\\'.$names->serviceNamespaceSegment;
        $commandNamespace = $serviceNamespace.'\\Commands\\'.$names->commandStudly;

        return new TalktoScaffoldPaths(
            basePath: $basePath,
            baseNamespace: $baseNamespace,
            servicePath: $servicePath,
            serviceNamespace: $serviceNamespace,
            commandPath: $commandPath,
            commandNamespace: $commandNamespace,
            files: [
                'client' => $servicePath.'/'.$names->serviceStudly.'TalktoClient.php',
                'command_enum' => $servicePath.'/'.$names->serviceStudly.'OutgoingCommand.php',
                'send_action' => $commandPath.'/'.$names->outgoingSendClass.'.php',
                'payload_builder' => $commandPath.'/'.$names->outgoingPayloadBuilderClass.'.php',
                'transactional_source_action' => $commandPath.'/'.$names->outgoingTransactionalSourceActionClass.'.php',
            ],
        );
    }

    public function resolveIncoming(
        string $service,
        string $command,
        string $basePath = 'app/Talkto',
        string $baseNamespace = 'App\\Talkto',
    ): TalktoScaffoldPaths {
        $names = $this->names->resolveIncoming($service, $command);
        $basePath = $this->normalizeBasePath($basePath);
        $baseNamespace = $this->normalizeBaseNamespace($baseNamespace);
        $servicePath = $basePath.'/Incoming/'.$names->serviceNamespaceSegment;
        $commandPath = $servicePath.'/Commands/'.$names->commandStudly;
        $serviceNamespace = $baseNamespace.'\\Incoming\\'.$names->serviceNamespaceSegment;
        $commandNamespace = $serviceNamespace.'\\Commands\\'.$names->commandStudly;

        return new TalktoScaffoldPaths(
            basePath: $basePath,
            baseNamespace: $baseNamespace,
            servicePath: $servicePath,
            serviceNamespace: $serviceNamespace,
            commandPath: $commandPath,
            commandNamespace: $commandNamespace,
            files: [
                'command_enum' => $servicePath.'/'.$names->serviceStudly.'IncomingCommand.php',
                'handler' => $commandPath.'/'.$names->incomingHandlerClass.'.php',
                'action' => $commandPath.'/'.$names->incomingActionClass.'.php',
                'validator' => $commandPath.'/'.$names->incomingValidatorClass.'.php',
            ],
        );
    }

    private function normalizeBasePath(string $basePath): string
    {
        return rtrim(str_replace('\\', '/', trim($basePath)), '/');
    }

    private function normalizeBaseNamespace(string $baseNamespace): string
    {
        $baseNamespace = trim(trim($baseNamespace), '\\');

        if ($baseNamespace === '') {
            throw new InvalidArgumentException('Base namespace cannot be empty.');
        }

        return $baseNamespace;
    }
}
