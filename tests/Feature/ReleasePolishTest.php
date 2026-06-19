<?php

test('composer metadata stays aligned with public package scope', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json') ?: '{}', true);

    expect($composer['name'])->toBe('mrezdev/laravel-talkto')
        ->and($composer['license'])->toBe('MIT')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Mrezdev\\LaravelTalkto\\');

    foreach (['laravel', 'service-to-service', 'hmac', 'outbox', 'inbox', 'idempotency', 'retry', 'dead-letter', 'callback', 'observability', 'security-audit'] as $keyword) {
        expect($composer['keywords'])->toContain($keyword);
    }

    $docs = releasePolishContents([
        'README.md',
        'docs/README.md',
        'docs/public-release-readiness.md',
        'docs/release-process.md',
    ]);

    expect($docs)->toContain('MIT')
        ->and(strtolower($docs))->toContain('mit license')
        ->and(strtolower($docs))->not->toContain('proprietary');
});

test('github actions workflow runs composer validation and pest on supported php versions', function (): void {
    $workflow = file_get_contents(__DIR__.'/../../.github/workflows/tests.yml') ?: '';

    expect($workflow)->toContain('pull_request:')
        ->and($workflow)->toContain('push:')
        ->and($workflow)->toContain('main')
        ->and($workflow)->toContain('master')
        ->and($workflow)->toContain("'8.2'")
        ->and($workflow)->toContain("'8.3'")
        ->and($workflow)->toContain('composer validate --strict')
        ->and($workflow)->toContain('composer install --prefer-dist --no-interaction --no-progress')
        ->and($workflow)->toContain('vendor/bin/pest');
});

test('documentation indexes and local markdown links resolve to existing files', function (): void {
    foreach (['README.md', 'docs/README.md'] as $file) {
        expect(releasePolishMissingLinks($file))->toBe([]);
    }

    $docsIndex = file_get_contents(__DIR__.'/../../docs/README.md') ?: '';
    preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $docsIndex, $matches);

    foreach ($matches[1] as $href) {
        expect(releasePolishLinkExists('docs/README.md', $href))->toBeTrue();
    }
});

test('readme covers final package operations and callback runtime', function (): void {
    $readme = file_get_contents(__DIR__.'/../../README.md') ?: '';

    expect($readme)->toContain('talkto:trace')
        ->and($readme)->toContain('talkto:security-audit')
        ->and($readme)->toContain('generic signed callback runtime')
        ->and($readme)->toContain('Retry, DLQ')
        ->and($readme)->toContain('talkto:dlq-reprocess')
        ->and($readme)->toContain('talkto.routes.enabled')
        ->and($readme)->toContain('talkto.callbacks.enabled');
});

test('docs avoid stale fake public api examples and forbidden host terms', function (): void {
    $contents = releasePolishTreeContents([
        'README.md',
        'docs',
        'stubs',
        'tests',
        'src',
        '.github',
    ]);

    expect($contents)->not->toContain('send'.'Result'.'Callback(')
        ->and($contents)->not->toContain('business'.'Key:')
        ->and($contents)->not->toContain('idempotency'.'Key:')
        ->and($contents)->not->toContain('composer validate --no-check'.'-publish')
        ->and($contents)->toContain('composer validate --strict');

    // Generic public documentation examples such as inventory, invoice,
    // billing, shipping, website, verify-invoice, and receive-bulk are allowed.
    // Host-app business class names and private project terms stay forbidden.
    $forbidden = [
        'de'.'mand',
        'ap'.'peal',
        'hy'.'brid',
        'material'.' detail',
        'i'.'bake',
        'material'.' mapping',
        'product'.' mapping',
    ];

    foreach ($forbidden as $term) {
        expect(strtolower($contents))->not->toContain($term);
    }
});

test('public api docs list final release surfaces', function (): void {
    $publicApi = file_get_contents(__DIR__.'/../../docs/PUBLIC_API.md') ?: '';

    foreach ([
        'TalktoEnvelopeData',
        'TalktoIncomingCommandResultData',
        'TalktoResultCallbackData',
        'TalktoTraceReporter',
        'TalktoSecurityAuditor',
        'TalktoRetryDecision',
    ] as $surface) {
        expect($publicApi)->toContain($surface);
    }
});

test('release checklist includes final safety gates before tagging', function (): void {
    $checklist = file_get_contents(__DIR__.'/../../RELEASE_CHECKLIST.md') ?: '';

    expect($checklist)->toContain('full package test suite')
        ->and($checklist)->toContain('talkto:security-audit')
        ->and($checklist)->toContain('talkto:trace')
        ->and($checklist)->toContain('no real secrets')
        ->and($checklist)->toContain('tag only after local tests pass');
});

test('github issue and pull request templates are release ready', function (): void {
    foreach ([
        '.github/ISSUE_TEMPLATE/bug_report.md',
        '.github/ISSUE_TEMPLATE/feature_request.md',
        '.github/pull_request_template.md',
    ] as $path) {
        expect(__DIR__.'/../../'.$path)->toBeFile();
    }

    $templates = releasePolishContents([
        '.github/ISSUE_TEMPLATE/bug_report.md',
        '.github/ISSUE_TEMPLATE/feature_request.md',
        '.github/pull_request_template.md',
    ]);

    foreach ([
        'Package version or Git tag',
        'Laravel version',
        'PHP version',
        'Sanitized command output',
        'talkto:trace',
        'talkto:security-audit',
        'Do not paste secrets, tokens, signatures, cookies, Authorization headers',
    ] as $term) {
        expect($templates)->toContain($term);
    }

    foreach ([
        'inven'.'tory',
        'in'.'voice',
        'de'.'mand',
        'ap'.'peal',
        'hy'.'brid',
        'material'.' detail',
        'i'.'bake',
        'ware'.'house',
        'material'.' mapping',
        'product'.' mapping',
        'APP_'.'KEY=',
        'WEBHOOK_CLIENT_'.'SECRET=',
        'local-talkto-test-'.'secret',
        'pass'.'word=',
        'tok'.'en=',
        'ghp'.'_',
        'github'.'_pat_',
    ] as $term) {
        expect(strtolower($templates))->not->toContain(strtolower($term));
    }
});

function releasePolishContents(array $files): string
{
    return implode("\n", array_map(
        fn (string $file): string => file_get_contents(__DIR__.'/../../'.$file) ?: '',
        $files
    ));
}

function releasePolishTreeContents(array $paths): string
{
    $root = realpath(__DIR__.'/../../');
    $contents = [];

    foreach ($paths as $path) {
        $fullPath = $root.DIRECTORY_SEPARATOR.$path;

        if (is_file($fullPath)) {
            $contents[] = file_get_contents($fullPath) ?: '';

            continue;
        }

        if (! is_dir($fullPath)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $filePath = (string) $file;

            if (preg_match('/\.(php|md)$/', $filePath)) {
                $contents[] = file_get_contents($filePath) ?: '';
            }
        }
    }

    return implode("\n", $contents);
}

function releasePolishMissingLinks(string $file): array
{
    $contents = file_get_contents(__DIR__.'/../../'.$file) ?: '';
    preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $contents, $matches);

    return array_values(array_filter(
        $matches[1],
        fn (string $href): bool => ! releasePolishLinkExists($file, $href)
    ));
}

function releasePolishLinkExists(string $file, string $href): bool
{
    if (preg_match('/^(https?:|mailto:|#)/', $href)) {
        return true;
    }

    $href = preg_replace('/#.*/', '', $href) ?? $href;

    if ($href === '') {
        return true;
    }

    $root = realpath(__DIR__.'/../../');
    $base = dirname($root.DIRECTORY_SEPARATOR.$file);
    $candidate = realpath($base.DIRECTORY_SEPARATOR.$href);

    return is_string($candidate) && str_starts_with($candidate, $root) && file_exists($candidate);
}
