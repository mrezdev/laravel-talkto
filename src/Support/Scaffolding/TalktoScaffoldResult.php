<?php

namespace Mrezdev\LaravelTalkto\Support\Scaffolding;

final readonly class TalktoScaffoldResult
{
    public function __construct(
        public array $created = [],
        public array $skipped = [],
        public array $overwritten = [],
        public array $intended = [],
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'skipped' => $this->skipped,
            'overwritten' => $this->overwritten,
            'intended' => $this->intended,
            'errors' => $this->errors,
        ];
    }
}
