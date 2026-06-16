<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * Base de test fournissant une base SQLite en mémoire avec le schéma du SDK,
 * sans dépendre d'une application Laravel complète (Eloquent via Capsule).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capsule = new Capsule;
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->createSchema();
    }

    private function createSchema(): void
    {
        $schema = $this->capsule->schema();

        $schema->create('workstations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname')->nullable()->unique();
            $table->string('ip_address')->unique();
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('printers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workstation_id')->nullable();
            $table->string('name');
            $table->string('connection_type')->default('network');
            $table->string('printer_type')->nullable();
            $table->string('model')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        $schema->create('printer_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('printer_id');
            $table->string('username');
            $table->text('password');
            $table->string('domain')->nullable();
            $table->timestamps();
        });
    }
}
