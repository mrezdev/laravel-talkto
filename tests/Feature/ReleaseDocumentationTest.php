<?php

if (! function_exists('p49PackagePath')) {
    function p49PackagePath(string $path): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}

if (! function_exists('p49ReadPackageFile')) {
    function p49ReadPackageFile(string $path): string
    {
        return file_get_contents(p49PackagePath($path)) ?: '';
    }
}

if (! function_exists('p49ForbiddenProjectTerms')) {
    function p49ForbiddenProjectTerms(): array
    {
        return [
            'Verify'.'Invoice',
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

if (! function_exists('p49ObviousSecretPatterns')) {
    function p49ObviousSecretPatterns(): array
    {
        return [
            'APP_'.'KEY=',
            'WEBHOOK_CLIENT_'.'SECRET=',
            'local-talkto-test-'.'secret',
            'pass'.'word=',
            'tok'.'en=',
            'ghp'.'_',
            'github'.'_pat_',
        ];
    }
}

if (! function_exists('p49ReleaseDocPaths')) {
    function p49ReleaseDocPaths(): array
    {
        return [
            'docs/private-repository-setup.md',
            'docs/ci.md',
            'docs/release-process.md',
            'docs/versioning.md',
            'docs/private-composer-installation.md',
            'docs/package-extraction-checklist.md',
            'docs/public-release-readiness.md',
        ];
    }
}

if (! function_exists('p49DocumentationAndMetadataPaths')) {
    function p49DocumentationAndMetadataPaths(): array
    {
        return array_merge([
            'README.md',
            'CHANGELOG.md',
            'LICENSE.md',
            'SECURITY.md',
            'SUPPORT.md',
            '.github/workflows/tests.yml',
            '.github/ISSUE_TEMPLATE/bug_report.md',
            '.github/ISSUE_TEMPLATE/feature_request.md',
            '.github/pull_request_template.md',
        ], p49ReleaseDocPaths());
    }
}

if (! function_exists('p49CombinedFiles')) {
    function p49CombinedFiles(array $paths): string
    {
        return implode("\n", array_map(
            fn (string $path): string => p49ReadPackageFile($path),
            $paths,
        ));
    }
}

test('release and private repository docs exist', function (): void {
    foreach (p49ReleaseDocPaths() as $path) {
        expect(p49PackagePath($path))->toBeFile();
    }
});

test('release docs describe tag based private first versioning', function (): void {
    $docs = strtolower(p49CombinedFiles(p49ReleaseDocPaths()));

    foreach ([
        'git tag',
        'no static `version` field',
        'no version in composer.json',
        'private-first',
        'license decision',
        'composer validate --no-check-publish',
        'vendor/bin/pest',
    ] as $term) {
        expect($docs)->toContain($term);
    }
});

test('readme links to repository ci release and installation docs', function (): void {
    $readme = p49ReadPackageFile('README.md');

    foreach (p49ReleaseDocPaths() as $path) {
        expect($readme)->toContain($path);
    }
});

test('repository documentation and metadata stay generic and secret free', function (): void {
    $combined = p49CombinedFiles(p49DocumentationAndMetadataPaths());

    foreach (array_merge(p49ForbiddenProjectTerms(), p49ObviousSecretPatterns()) as $term) {
        expect($combined)->not->toContain($term);
    }
});

test('composer metadata does not define a static package version', function (): void {
    $composer = json_decode(p49ReadPackageFile('composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($composer)->toBeArray()
        ->and($composer)->not->toHaveKey('version');
});
