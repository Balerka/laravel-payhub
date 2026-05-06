<?php

namespace Balerka\LaravelPayhub;

use Illuminate\Support\ServiceProvider;

class PayhubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payhub.php', 'payhub');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/payhub.php' => config_path('payhub.php'),
        ], 'payhub-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'payhub-migrations');

        $this->publishes([
            __DIR__.'/../resources/js' => resource_path('js/pages/payhub'),
        ], 'payhub-react');

        $this->publishes([
            __DIR__.'/../resources/js/locales/en/payhub.json' => resource_path('js/locales/en/payhub.json'),
            __DIR__.'/../resources/js/locales/ru/payhub.json' => resource_path('js/locales/ru/payhub.json'),
        ], 'payhub-locales');
    }
}
