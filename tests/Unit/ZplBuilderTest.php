<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Label\ZplBuilder;
use Neocode\Laraprint\Laraprint;
use Neocode\Laraprint\Testing\PrintRecorder;
use PHPUnit\Framework\TestCase;

final class ZplBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        PrintRecorder::instance()->disable();
        parent::tearDown();
    }

    public function test_it_wraps_in_xa_xz(): void
    {
        $zpl = ZplBuilder::make()->text(50, 50, 'Hello')->toZpl();

        $this->assertStringStartsWith('^XA', $zpl);
        $this->assertStringEndsWith("^XZ\n", $zpl);
        $this->assertStringContainsString('^FDHello^FS', $zpl);
        $this->assertStringContainsString('^FO50,50', $zpl);
    }

    public function test_barcode_qr_and_box(): void
    {
        $zpl = ZplBuilder::make()
            ->barcode(10, 20, '12345')
            ->qr(30, 40, 'https://example.com')
            ->box(0, 0, 100, 200, 4)
            ->toZpl();

        $this->assertStringContainsString('^BCN,100,Y,N,N^FD12345^FS', $zpl);
        $this->assertStringContainsString('^BQN,2,5^FDLA,https://example.com^FS', $zpl);
        $this->assertStringContainsString('^GB100,200,4^FS', $zpl);
    }

    public function test_it_escapes_zpl_control_chars(): void
    {
        $zpl = ZplBuilder::make()->text(0, 0, 'A^B~C')->toZpl();

        $this->assertStringContainsString('^FDABC^FS', $zpl);
    }

    public function test_print_sends_raw_bytes_under_fake(): void
    {
        $recorder = Laraprint::fake();

        ZplBuilder::make()->text(10, 10, 'LABEL')->print(['connection_type' => 'network', 'settings' => ['ip' => '192.0.2.9']]);

        $recorder->assertPrintedTimes(1)->assertPrintedContains('^XA')->assertPrintedContains('LABEL');
    }

    public function test_send_raw_has_no_escpos_init(): void
    {
        $recorder = Laraprint::fake();

        Laraprint::sendRaw(['connection_type' => 'network', 'settings' => ['ip' => '192.0.2.9']], 'PURE-BYTES');

        // Pas d'octet d'init ESC @ (0x1B 0x40) ajouté.
        $this->assertSame('PURE-BYTES', $recorder->recorded()[0]['content']);
    }
}
