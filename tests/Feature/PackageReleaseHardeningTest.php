<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Mrezdev\LaravelTalkto\Console\Commands\ReportTalktoMessagesCommand;
use Mrezdev\LaravelTalkto\Console\Commands\ReprocessTalktoDeadLettersCommand;
use Mrezdev\LaravelTalkto\Console\Commands\RetryFailedTalktoMessagesCommand;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;
use Mrezdev\LaravelTalkto\Pipelines\ProcessIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Pipelines\SendOutgoingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoHealthChecker;
use Mrezdev\LaravelTalkto\Services\TalktoMetricsCollector;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;

test('composer metadata is package release friendly', function (): void {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

    expect($composer['name'])->toBe('mrezdev/laravel-talkto')
        ->and($composer['description'])->toBeString()->not->toBe('')
        ->and($composer['type'])->toBe('library')
        ->and($composer['license'])->toBeString()->not->toBe('')
        ->and($composer['autoload']['psr-4']['Mrezdev\\LaravelTalkto\\'])->toBe('src/')
        ->and($composer['autoload-dev']['psr-4']['Mrezdev\\LaravelTalkto\\Tests\\'])->toBe('tests/')
        ->and($composer['extra']['laravel']['providers'])->toContain(LaravelTalktoServiceProvider::class)
        ->and($composer['scripts']['test'])->toBe('pest');
});

test('service provider exposes config and migration publish tags', function (): void {
    $configTags = [
        'laravel-talkto-config',
        'talkto-config',
    ];
    $migrationTags = [
        'laravel-talkto-migrations',
        'talkto-migrations',
    ];

    foreach ($configTags as $tag) {
        $paths = ServiceProvider::pathsToPublish(LaravelTalktoServiceProvider::class, $tag);
        $source = str_replace('\\', '/', (string) array_key_first($paths));

        expect($paths)->not->toBeEmpty()
            ->and($source)->toEndWith('config/talkto.php');
    }

    foreach ($migrationTags as $tag) {
        $paths = ServiceProvider::pathsToPublish(LaravelTalktoServiceProvider::class, $tag);
        $source = str_replace('\\', '/', (string) array_key_first($paths));

        expect($paths)->not->toBeEmpty()
            ->and($source)->toEndWith('database/migrations');
    }
});

test('package artisan commands are registered', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('talkto:retry-failed')
        ->and($commands['talkto:retry-failed'])->toBeInstanceOf(RetryFailedTalktoMessagesCommand::class)
        ->and($commands)->toHaveKey('talkto:dlq-reprocess')
        ->and($commands['talkto:dlq-reprocess'])->toBeInstanceOf(ReprocessTalktoDeadLettersCommand::class)
        ->and($commands)->toHaveKey('talkto:report')
        ->and($commands['talkto:report'])->toBeInstanceOf(ReportTalktoMessagesCommand::class);
});

test('release extension services resolve from the container', function (): void {
    expect(app(TalktoIncomingHandlerRegistryContract::class))->toBeInstanceOf(TalktoIncomingHandlerRegistryContract::class)
        ->and(app(TalktoOutgoingTargetRegistryContract::class))->toBeInstanceOf(TalktoOutgoingTargetRegistryContract::class)
        ->and(app(TalktoRetryPolicy::class))->toBeInstanceOf(TalktoRetryPolicy::class)
        ->and(app(TalktoDeadLetterQueue::class))->toBeInstanceOf(TalktoDeadLetterQueue::class)
        ->and(app(TalktoMetricsCollector::class))->toBeInstanceOf(TalktoMetricsCollector::class)
        ->and(app(TalktoHealthChecker::class))->toBeInstanceOf(TalktoHealthChecker::class)
        ->and(app(ReceiveIncomingTalktoMessagePipeline::class))->toBeInstanceOf(ReceiveIncomingTalktoMessagePipeline::class)
        ->and(app(ProcessIncomingTalktoMessagePipeline::class))->toBeInstanceOf(ProcessIncomingTalktoMessagePipeline::class)
        ->and(app(SendOutgoingTalktoMessagePipeline::class))->toBeInstanceOf(SendOutgoingTalktoMessagePipeline::class);
});

test('release config contains required top level keys and safe defaults', function (): void {
    $defaults = require __DIR__.'/../../config/talkto.php';

    foreach (['incoming', 'outgoing', 'retry', 'dead_letter', 'security', 'observability'] as $key) {
        expect($defaults)->toHaveKey($key);
    }

    expect($defaults['routes']['enabled'])->toBeFalse()
        ->and($defaults['migrations']['enabled'])->toBeFalse()
        ->and($defaults['security']['signature_version'])->toBe('v1')
        ->and($defaults['security']['accept_versions'])->toBe(['v1', 'v2'])
        ->and($defaults['observability']['enabled'])->toBeTrue();
});
