<?php

namespace Mrezdev\LaravelTalkto\Support\Scaffolding;

final readonly class TalktoScaffoldFile
{
    public function __construct(
        public string $path,
        public string $contents,
    ) {}
}
