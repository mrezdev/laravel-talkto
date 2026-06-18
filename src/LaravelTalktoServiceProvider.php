<?php

namespace Mrezdev\LaravelTalkto;

use Mrezdev\LaravelTalkto\Console\Commands\RetryFailedTalktoMessagesCommand;
use Mrezdev\LaravelTalkto\Console\Commands\ReportTalktoMessagesCommand;
use Mrezdev\LaravelTalkto\Console\Commands\ReprocessTalktoDeadLettersCommand;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Pipelines\ProcessIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Pipelines\SendOutgoingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\TalktoDeadLetterQueue;
use Mrezdev\LaravelTalkto\Services\TalktoHealthChecker;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingHandlerRegistry;
use Mrezdev\LaravelTalkto\Services\TalktoMetricsCollector;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingTargetRegistry;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Illuminate\Support\ServiceProvider;

class LaravelTalktoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/talkto.php', 'talkto');
        $this->app->singleton(TalktoIncomingHandlerRegistry::class);
        $this->app->alias(TalktoIncomingHandlerRegistry::class, TalktoIncomingHandlerRegistryContract::class);
        $this->app->singleton(TalktoOutgoingTargetRegistry::class);
        $this->app->alias(TalktoOutgoingTargetRegistry::class, TalktoOutgoingTargetRegistryContract::class);
        $this->app->bind(TalktoRetryPolicy::class);
        $this->app->bind(TalktoDeadLetterQueue::class);
        $this->app->bind(TalktoMetricsCollector::class);
        $this->app->bind(TalktoHealthChecker::class);
        $this->app->bind(ReceiveIncomingTalktoMessagePipeline::class);
        $this->app->bind(ProcessIncomingTalktoMessagePipeline::class);
        $this->app->bind(SendOutgoingTalktoMessagePipeline::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RetryFailedTalktoMessagesCommand::class,
                ReprocessTalktoDeadLettersCommand::class,
                ReportTalktoMessagesCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../config/talkto.php' => config_path('talkto.php'),
        ], 'laravel-talkto-config');
        $this->publishes([
            __DIR__.'/../config/talkto.php' => config_path('talkto.php'),
        ], 'talkto-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'laravel-talkto-migrations');
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'talkto-migrations');

        if (config('talkto.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if (config('talkto.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }
    }
}
