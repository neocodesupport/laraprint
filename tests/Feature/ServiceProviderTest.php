<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Neocode\Laraprint\LaraprintServiceProvider;
use Neocode\Laraprint\Printers\PrinterRegistry;
use Neocode\Laraprint\Tests\TestbenchTestCase;

final class ServiceProviderTest extends TestbenchTestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertSame('network', config('laraprint.connection_type'));
        $this->assertIsArray(config('laraprint.receipt'));
        $this->assertArrayHasKey('company', config('laraprint.receipt'));
    }

    public function test_registry_is_bound_as_singleton(): void
    {
        $first = $this->app->make(PrinterRegistry::class);
        $second = $this->app->make(PrinterRegistry::class);

        $this->assertInstanceOf(PrinterRegistry::class, $first);
        $this->assertSame($first, $second);
    }

    public function test_artisan_command_is_registered(): void
    {
        $this->assertArrayHasKey('laraprint:printers', Artisan::all());
    }

    public function test_publish_tags_are_declared(): void
    {
        $this->assertNotEmpty(
            ServiceProvider::pathsToPublish(LaraprintServiceProvider::class, 'laraprint-config')
        );
        $this->assertNotEmpty(
            ServiceProvider::pathsToPublish(LaraprintServiceProvider::class, 'laraprint-migrations')
        );
    }
}
