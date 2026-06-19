<?php

if (! function_exists('p48PackagePath')) {
    function p48PackagePath(string $path): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}

if (! function_exists('p48ReadPackageFile')) {
    function p48ReadPackageFile(string $path): string
    {
        return file_get_contents(p48PackagePath($path)) ?: '';
    }
}

if (! function_exists('p48ForbiddenProjectTerms')) {
    function p48ForbiddenProjectTerms(): array
    {
        return [
            'Verify'.'In'.'voice',
            'Dem'.'and',
            'App'.'eal',
            'Hy'.'brid',
            'Material'.'Detail',
            'create:receive-bulks-'.'hy'.'brid',
            'receive-bulks-'.'hy'.'brid',
            'product_'.'inven'.'tory',
            'ware'.'house',
            'mrezdev'.'_testing',
            'inven'.'tory'.'_testing',
        ];
    }
}

if (! function_exists('p48ObviousSecretPatterns')) {
    function p48ObviousSecretPatterns(): array
    {
        return [
            'APP_'.'KEY=',
            'WEBHOOK_CLIENT_'.'SECRET=',
            'local-talkto-test-'.'secret',
            'pass'.'word=',
            'tok'.'en=',
        ];
    }
}

if (! function_exists('p48RequiredDocPaths')) {
    function p48RequiredDocPaths(): array
    {
        return [
            'docs/README.md',
            'docs/host-integration-template.md',
            'docs/new-service-onboarding.md',
            'docs/local-http-e2e-template.md',
            'docs/command-contract-template.md',
            'docs/callback-contract-template.md',
            'docs/recovery-monitoring-template.md',
            'docs/production-rollout-template.md',
            'docs/testing.md',
            'docs/troubleshooting.md',
        ];
    }
}

if (! function_exists('p48DocumentationText')) {
    function p48DocumentationText(): string
    {
        $paths = array_merge(['README.md'], p48RequiredDocPaths());

        return implode("\n", array_map(
            fn (string $path): string => p48ReadPackageFile($path),
            $paths,
        ));
    }
}

test('required onboarding docs exist', function (): void {
    foreach (p48RequiredDocPaths() as $path) {
        expect(p48PackagePath($path))->toBeFile();
    }
});

test('readme links to the new service onboarding kit', function (): void {
    $readme = p48ReadPackageFile('README.md');

    foreach ([
        'docs/README.md',
        'docs/new-service-onboarding.md',
        'docs/local-http-e2e-template.md',
        'docs/command-contract-template.md',
        'docs/callback-contract-template.md',
        'docs/recovery-monitoring-template.md',
        'docs/production-rollout-template.md',
    ] as $link) {
        expect($readme)->toContain($link);
    }
});

test('docs cover security redaction idempotency callback retry and rollback', function (): void {
    $docs = strtolower(p48DocumentationText());

    foreach (['security', 'redaction', 'idempotency', 'callback', 'retry', 'rollback'] as $term) {
        expect($docs)->toContain($term);
    }
});

test('docs stay free of project terms and committed secret values', function (): void {
    $docs = p48DocumentationText();

    foreach (array_merge(p48ForbiddenProjectTerms(), p48ObviousSecretPatterns()) as $term) {
        expect($docs)->not->toContain($term);
    }
});

test('docs reference the real incoming result API', function (): void {
    $docs = implode("\n", [
        p48ReadPackageFile('README.md'),
        p48ReadPackageFile('docs/command-contract-template.md'),
        p48ReadPackageFile('docs/result-callbacks.md'),
        p48ReadPackageFile('docs/PUBLIC_API.md'),
    ]);

    expect($docs)->not->toContain('TalktoIncomingCommandResult::failed(')
        ->and($docs)->toContain('failedFinal(')
        ->and($docs)->toContain('failedRetryable(')
        ->and($docs)->toContain('isSucceeded()')
        ->and($docs)->toContain('IncomingCommandResultContract');
});

test('readme references the real result callback sender API', function (): void {
    $readme = p48ReadPackageFile('README.md');

    expect($readme)->not->toContain('sendResult'.'Callback(')
        ->and($readme)->toContain('sendResult(')
        ->and($readme)->toContain('ResultCallbackSenderContract')
        ->and($readme)->toContain('TalktoIncomingCommandResult');
});

test('readme references the real outgoing message factory options api', function (): void {
    $readme = p48ReadPackageFile('README.md');

    expect($readme)->not->toContain('business'.'Key:')
        ->and($readme)->not->toContain('idempotency'.'Key:')
        ->and($readme)->toContain("'business_key'")
        ->and($readme)->toContain("'idempotency_key'")
        ->and($readme)->toContain('TalktoOutgoingMessageFactory');
});

test('readme contains github landing sections', function (): void {
    $readme = p48ReadPackageFile('README.md');

    foreach ([
        '## What Laravel Talkto Is',
        '## When To Use It',
        '## When Not To Use It',
        '## Core Features',
        '## 60-Second Architecture Overview',
        '## Requirements',
        '## Installation',
        '## 5-Minute Quickstart',
        '## Security Model Summary',
        '## Retry, DLQ, And Observability Summary',
        '## Documentation Map',
        '## Public API / Extension Points',
        '## Current Maturity / Release Status',
    ] as $heading) {
        expect($readme)->toContain($heading);
    }
});

test('documentation index exists and links only to existing files', function (): void {
    $indexPath = p48PackagePath('docs/README.md');
    $index = p48ReadPackageFile('docs/README.md');

    expect($indexPath)->toBeFile();

    preg_match_all('/\[[^\]]+\]\(([^)#]+)(?:#[^)]+)?\)/', $index, $matches);

    foreach ($matches[1] as $link) {
        if (str_starts_with($link, 'http://') || str_starts_with($link, 'https://')) {
            continue;
        }

        $target = str_starts_with($link, '../')
            ? p48PackagePath(substr($link, 3))
            : p48PackagePath('docs/'.$link);

        expect($target)->toBeFile();
    }
});

test('readme and docs avoid fake callback api and proprietary release overclaims', function (): void {
    $combined = p48DocumentationText()."\n".p48ReadPackageFile('docs/README.md');
    $composer = json_decode(p48ReadPackageFile('composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($combined)->not->toContain('sendResult'.'Callback(')
        ->and($combined)->toContain('sendResult(');

    if (($composer['license'] ?? null) === 'proprietary') {
        $lower = strtolower($combined);

        foreach ([
            'mit license',
            'licensed under mit',
            'open-source package',
            'open source package',
            'released as open source',
            'publicly released package',
        ] as $claim) {
            expect($lower)->not->toContain($claim);
        }
    }
});
