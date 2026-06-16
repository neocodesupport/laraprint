<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Feature;

use Neocode\Laraprint\Printers\PrinterRegistry;
use Neocode\Laraprint\Tests\TestbenchTestCase;

final class PrintersCommandTest extends TestbenchTestCase
{
    private function registry(): PrinterRegistry
    {
        return $this->app->make(PrinterRegistry::class);
    }

    public function test_list_shows_empty_message(): void
    {
        $this->artisan('laraprint:printers', ['action' => 'list'])
            ->expectsOutputToContain('Aucune imprimante enregistrée')
            ->assertExitCode(0);
    }

    public function test_add_registers_a_printer(): void
    {
        $this->artisan('laraprint:printers', [
            'action' => 'add',
            '--name' => 'Caisse 1',
            '--type' => 'network',
            '--setting' => ['ip=192.168.1.20', 'port=9100'],
            '--printer-type' => 'thermal_escpos_raw',
            '--default' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('printers', [
            'name' => 'Caisse 1',
            'connection_type' => 'network',
            'printer_type' => 'thermal_escpos_raw',
            'is_default' => true,
        ]);

        $printer = $this->registry()->findByName('Caisse 1');
        $this->assertNotNull($printer);
        $this->assertSame('192.168.1.20', $printer->settings['ip']);
        $this->assertSame(9100, $printer->settings['port']);
    }

    public function test_add_requires_a_name(): void
    {
        $this->artisan('laraprint:printers', ['action' => 'add', '--name' => ''])
            ->expectsQuestion('Nom de l\'imprimante', '')
            ->assertExitCode(2); // INVALID
    }

    public function test_default_command_is_exclusive(): void
    {
        $a = $this->registry()->register(['name' => 'A', 'settings' => ['ip' => '10.0.0.1'], 'is_default' => true]);
        $b = $this->registry()->register(['name' => 'B', 'settings' => ['ip' => '10.0.0.2']]);

        $this->artisan('laraprint:printers', ['action' => 'default', 'target' => (string) $b->id])
            ->assertExitCode(0);

        $this->assertTrue($b->fresh()->is_default);
        $this->assertFalse($a->fresh()->is_default);
    }

    public function test_default_by_name(): void
    {
        $p = $this->registry()->register(['name' => 'Par Nom', 'settings' => ['ip' => '10.0.0.3']]);

        $this->artisan('laraprint:printers', ['action' => 'default', 'target' => 'Par Nom'])
            ->assertExitCode(0);

        $this->assertTrue($p->fresh()->is_default);
    }

    public function test_remove_with_confirmation(): void
    {
        $p = $this->registry()->register(['name' => 'Temp', 'settings' => ['ip' => '10.0.0.1']]);

        $this->artisan('laraprint:printers', ['action' => 'remove', 'target' => (string) $p->id])
            ->expectsConfirmation('Supprimer l\'imprimante « Temp » ?', 'yes')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('printers', ['id' => $p->id]);
    }

    public function test_remove_can_be_cancelled(): void
    {
        $p = $this->registry()->register(['name' => 'Garder', 'settings' => ['ip' => '10.0.0.1']]);

        $this->artisan('laraprint:printers', ['action' => 'remove', 'target' => (string) $p->id])
            ->expectsConfirmation('Supprimer l\'imprimante « Garder » ?', 'no')
            ->assertExitCode(0);

        $this->assertDatabaseHas('printers', ['id' => $p->id]);
    }

    public function test_remove_unknown_fails(): void
    {
        $this->artisan('laraprint:printers', ['action' => 'remove', 'target' => '9999'])
            ->assertExitCode(1); // FAILURE
    }

    public function test_unknown_action_is_invalid(): void
    {
        $this->artisan('laraprint:printers', ['action' => 'bogus'])
            ->assertExitCode(2); // INVALID
    }
}
