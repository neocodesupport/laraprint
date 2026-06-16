<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests;

use Neocode\Laraprint\LaraprintServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base de test « application complète » via Testbench : démarre une app Laravel
 * minimale avec le ServiceProvider du package et une base SQLite en mémoire.
 *
 * Utilisée pour tester ce qui dépend du conteneur Laravel (commande Artisan,
 * enregistrement du ServiceProvider).
 */
abstract class TestbenchTestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaraprintServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
