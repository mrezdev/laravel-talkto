<?php

namespace Mrezdev\LaravelTalkto\Support\Scaffolding;

final readonly class TalktoIncomingScaffoldResult
{
    public function __construct(
        public TalktoScaffoldNames $names,
        public TalktoScaffoldPaths $paths,
        public string $handlerClass,
        public string $handlerFqn,
        public string $enumClass,
        public string $configSnippet,
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
}
