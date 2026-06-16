<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Connector\PrinterConnectionConfig;
use Neocode\Laraprint\Laraprint;
use Neocode\Laraprint\Support\PrinterType;
use Neocode\Laraprint\Support\ReceiptConfig;
use Neocode\Laraprint\Thermal\ReceiptData;
use PHPUnit\Framework\TestCase;

final class PrinterTypeAndLaraprintTest extends TestCase
{
    public function test_printer_type_backed_values(): void
    {
        $this->assertSame('thermal_escpos_raw', PrinterType::ThermalEscposRaw->value);
        $this->assertSame('windows_spool_document', PrinterType::WindowsSpoolDocument->value);
        $this->assertSame('cups_spool_document', PrinterType::CupsSpoolDocument->value);
        $this->assertSame(PrinterType::ThermalEscposRaw, PrinterType::tryFrom('thermal_escpos_raw'));
        $this->assertNull(PrinterType::tryFrom('nope'));
    }

    public function test_laraprint_pure_factories_return_dtos(): void
    {
        $this->assertInstanceOf(
            PrinterConnectionConfig::class,
            Laraprint::connectionConfig(['connection_type' => 'network', 'settings' => ['ip' => '1.2.3.4']])
        );

        $this->assertInstanceOf(
            ReceiptConfig::class,
            Laraprint::receiptConfig(['company' => ['name' => 'Acme']])
        );

        $this->assertInstanceOf(
            ReceiptData::class,
            Laraprint::receiptData(['sale_number' => 'S1'])
        );
    }
}
