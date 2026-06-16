<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Connector\PrinterConnectionConfig;
use Neocode\Laraprint\Support\PrinterType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PrinterConnectionConfigTest extends TestCase
{
    public function test_it_builds_from_array_with_explicit_keys(): void
    {
        $config = PrinterConnectionConfig::fromArray([
            'connection_type' => 'network',
            'settings' => ['ip' => '192.168.1.20', 'port' => 9100],
            'name' => 'Caisse 1',
            'is_active' => true,
        ]);

        $this->assertSame('network', $config->connectionType);
        $this->assertSame('192.168.1.20', $config->settings['ip']);
        $this->assertSame('Caisse 1', $config->name);
        $this->assertTrue($config->isActive);
        $this->assertNull($config->printerType);
    }

    public function test_it_accepts_type_alias_and_defaults(): void
    {
        $config = PrinterConnectionConfig::fromArray(['type' => 'cups']);

        $this->assertSame('cups', $config->connectionType);
        $this->assertTrue($config->isActive);
    }

    public function test_it_defaults_connection_type_to_network(): void
    {
        $config = PrinterConnectionConfig::fromArray([]);

        $this->assertSame('network', $config->connectionType);
    }

    public function test_it_falls_back_settings_to_whole_array(): void
    {
        $config = PrinterConnectionConfig::fromArray(['ip' => '10.0.0.1', 'port' => 9100]);

        $this->assertSame('10.0.0.1', $config->settings['ip']);
    }

    public function test_it_parses_printer_type_string(): void
    {
        $config = PrinterConnectionConfig::fromArray([
            'connection_type' => 'windows',
            'printer_type' => 'windows_spool_document',
        ]);

        $this->assertSame(PrinterType::WindowsSpoolDocument, $config->printerType);
    }

    public function test_it_accepts_printer_type_enum_instance(): void
    {
        $config = PrinterConnectionConfig::fromArray([
            'connection_type' => 'cups',
            'printer_type' => PrinterType::CupsSpoolDocument,
        ]);

        $this->assertSame(PrinterType::CupsSpoolDocument, $config->printerType);
    }

    public function test_it_rejects_invalid_printer_type(): void
    {
        $this->expectException(RuntimeException::class);

        PrinterConnectionConfig::fromArray([
            'connection_type' => 'cups',
            'printer_type' => 'not_a_real_type',
        ]);
    }

    public function test_it_rejects_empty_connection_type(): void
    {
        $this->expectException(RuntimeException::class);

        new PrinterConnectionConfig('');
    }

    public function test_to_array_round_trips(): void
    {
        $config = PrinterConnectionConfig::fromArray([
            'connection_type' => 'windows',
            'settings' => ['printer_name' => 'EPSON'],
            'name' => 'TM',
            'printer_type' => 'thermal_escpos_raw',
            'is_active' => false,
        ]);

        $array = $config->toArray();

        $this->assertSame('windows', $array['connection_type']);
        $this->assertSame('thermal_escpos_raw', $array['printer_type']);
        $this->assertFalse($array['is_active']);
        $this->assertSame('EPSON', $array['settings']['printer_name']);
    }
}
