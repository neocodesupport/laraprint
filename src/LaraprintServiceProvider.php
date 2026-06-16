<?php

declare(strict_types=1);

namespace Neocode\Laraprint;

use Illuminate\Support\ServiceProvider;
use Neocode\Laraprint\Printers\PrinterRegistry;

class LaraprintServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laraprint.php',
            'laraprint'
        );

        $this->app->singleton(PrinterRegistry::class, fn () => new PrinterRegistry);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/laraprint.php' => config_path('laraprint.php'),
            ], 'laraprint-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'laraprint-migrations');
        }
    }
}
