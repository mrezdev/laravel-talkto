<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\VendorPublishCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;

beforeEach(function (): void {
    talktoMigrationBadgeResetPublishing();
    talktoMigrationBadgeSetVendorPublishDateUpdates(true);
    Carbon::setTestNow();
});

afterEach(function (): void {
    Carbon::setTestNow();
    talktoMigrationBadgeSetVendorPublishDateUpdates(true);
    talktoMigrationBadgeResetPublishing();
    talktoMigrationBadgeCleanTempPaths();
});

test('migration publishing tags are registered through Laravel publishable migration paths', function (): void {
    $databasePath = talktoMigrationBadgeTempDatabasePath();

    talktoMigrationBadgeBootProvider($databasePath, true);

    $packageMigrationPath = talktoMigrationBadgePackageMigrationPath();
    $publishableMigrationPaths = array_map(
        fn (string $path): string => talktoMigrationBadgeNormalizePath(realpath($path) ?: $path),
        ServiceProvider::publishableMigrationPaths()
    );

    expect($publishableMigrationPaths)->toContain(talktoMigrationBadgeNormalizePath($packageMigrationPath));

    foreach (['laravel-talkto-migrations', 'talkto-migrations'] as $tag) {
        $paths = ServiceProvider::pathsToPublish(LaravelTalktoServiceProvider::class, $tag);

        expect($paths)->not->toBeEmpty()
            ->and(array_map(
                fn (string $path): string => talktoMigrationBadgeNormalizePath(realpath($path) ?: $path),
                array_keys($paths)
            ))->toContain(talktoMigrationBadgeNormalizePath($packageMigrationPath))
            ->and(array_map(
                fn (string $path): string => talktoMigrationBadgeNormalizePath($path),
                array_values($paths)
            ))->toContain(talktoMigrationBadgeNormalizePath($databasePath.DIRECTORY_SEPARATOR.'migrations'));
    }
});

test('long migration publish tag writes current Laravel timestamps while preserving suffix order', function (): void {
    $databasePath = talktoMigrationBadgeTempDatabasePath();
    $frozenNow = Carbon::parse('2026-07-20 14:30:00');

    Carbon::setTestNow($frozenNow);
    talktoMigrationBadgeBootProvider($databasePath, true);

    expect($this->artisan('vendor:publish --tag=laravel-talkto-migrations')->run())->toBe(0);

    $sourceFiles = talktoMigrationBadgePackageMigrationFiles();
    $publishedFiles = talktoMigrationBadgePublishedMigrationFiles($databasePath);

    expect($publishedFiles)->toHaveCount(count($sourceFiles))
        ->and(array_intersect($sourceFiles, $publishedFiles))->toBeEmpty()
        ->and(array_map('talktoMigrationBadgeMigrationSuffix', $publishedFiles))
        ->toBe(array_map('talktoMigrationBadgeMigrationSuffix', $sourceFiles))
        ->and(array_map('talktoMigrationBadgeMigrationTimestamp', $publishedFiles))
        ->toBe(talktoMigrationBadgeExpectedTimestamps($frozenNow, count($sourceFiles)));
});

test('short migration publish tag has equivalent timestamp publishing behavior', function (): void {
    $databasePath = talktoMigrationBadgeTempDatabasePath();
    $frozenNow = Carbon::parse('2026-07-20 16:00:00');

    Carbon::setTestNow($frozenNow);
    talktoMigrationBadgeBootProvider($databasePath, true);

    expect($this->artisan('vendor:publish --tag=talkto-migrations')->run())->toBe(0);

    $sourceFiles = talktoMigrationBadgePackageMigrationFiles();
    $publishedFiles = talktoMigrationBadgePublishedMigrationFiles($databasePath);

    expect($publishedFiles)->toHaveCount(count($sourceFiles))
        ->and(array_map('talktoMigrationBadgeMigrationSuffix', $publishedFiles))
        ->toBe(array_map('talktoMigrationBadgeMigrationSuffix', $sourceFiles))
        ->and(array_map('talktoMigrationBadgeMigrationTimestamp', $publishedFiles))
        ->toBe(talktoMigrationBadgeExpectedTimestamps($frozenNow, count($sourceFiles)));
});

test('host disabled migration date publishing config is respected', function (): void {
    $databasePath = talktoMigrationBadgeTempDatabasePath();

    Carbon::setTestNow(Carbon::parse('2026-07-20 18:00:00'));
    talktoMigrationBadgeBootProvider($databasePath, false);

    expect(config('database.migrations.update_date_on_publish'))->toBeFalse()
        ->and(array_map(
            fn (string $path): string => talktoMigrationBadgeNormalizePath(realpath($path) ?: $path),
            ServiceProvider::publishableMigrationPaths()
        ))->not->toContain(talktoMigrationBadgeNormalizePath(talktoMigrationBadgePackageMigrationPath()))
        ->and($this->artisan('vendor:publish --tag=laravel-talkto-migrations')->run())->toBe(0)
        ->and(talktoMigrationBadgePublishedMigrationFiles($databasePath))
        ->toBe(talktoMigrationBadgePackageMigrationFiles());
});

test('existing statically published migration files are not overwritten without force', function (): void {
    $databasePath = talktoMigrationBadgeTempDatabasePath();
    $migrationDirectory = $databasePath.DIRECTORY_SEPARATOR.'migrations';
    $sourceFiles = talktoMigrationBadgePackageMigrationFiles();
    $existingFile = $migrationDirectory.DIRECTORY_SEPARATOR.$sourceFiles[0];
    $existingContent = '<?php // host-owned Talkto migration';

    (new Filesystem)->ensureDirectoryExists($migrationDirectory);
    file_put_contents($existingFile, $existingContent);

    Carbon::setTestNow(Carbon::parse('2026-07-20 19:00:00'));
    talktoMigrationBadgeBootProvider($databasePath, true);

    expect($this->artisan('vendor:publish --tag=laravel-talkto-migrations')->run())->toBe(0)
        ->and(file_get_contents($existingFile))->toBe($existingContent);
});

test('direct migration loading remains independent of publishing timestamp behavior', function (): void {
    config(['talkto.migrations.enabled' => true]);

    talktoMigrationBadgeBootProvider(talktoMigrationBadgeTempDatabasePath(), false);

    $migratorPaths = array_map(
        fn (string $path): string => talktoMigrationBadgeNormalizePath(realpath($path) ?: $path),
        app('migrator')->paths()
    );

    expect($migratorPaths)->toContain(talktoMigrationBadgeNormalizePath(talktoMigrationBadgePackageMigrationPath()))
        ->and(array_map(
            fn (string $path): string => talktoMigrationBadgeNormalizePath(realpath($path) ?: $path),
            ServiceProvider::publishableMigrationPaths()
        ))->not->toContain(talktoMigrationBadgeNormalizePath(talktoMigrationBadgePackageMigrationPath()));
});

test('readme badge block contains only essential valid badge links', function (): void {
    $readme = file_get_contents(__DIR__.'/../../README.md');
    $badgeBlock = implode("\n", array_slice(explode("\n", $readme), 0, 4));

    preg_match_all(
        '/\[!\[(?<label>[^\]]+)\]\((?<image>https:\/\/img\.shields\.io\/[^)]+)\)\]\((?<target>https:\/\/[^)]+)\)/',
        $badgeBlock,
        $matches,
        PREG_SET_ORDER
    );

    $badges = array_map(fn (array $match): array => [
        'label' => $match['label'],
        'image' => $match['image'],
        'target' => $match['target'],
    ], $matches);

    expect($badges)->toBe([
        [
            'label' => 'Latest Version on Packagist',
            'image' => 'https://img.shields.io/packagist/v/mrezdev/laravel-talkto.svg?style=flat-square',
            'target' => 'https://packagist.org/packages/mrezdev/laravel-talkto',
        ],
        [
            'label' => 'Tests',
            'image' => 'https://img.shields.io/github/actions/workflow/status/mrezdev/laravel-talkto/tests.yml?branch=main&label=tests&style=flat-square',
            'target' => 'https://github.com/mrezdev/laravel-talkto/actions/workflows/tests.yml',
        ],
        [
            'label' => 'PHP Version',
            'image' => 'https://img.shields.io/packagist/php-v/mrezdev/laravel-talkto.svg?style=flat-square',
            'target' => 'https://packagist.org/packages/mrezdev/laravel-talkto',
        ],
        [
            'label' => 'License',
            'image' => 'https://img.shields.io/packagist/l/mrezdev/laravel-talkto.svg?style=flat-square',
            'target' => 'https://packagist.org/packages/mrezdev/laravel-talkto',
        ],
    ]);

    foreach ($badges as $badge) {
        expect(filter_var($badge['image'], FILTER_VALIDATE_URL))->not->toBeFalse()
            ->and(filter_var($badge['target'], FILTER_VALIDATE_URL))->not->toBeFalse();
    }

    foreach (['Total Downloads', 'GitHub Release', 'GitHub Stars', 'GitHub Issues', 'GitHub Pull Requests', 'Last Commit'] as $removedLabel) {
        expect($badgeBlock)->not->toContain($removedLabel);
    }
});

function talktoMigrationBadgeBootProvider(string $databasePath, bool $updateDatesOnPublish): void
{
    app()->useDatabasePath($databasePath);
    config(['database.migrations.update_date_on_publish' => $updateDatesOnPublish]);

    talktoMigrationBadgeResetPublishing();

    app()->getProvider(LaravelTalktoServiceProvider::class)->boot();
}

function talktoMigrationBadgePackageMigrationPath(): string
{
    $path = __DIR__.'/../../database/migrations';

    return realpath($path) ?: $path;
}

/**
 * @return list<string>
 */
function talktoMigrationBadgePackageMigrationFiles(): array
{
    $files = array_values(array_filter(
        scandir(talktoMigrationBadgePackageMigrationPath()) ?: [],
        fn (string $file): bool => str_ends_with($file, '.php')
    ));

    sort($files);

    return $files;
}

/**
 * @return list<string>
 */
function talktoMigrationBadgePublishedMigrationFiles(string $databasePath): array
{
    $migrationDirectory = $databasePath.DIRECTORY_SEPARATOR.'migrations';

    if (! is_dir($migrationDirectory)) {
        return [];
    }

    $files = array_values(array_filter(
        scandir($migrationDirectory) ?: [],
        fn (string $file): bool => str_ends_with($file, '.php')
    ));

    sort($files);

    return $files;
}

function talktoMigrationBadgeMigrationSuffix(string $filename): string
{
    return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $filename) ?? $filename;
}

function talktoMigrationBadgeMigrationTimestamp(string $filename): string
{
    return substr($filename, 0, 17);
}

/**
 * @return list<string>
 */
function talktoMigrationBadgeExpectedTimestamps(Carbon $publishedAt, int $count): array
{
    $timestamps = [];

    foreach (range(1, $count) as $seconds) {
        $timestamps[] = $publishedAt->copy()->addSeconds($seconds)->format('Y_m_d_His');
    }

    return $timestamps;
}

function talktoMigrationBadgeTempDatabasePath(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'talkto-migration-publish-'.bin2hex(random_bytes(8));

    $GLOBALS['talktoMigrationBadgeTempPaths'][] = $path;

    return $path;
}

function talktoMigrationBadgeCleanTempPaths(): void
{
    $filesystem = new Filesystem;

    foreach ($GLOBALS['talktoMigrationBadgeTempPaths'] ?? [] as $path) {
        if (is_string($path) && is_dir($path)) {
            $filesystem->deleteDirectory($path);
        }
    }

    $GLOBALS['talktoMigrationBadgeTempPaths'] = [];
}

function talktoMigrationBadgeResetPublishing(): void
{
    $provider = LaravelTalktoServiceProvider::class;
    $migrationPath = talktoMigrationBadgeNormalizePath(talktoMigrationBadgePackageMigrationPath());

    $publishes = talktoMigrationBadgeServiceProviderProperty('publishes')->getValue();
    unset($publishes[$provider]);
    talktoMigrationBadgeServiceProviderProperty('publishes')->setValue($publishes);

    $publishGroups = talktoMigrationBadgeServiceProviderProperty('publishGroups')->getValue();

    foreach ([
        'laravel-talkto-config',
        'talkto-config',
        'laravel-talkto-migrations',
        'talkto-migrations',
        'talkto-panel-views',
        'laravel-talkto-translations',
        'talkto-translations',
    ] as $tag) {
        unset($publishGroups[$tag]);
    }

    talktoMigrationBadgeServiceProviderProperty('publishGroups')->setValue($publishGroups);

    $publishableMigrationPaths = array_values(array_filter(
        talktoMigrationBadgeServiceProviderProperty('publishableMigrationPaths')->getValue(),
        fn (string $path): bool => talktoMigrationBadgeNormalizePath(realpath($path) ?: $path) !== $migrationPath
    ));

    talktoMigrationBadgeServiceProviderProperty('publishableMigrationPaths')->setValue($publishableMigrationPaths);
}

function talktoMigrationBadgeServiceProviderProperty(string $property): ReflectionProperty
{
    $reflection = new ReflectionClass(ServiceProvider::class);
    $reflectionProperty = $reflection->getProperty($property);
    $reflectionProperty->setAccessible(true);

    return $reflectionProperty;
}

function talktoMigrationBadgeSetVendorPublishDateUpdates(bool $enabled): void
{
    $reflection = new ReflectionClass(VendorPublishCommand::class);
    $property = $reflection->getProperty('updateMigrationDates');
    $property->setAccessible(true);
    $property->setValue($enabled);
}

function talktoMigrationBadgeNormalizePath(string $path): string
{
    return str_replace('\\', '/', $path);
}
