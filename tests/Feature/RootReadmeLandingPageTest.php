<?php

function rootReadmeLandingPath(string $path): string
{
    return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
}

function rootReadmeLandingFile(string $path): string
{
    return file_get_contents(rootReadmeLandingPath($path)) ?: '';
}

test('root readme contains the friendly package landing sections', function (): void {
    $readme = rootReadmeLandingFile('README.md');

    foreach ([
        '# Laravel Talkto',
        '## Why Laravel Talkto?',
        '## From two apps to a distributed Laravel ecosystem',
        '## Documentation Map',
    ] as $heading) {
        expect($readme)->toContain($heading);
    }
});

test('root readme references existing webp landing assets', function (): void {
    $readme = rootReadmeLandingFile('README.md');

    foreach ([
        'docs/assets/talkto-laravel-thinking.webp',
        'docs/assets/talkto-service-network.webp',
    ] as $asset) {
        expect($readme)->toContain($asset)
            ->and(rootReadmeLandingPath($asset))->toBeFile();
    }

    expect(rootReadmeLandingPath('docs/assets/talkto-two-service-flow.webp'))->toBeFile();
});

test('root readme preserves required technical package sections', function (): void {
    $readme = rootReadmeLandingFile('README.md');

    foreach ([
        '## What It Does',
        '## When To Use It',
        '## When Not To Use It',
        '## Installation',
        '## 5-Minute Quickstart',
        '## Secure Defaults',
        '## Sending Commands',
        '## Receiving Commands',
        '## Result Callbacks',
        '## Documentation Map',
    ] as $heading) {
        expect($readme)->toContain($heading);
    }
});

test('root readme documentation map remains public and technical-doc focused', function (): void {
    $readme = rootReadmeLandingFile('README.md');

    foreach ([
        'docs/README.md',
        'docs/installation.md',
        'docs/configuration.md',
        'docs/security.md',
        'docs/production-hardening.md',
        'docs/troubleshooting.md',
        'docs/PUBLIC_API.md',
    ] as $link) {
        expect($readme)->toContain($link);
    }

    expect($readme)->toContain('technical map')
        ->and($readme)->toContain('grouped links for setup, concepts, examples, operations, package development, and support')
        ->and($readme)->not->toContain('docs/internal')
        ->and($readme)->not->toContain('docs/internal/README.md')
        ->and($readme)->not->toContain('internal/README.md');
});
