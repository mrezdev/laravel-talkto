<?php

namespace Mrezdev\LaravelTalkto;

use Mrezdev\LaravelTalkto\Console\Commands\MakeTalktoIncomingCommand;
use Mrezdev\LaravelTalkto\Console\Commands\MakeTalktoIntegrationCommand;
use Mrezdev\LaravelTalkto\Console\Commands\MakeTalktoOutgoingCommand;
use Mrezdev\LaravelTalkto\Console\Commands\RetryFailedTalktoMessagesCommand;
use Mrezdev\LaravelTalkto\Console\Commands\ReportTalktoMessagesCommand;
use Mrezdev\LaravelTalkto\Console\Commands\ReprocessTalktoDeadLettersCommand;
use Mrezdev\LaravelTalkto\Console\Commands\SecurityAuditTalktoCommand;
use Mrezdev\LaravelTalkto\Console\Commands\TraceTalktoMessageCommand;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
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
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackReceiver;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackSender;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Services\TalktoSecurityAuditor;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelActionExecutor;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelActiveHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionRegistry;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoScaffoldNameResolver;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoScaffoldPathResolver;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoScaffoldWriter;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoStubRenderer;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoIncomingScaffolder;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoOutgoingScaffolder;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelJsonPresenter;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;
use Illuminate\Support\Facades\Route;
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
        $this->app->singleton(TalktoSecurityRedactor::class);
        $this->app->bind(TalktoSecurityAuditor::class);
        $this->app->bind(TalktoDeadLetterQueue::class);
        $this->app->bind(TalktoMetricsCollector::class);
        $this->app->bind(TalktoHealthChecker::class);
        $this->app->bind(TalktoPanelActionExecutor::class);
        $this->app->bind(TalktoPanelActiveHealthChecker::class);
        $this->app->bind(TalktoPanelMessageQuery::class);
        $this->app->bind(TalktoPanelConnectionRegistry::class);
        $this->app->bind(TalktoPanelConnectionHealthChecker::class);
        $this->app->bind(TalktoPanelJsonPresenter::class);
        $this->app->bind(ResultCallbackSenderContract::class, TalktoResultCallbackSender::class);
        $this->app->bind(ResultCallbackReceiverContract::class, TalktoResultCallbackReceiver::class);
        $this->app->bind(ReceiveIncomingTalktoMessagePipeline::class);
        $this->app->bind(ProcessIncomingTalktoMessagePipeline::class);
        $this->app->bind(SendOutgoingTalktoMessagePipeline::class);
        $this->app->bind(TalktoScaffoldNameResolver::class);
        $this->app->bind(TalktoScaffoldPathResolver::class);
        $this->app->bind(TalktoStubRenderer::class);
        $this->app->bind(TalktoScaffoldWriter::class);
        $this->app->bind(TalktoIncomingScaffolder::class);
        $this->app->bind(TalktoOutgoingScaffolder::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'talkto');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeTalktoIncomingCommand::class,
                MakeTalktoIntegrationCommand::class,
                MakeTalktoOutgoingCommand::class,
                RetryFailedTalktoMessagesCommand::class,
                ReprocessTalktoDeadLettersCommand::class,
                ReportTalktoMessagesCommand::class,
                TraceTalktoMessageCommand::class,
                SecurityAuditTalktoCommand::class,
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

        $this->publishes([
            __DIR__.'/../resources/views/panel' => resource_path('views/vendor/talkto/panel'),
        ], 'talkto-panel-views');

        if (config('talkto.migrations.enabled', false)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if (config('talkto.routes.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        if (config('talkto.panel.enabled', false) === true) {
            $this->loadPanelRoutes();
        }
    }

    private function loadPanelRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $prefix = config('talkto.panel.route.prefix', 'talkto');
        $domain = config('talkto.panel.route.domain');
        $middleware = config('talkto.panel.route.middleware', ['web', 'auth']);
        $name = config('talkto.panel.route.name', 'talkto.panel.');

        $route = Route::prefix(is_string($prefix) ? $prefix : 'talkto')
            ->as(is_string($name) ? $name : 'talkto.panel.')
            ->middleware(is_array($middleware) ? $middleware : [$middleware]);

        if (is_string($domain) && $domain !== '') {
            $route->domain($domain);
        }

        $route->group(__DIR__.'/../routes/panel.php');
    }
}
