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

if (! function_exists('p49RepositoryMetadataPaths')) {
    function p49RepositoryMetadataPaths(): array
    {
        return [
            '.gitignore',
            '.gitattributes',
            'SECURITY.md',
            'SUPPORT.md',
            '.github/workflows/tests.yml',
            '.github/ISSUE_TEMPLATE/bug_report.md',
            '.github/ISSUE_TEMPLATE/feature_request.md',
            '.github/pull_request_template.md',
        ];
    }
}

test('repository metadata files exist', function (): void {
    foreach (p49RepositoryMetadataPaths() as $path) {
        expect(p49PackagePath($path))->toBeFile();
    }
});

test('github issue and pull request templates request sanitized generic context', function (): void {
    $templates = [
        '.github/ISSUE_TEMPLATE/bug_report.md',
        '.github/ISSUE_TEMPLATE/feature_request.md',
        '.github/pull_request_template.md',
    ];

    $combined = implode("\n", array_map(
        fn (string $path): string => p49ReadPackageFile($path),
        $templates,
    ));

    foreach ([
        'Package version or Git tag',
        'Laravel version',
        'PHP version',
        'Sanitized command output',
        'talkto:trace',
        'talkto:security-audit',
        'minimal generic reproduction',
        'Do not paste secrets, tokens, signatures, cookies, Authorization headers',
    ] as $term) {
        expect($combined)->toContain($term);
    }

    $pullRequest = p49ReadPackageFile('.github/pull_request_template.md');

    foreach ([
        'Tests were run',
        'Documentation was updated when needed',
        'No application-specific business terms were added',
        'No secrets, tokens, signatures, cookies, Authorization headers',
        'No generated artifacts were added',
        'Changed-files-only ZIP or manifest artifacts are not intended for commit unless explicitly requested',
    ] as $term) {
        expect($pullRequest)->toContain($term);
    }

    foreach (array_merge(p49ForbiddenProjectTerms(), p49ObviousSecretPatterns()) as $term) {
        expect($combined)->not->toContain($term);
    }
});

test('github workflow validates composer metadata installs dependencies and runs package tests', function (): void {
    $workflow = p49ReadPackageFile('.github/workflows/tests.yml');

    expect($workflow)->toContain('pull_request')
        ->and($workflow)->toContain('push:')
        ->and($workflow)->toContain('actions/checkout')
        ->and($workflow)->toContain('shivammathur/setup-php')
        ->and($workflow)->toContain('composer validate --strict')
        ->and($workflow)->toContain('composer install --prefer-dist --no-interaction --no-progress')
        ->and($workflow)->toContain('vendor/bin/pest');

    foreach (['deploy', 'scp ', 'rsync', 'gh release', 'composer publish', 'git push'] as $term) {
        expect(strtolower($workflow))->not->toContain($term);
    }

    foreach (array_merge(p49ForbiddenProjectTerms(), p49ObviousSecretPatterns(), ['E:/laragon', 'E:\\laragon', 'mrezdev-v2']) as $term) {
        expect($workflow)->not->toContain($term);
    }
});

test('gitignore excludes generated local and dependency files', function (): void {
    $gitignore = p49ReadPackageFile('.gitignore');

    foreach ([
        '/vendor/',
        'composer.lock',
        '.phpunit.cache/',
        '.pest/',
        '.env',
        '.env.*',
        'storage/',
        'node_modules/',
        'coverage/',
        'build/',
    ] as $line) {
        expect($gitignore)->toContain($line);
    }
});

test('gitattributes keeps release docs and excludes development artifacts from archives', function (): void {
    $attributes = p49ReadPackageFile('.gitattributes');

    foreach ([
        '* text=auto',
        '/.github/ export-ignore',
        '/tests/ export-ignore',
        '/vendor/ export-ignore',
        '/composer.lock export-ignore',
    ] as $line) {
        expect($attributes)->toContain($line);
    }

    foreach (['README.md export-ignore', 'LICENSE.md export-ignore', 'CHANGELOG.md export-ignore'] as $line) {
        expect($attributes)->not->toContain($line);
    }
});

test('security and support docs are private safe', function (): void {
    $combined = p49ReadPackageFile('SECURITY.md')."\n".p49ReadPackageFile('SUPPORT.md');
    $lower = strtolower($combined);

    expect($lower)->toContain('private')
        ->and($lower)->toContain('proprietary')
        ->and($lower)->toContain('no public vulnerability disclosure address')
        ->and($lower)->toContain('internal');

    foreach (p49ObviousSecretPatterns() as $term) {
        expect($combined)->not->toContain($term);
    }
});
