<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Support\ConnectionType;
use Neocode\Laraprint\Support\PrinterType;
use PHPUnit\Framework\TestCase;

final class ConnectionTypeTest extends TestCase
{
    public function test_values_cover_all_supported_types(): void
    {
        $this->assertSame(
            ['network', 'windows', 'cups', 'smb', 'usb', 'file', 'ipp'],
            ConnectionType::values(),
        );
    }

    public function test_uses_spooler(): void
    {
        $this->assertTrue(ConnectionType::Windows->usesSpooler());
        $this->assertTrue(ConnectionType::Cups->usesSpooler());
        $this->assertTrue(ConnectionType::Smb->usesSpooler());
        $this->assertFalse(ConnectionType::Network->usesSpooler());
        $this->assertFalse(ConnectionType::Usb->usesSpooler());
        $this->assertFalse(ConnectionType::File->usesSpooler());
    }

    public function test_default_printer_type(): void
    {
        $this->assertSame(PrinterType::WindowsSpoolDocument, ConnectionType::Windows->defaultPrinterType());
        $this->assertSame(PrinterType::CupsSpoolDocument, ConnectionType::Cups->defaultPrinterType());
        $this->assertSame(PrinterType::CupsSpoolDocument, ConnectionType::Smb->defaultPrinterType());
        $this->assertSame(PrinterType::ThermalEscposRaw, ConnectionType::Network->defaultPrinterType());
        $this->assertSame(PrinterType::ThermalEscposRaw, ConnectionType::File->defaultPrinterType());
    }

    public function test_infer_printer_type_from_string(): void
    {
        $this->assertSame(PrinterType::WindowsSpoolDocument, ConnectionType::inferPrinterType('windows'));
        $this->assertSame(PrinterType::CupsSpoolDocument, ConnectionType::inferPrinterType('smb'));
        $this->assertSame(PrinterType::ThermalEscposRaw, ConnectionType::inferPrinterType('network'));
        // Type inconnu => repli ESC/POS brut.
        $this->assertSame(PrinterType::ThermalEscposRaw, ConnectionType::inferPrinterType('unknown'));
    }
}
