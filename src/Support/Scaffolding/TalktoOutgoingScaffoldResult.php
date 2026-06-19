<?php

namespace Mrezdev\LaravelTalkto\Support\Scaffolding;

final readonly class TalktoOutgoingScaffoldResult
{
    public function __construct(
        public TalktoScaffoldNames $names,
        public TalktoScaffoldPaths $paths,
        public string $clientClass,
        public string $clientFqn,
        public string $clientMethod,
        public string $transactionalClientMethod,
        public string $enumClass,
        public bool $transactional = false,
        public array $created = [],
        public array $skipped = [],
        public array $overwritten = [],
        public array $intended = [],
        public array $warnings = [],
        public array $manualUpdates = [],
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function exampleUsage(): string
    {
        $normal = 'app(\\'.$this->clientFqn.'::class)'.PHP_EOL
            .'    ->'.$this->clientMethod.'($source);';

        if (! $this->transactional) {
            return $normal;
        }

        return 'Normal:'.PHP_EOL
            .$normal.PHP_EOL.PHP_EOL
            .'Transactional:'.PHP_EOL
            .'app(\\'.$this->clientFqn.'::class)'.PHP_EOL
            .'    ->'.$this->transactionalClientMethod.'($data);';
    }
}
