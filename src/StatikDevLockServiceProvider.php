<?php

namespace Statikbe\StatikDevLock;

use Illuminate\Support\ServiceProvider;

class StatikDevLockServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/statik-dev-lock.php', 'statik-dev-lock');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/statik-dev-lock.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statik-dev-lock');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'statik-dev-lock');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/statik-dev-lock.php' => config_path('statik-dev-lock.php'),
        ], ['statik-dev-lock', 'statik-dev-lock-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/statik-dev-lock'),
        ], ['statik-dev-lock', 'statik-dev-lock-views']);

        // The middleware prefers this copy over the packaged one, the same way a published view
        // overrides a package view.
        $this->publishes([
            __DIR__.'/../resources/css' => resource_path('css/vendor/statik-dev-lock'),
        ], ['statik-dev-lock', 'statik-dev-lock-css']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/statik-dev-lock'),
        ], ['statik-dev-lock', 'statik-dev-lock-lang']);
    }
}
