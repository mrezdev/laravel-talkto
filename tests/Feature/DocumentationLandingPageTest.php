<?php

function documentationLandingPath(string $path): string
{
    return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
}

function documentationLandingFile(string $path): string
{
    return file_get_contents(documentationLandingPath($path)) ?: '';
}

test('public documentation index is the full technical documentation map', function (): void {
    $index = documentationLandingFile('docs/README.md');

    foreach ([
        '# Laravel Talkto Documentation',
        '## Start Here',
        '## Core Concepts',
        '## Examples',
        '## Operations',
        '## Package Development',
        '## Upgrade And Support',
        '## Maintainer Notes',
    ] as $heading) {
        expect($index)->toContain($heading);
    }

    expect($index)->toContain('full public documentation map');
});

test('public documentation index still links to the required technical docs', function (): void {
    $index = documentationLandingFile('docs/README.md');

    foreach ([
        'installation.md',
        'configuration.md',
        'security.md',
        'production-hardening.md',
        'troubleshooting.md',
        'architecture.md',
        'sending-commands.md',
        'handling-commands.md',
        'result-callbacks.md',
        'extending.md',
        'PUBLIC_API.md',
        'examples/outgoing-only.md',
        'examples/incoming-only.md',
        'examples/bidirectional-callback.md',
        'command-contract-template.md',
        'callback-contract-template.md',
        'host-integration-template.md',
        'recovery-monitoring-template.md',
        'panel.md',
        'testing.md',
        'smoke-tests.md',
        'production-rollout-template.md',
        'release-readiness.md',
        'release-process.md',
        'ci.md',
        'versioning.md',
        'scaffolding.md',
        'transactional-outgoing.md',
        'http-client.md',
        'local-http-e2e-template.md',
        'installing-into-existing-apps.md',
        'new-service-onboarding.md',
        'upgrading.md',
        '../UPGRADE.md',
        '../CHANGELOG.md',
        '../SECURITY.md',
        '../SUPPORT.md',
    ] as $link) {
        expect($index)->toContain('('.$link.')')
            ->and(documentationLandingPath(str_starts_with($link, '../') ? substr($link, 3) : 'docs/'.$link))->toBeFile();
    }

    expect($index)->toContain('supported public surface')
        ->and($index)->toContain('internal boundary');
});

test('public documentation index does not link to internal maintainer docs', function (): void {
    $index = documentationLandingFile('docs/README.md');

    expect($index)->toContain('Internal maintainer notes are kept in the repository only')
        ->and($index)->not->toContain('docs/internal')
        ->and($index)->not->toContain('internal/README.md');
});

test('documentation webp assets remain available for package landing pages', function (): void {
    foreach ([
        'docs/assets/talkto-laravel-thinking.webp',
        'docs/assets/talkto-two-service-flow.webp',
        'docs/assets/talkto-service-network.webp',
    ] as $asset) {
        expect(documentationLandingPath($asset))->toBeFile();
    }
});
