<?php

namespace Ibake\TalktoReliable;

use Ibake\TalktoReliable\Console\Commands\RetryFailedTalktoMessagesCommand;
use Ibake\TalktoReliable\Console\Commands\ReportTalktoMessagesCommand;
use Ibake\TalktoReliable\Console\Commands\ReprocessTalktoDeadLettersCommand;
use Ibake\TalktoReliable\Contracts\TalktoIncomingHandlerRegistryContract;
use Ibake\TalktoReliable\Contracts\TalktoOutgoingTargetRegistryContract;
use Ibake\TalktoReliable\Pipelines\ProcessIncomingTalktoMessagePipeline;
use Ibake\TalktoReliable\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Ibake\TalktoReliable\Pipelines\SendOutgoingTalktoMessagePipeline;
use Ibake\TalktoReliable\Services\TalktoDeadLetterQueue;
use Ibake\TalktoReliable\Services\TalktoHealthChecker;
use Ibake\TalktoReliable\Services\TalktoIncomingHandlerRegistry;
use Ibake\TalktoReliable\Services\TalktoMetricsCollector;
use Ibake\TalktoReliable\Services\TalktoOutgoingTargetRegistry;
use Ibake\TalktoReliable\Services\TalktoRetryPolicy;
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
        ], 'talkto-reliable-config');
        $this->publishes([
            __DIR__.'/../config/talkto.php' => config_path('talkto.php'),
        ], 'talkto-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'talkto-reliable-migrations');
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
