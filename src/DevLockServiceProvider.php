<?php

namespace Statikbe\DevLock;

use Illuminate\Support\ServiceProvider;

class DevLockServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dev-lock.php', 'dev-lock');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/dev-lock.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'dev-lock');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'dev-lock');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/dev-lock.php' => config_path('dev-lock.php'),
        ], ['dev-lock', 'dev-lock-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/dev-lock'),
        ], ['dev-lock', 'dev-lock-views']);

        // The middleware prefers this copy over the packaged one, the same way a published view
        // overrides a package view.
        $this->publishes([
            __DIR__.'/../resources/css' => resource_path('css/vendor/dev-lock'),
        ], ['dev-lock', 'dev-lock-css']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/dev-lock'),
        ], ['dev-lock', 'dev-lock-lang']);
    }
}
