<?php

namespace Ibake\TalktoReliable;

use Ibake\TalktoReliable\Console\Commands\RetryFailedTalktoMessagesCommand;
use Ibake\TalktoReliable\Console\Commands\ReportTalktoMessagesCommand;
use Ibake\TalktoReliable\Console\Commands\ReprocessTalktoDeadLettersCommand;
use Ibake\TalktoReliable\Contracts\TalktoIncomingHandlerRegistryContract;
use Ibake\TalktoReliable\Contracts\TalktoOutgoingTargetRegistryContract;
use Ibake\TalktoReliable\Services\TalktoIncomingHandlerRegistry;
use Ibake\TalktoReliable\Services\TalktoHealthChecker;
use Ibake\TalktoReliable\Services\TalktoMetricsCollector;
use Ibake\TalktoReliable\Services\TalktoOutgoingTargetRegistry;
use Illuminate\Support\ServiceProvider;

class TalktoReliableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/talkto.php', 'talkto');
        $this->app->singleton(TalktoIncomingHandlerRegistry::class);
        $this->app->alias(TalktoIncomingHandlerRegistry::class, TalktoIncomingHandlerRegistryContract::class);
        $this->app->singleton(TalktoOutgoingTargetRegistry::class);
        $this->app->alias(TalktoOutgoingTargetRegistry::class, TalktoOutgoingTargetRegistryContract::class);
        $this->app->bind(TalktoMetricsCollector::class);
        $this->app->bind(TalktoHealthChecker::class);
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
        ], 'talkto-reliable-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'talkto-reliable-migrations');

        if (config('talkto.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if (config('talkto.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }
    }
}
