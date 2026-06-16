<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Discovery\LocalPrinters;
use PHPUnit\Framework\TestCase;

final class LocalPrintersTest extends TestCase
{
    public function test_parses_windows_usb_lines(): void
    {
        $output = "EPSON TM-T20II Receipt|USB001\nHP LaserJet|DOT4_001\n";

        $printers = LocalPrinters::parseWindowsUsb($output);

        $this->assertCount(2, $printers);
        $this->assertSame('windows', $printers[0]['connection_type']);
        $this->assertSame('EPSON TM-T20II Receipt', $printers[0]['settings']['printer_name']);
        $this->assertSame('USB001', $printers[0]['settings']['port']);
        $this->assertSame('thermal_escpos_raw', $printers[0]['printer_type']);
        $this->assertSame('HP LaserJet', $printers[1]['name']);
    }

    public function test_windows_usb_ignores_blank_and_nameless_lines(): void
    {
        $output = "\n|USB001\n  \nValid Printer|USB002\n";

        $printers = LocalPrinters::parseWindowsUsb($output);

        $this->assertCount(1, $printers);
        $this->assertSame('Valid Printer', $printers[0]['name']);
    }

    public function test_parses_lpinfo_usb_devices(): void
    {
        $output = implode("\n", [
            'network socket',
            'direct usb://EPSON/TM-T20II?serial=583247',
            'direct hp:/usb/HP_LaserJet?serial=ABC',
            'network lpd',
        ]);

        $printers = LocalPrinters::parseLpinfo($output);

        $this->assertCount(1, $printers);
        $this->assertSame('cups', $printers[0]['connection_type']);
        $this->assertSame('usb://EPSON/TM-T20II?serial=583247', $printers[0]['settings']['device_uri']);
        $this->assertStringContainsString('EPSON', $printers[0]['name']);
    }

    public function test_lpinfo_without_usb_returns_empty(): void
    {
        $output = "network socket\nnetwork lpd\ndirect parallel:/dev/lp0\n";

        $this->assertSame([], LocalPrinters::parseLpinfo($output));
    }
}
