<?php

namespace Mrezdev\LaravelTalkto\Support\Scaffolding;

final readonly class TalktoScaffoldPaths
{
    public function __construct(
        public string $basePath,
        public string $baseNamespace,
        public string $servicePath,
        public string $serviceNamespace,
        public string $commandPath,
        public string $commandNamespace,
        public array $files,
    ) {}

    public function file(string $key): ?string
    {
        return $this->files[$key] ?? null;
    }

    public function toArray(): array
    {
        return [
            'base_path' => $this->basePath,
            'base_namespace' => $this->baseNamespace,
            'service_path' => $this->servicePath,
            'service_namespace' => $this->serviceNamespace,
            'command_path' => $this->commandPath,
            'command_namespace' => $this->commandNamespace,
            'files' => $this->files,
        ];
    }
}
