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
            'Verify'.'Invoice',
            'Dem'.'and',
            'App'.'eal',
            'Hy'.'brid',
            'Material'.'Detail',
            'create:receive-bulks-'.'hybrid',
            'receive-bulks-'.'hybrid',
            'product_'.'inventory',
            'ware'.'house',
            'mrezdev'.'_testing',
            'inventory'.'_testing',
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
