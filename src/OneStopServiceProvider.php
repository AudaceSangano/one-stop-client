<?php

namespace Maico\OneStop;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Maico\OneStop\Console\CheckCommand;
use Maico\OneStop\Console\InstallCommand;
use Maico\OneStop\Http\Middleware\AllowOneStopEmbedding;
use Maico\OneStop\Http\Middleware\EndSignedOutOneStopSession;
use Maico\OneStop\Http\Middleware\KeepSignInInsideOneStop;

class OneStopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/one-stop.php', 'one-stop');
    }

    public function boot(Router $router): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/one-stop.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'one-stop');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'one-stop');

        // None of these do anything until One-Stop sign-in is enabled (or a session was started through it).
        // Added through the HTTP kernel when there is one, so the router and kernel stay in sync.
        foreach ([EndSignedOutOneStopSession::class, KeepSignInInsideOneStop::class, AllowOneStopEmbedding::class] as $middleware) {
            $kernel = $this->app->bound(HttpKernel::class) ? $this->app->make(HttpKernel::class) : null;

            $kernel instanceof FoundationHttpKernel
                ? $kernel->appendMiddlewareToGroup('web', $middleware)
                : $router->pushMiddlewareToGroup('web', $middleware);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/one-stop.php' => config_path('one-stop.php')], 'one-stop-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/one-stop')], 'one-stop-views');
            $this->commands([InstallCommand::class, CheckCommand::class]);
        }
    }
}
