<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Mrezdev\LaravelTalkto\LaravelTalktoServiceProvider;

test('english panel translations are namespaced and publishable', function (): void {
    $translationFile = __DIR__.'/../../lang/en/panel.php';
    $translations = require $translationFile;

    expect($translationFile)->toBeFile()
        ->and($translations)->toBeArray()
        ->and($translations['title'])->toBe('Talkto Panel')
        ->and(Lang::has('talkto::panel.title'))->toBeTrue()
        ->and(__('talkto::panel.title'))->toBe('Talkto Panel');

    foreach (['laravel-talkto-translations', 'talkto-translations'] as $tag) {
        $paths = ServiceProvider::pathsToPublish(LaravelTalktoServiceProvider::class, $tag);
        $source = str_replace('\\', '/', (string) array_key_first($paths));
        $destination = str_replace('\\', '/', (string) reset($paths));

        expect($paths)->not->toBeEmpty()
            ->and($source)->toEndWith('lang')
            ->and($destination)->toEndWith('lang/vendor/talkto')
            ->and(Artisan::call('vendor:publish', [
                '--tag' => $tag,
                '--force' => true,
            ]))->toBe(0);
    }
});

test('panel partials resolve package translation keys', function (): void {
    View::replaceNamespace('talkto', __DIR__.'/../../resources/views');

    $html = view('talkto::panel.partials.empty-state')->render();

    expect($html)->toContain('No records')
        ->and($html)->toContain('There is nothing to show yet.')
        ->and($html)->not->toContain('talkto::panel.');
});

test('panel action translations include executor result messages', function (): void {
    $translations = require __DIR__.'/../../lang/en/panel.php';

    expect($translations['actions']['retry_disabled'])->toBe('Panel retry action is disabled.')
        ->and($translations['actions']['unsupported_direction'])->toBe('Unsupported message direction.')
        ->and($translations['actions']['message_not_retryable'])->toBe('Message is not retryable.')
        ->and($translations['actions']['retry_dispatched'])->toBe('Retry job dispatched.')
        ->and($translations['actions']['dead_letter_reprocess_dispatched'])->toBe('Dead letter reprocess job dispatched.')
        ->and(__('talkto::panel.actions.retry_dispatched'))->toBe('Retry job dispatched.')
        ->and(__('talkto::panel.actions.dead_letter_reprocess_dispatched'))->toBe('Dead letter reprocess job dispatched.');
});
