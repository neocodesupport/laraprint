<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Feature;

use InvalidArgumentException;
use Neocode\Laraprint\Models\Printer;
use Neocode\Laraprint\Printers\PrinterRegistry;
use Neocode\Laraprint\Support\PrinterType;
use Neocode\Laraprint\Tests\DatabaseTestCase;
use RuntimeException;

final class PrinterRegistryTest extends DatabaseTestCase
{
    private function registry(): PrinterRegistry
    {
        return new PrinterRegistry;
    }

    public function test_it_registers_a_new_printer(): void
    {
        $printer = $this->registry()->register([
            'name' => 'Caisse 1',
            'connection_type' => 'network',
            'printer_type' => 'thermal_escpos_raw',
            'settings' => ['ip' => '192.168.1.20', 'port' => 9100],
        ]);

        $this->assertInstanceOf(Printer::class, $printer);
        $this->assertNotNull($printer->id);
        $this->assertSame('Caisse 1', $printer->name);
        $this->assertSame('thermal_escpos_raw', $printer->printer_type);
        $this->assertFalse($printer->is_default);
        $this->assertCount(1, $this->registry()->all());
    }

    public function test_it_requires_a_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry()->register(['connection_type' => 'network']);
    }

    public function test_it_rejects_invalid_printer_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry()->register(['name' => 'X', 'printer_type' => 'bogus']);
    }

    public function test_it_accepts_printer_type_enum(): void
    {
        $printer = $this->registry()->register([
            'name' => 'Doc',
            'connection_type' => 'windows',
            'printer_type' => PrinterType::WindowsSpoolDocument,
            'settings' => ['printer_name' => 'HP'],
        ]);

        $this->assertSame('windows_spool_document', $printer->printer_type);
    }

    public function test_registering_as_default_sets_it_default(): void
    {
        $printer = $this->registry()->register([
            'name' => 'Default One',
            'settings' => ['ip' => '10.0.0.1'],
            'is_default' => true,
        ]);

        $this->assertTrue($printer->is_default);
        $this->assertSame($printer->id, $this->registry()->default()?->id);
    }

    public function test_set_default_is_exclusive(): void
    {
        $a = $this->registry()->register(['name' => 'A', 'settings' => ['ip' => '10.0.0.1'], 'is_default' => true]);
        $b = $this->registry()->register(['name' => 'B', 'settings' => ['ip' => '10.0.0.2']]);

        $this->registry()->setDefault($b);

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
        $this->assertSame($b->id, $this->registry()->default()?->id);
        $this->assertCount(1, Printer::query()->where('is_default', true)->get());
    }

    public function test_default_is_scoped_per_workstation(): void
    {
        $reg = $this->registry();
        $w1 = $reg->register(['name' => 'W1', 'workstation_id' => 1, 'settings' => ['ip' => '10.0.0.1'], 'is_default' => true]);
        $w2 = $reg->register(['name' => 'W2', 'workstation_id' => 2, 'settings' => ['ip' => '10.0.0.2'], 'is_default' => true]);

        // Définir le défaut du poste 2 ne touche pas le défaut du poste 1.
        $this->assertTrue($w1->fresh()->is_default);
        $this->assertTrue($w2->fresh()->is_default);
        $this->assertSame($w1->id, $reg->default(1)?->id);
        $this->assertSame($w2->id, $reg->default(2)?->id);
    }

    public function test_resolve_by_id_name_and_default(): void
    {
        $reg = $this->registry();
        $a = $reg->register(['name' => 'By Name', 'settings' => ['ip' => '10.0.0.1']]);
        $reg->register(['name' => 'The Default', 'settings' => ['ip' => '10.0.0.2'], 'is_default' => true]);

        $this->assertSame($a->id, $reg->resolve($a->id)->id);
        $this->assertSame($a->id, $reg->resolve('By Name')->id);
        $this->assertSame('The Default', $reg->resolve(null)->name);
    }

    public function test_resolve_throws_when_no_default(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry()->resolve(null);
    }

    public function test_resolve_throws_for_unknown_printer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry()->resolve('nope');
    }

    public function test_resolve_rejects_inactive_printer(): void
    {
        $printer = $this->registry()->register([
            'name' => 'Off',
            'settings' => ['ip' => '10.0.0.1'],
            'is_active' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->registry()->resolve($printer->id);
    }

    public function test_connection_config_is_sdk_ready(): void
    {
        $printer = $this->registry()->register([
            'name' => 'Caisse',
            'connection_type' => 'network',
            'printer_type' => 'thermal_escpos_raw',
            'settings' => ['ip' => '192.168.1.50', 'port' => 9100],
        ]);

        $config = $this->registry()->connectionConfig($printer->id);

        $this->assertSame('network', $config['connection_type']);
        $this->assertSame('192.168.1.50', $config['settings']['ip']);
        $this->assertSame('thermal_escpos_raw', $config['printer_type']);
        $this->assertSame('Caisse', $config['name']);
        $this->assertTrue($config['is_active']);
    }

    public function test_forget_deletes_printer(): void
    {
        $printer = $this->registry()->register(['name' => 'Temp', 'settings' => ['ip' => '10.0.0.1']]);

        $this->registry()->forget($printer->id);

        $this->assertNull($this->registry()->find($printer->id));
        $this->assertCount(0, $this->registry()->all());
    }
}
