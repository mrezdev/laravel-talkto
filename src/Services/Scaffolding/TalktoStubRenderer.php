<?php

namespace Mrezdev\LaravelTalkto\Services\Scaffolding;

final class TalktoStubRenderer
{
    public function render(string $template, array $replacements): string
    {
        $replace = [];

        foreach ($replacements as $key => $value) {
            $replace['{{ '.$key.' }}'] = (string) $value;
            $replace['{{'.$key.'}}'] = (string) $value;
        }

        return strtr($template, $replace);
    }

    public function hasUnresolvedPlaceholders(string $content): bool
    {
        return preg_match('/{{\s*[A-Za-z0-9_.-]+\s*}}/', $content) === 1;
    }
}
