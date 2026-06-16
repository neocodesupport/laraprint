<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Laraprint;
use Neocode\Laraprint\Testing\PrintRecorder;
use PHPUnit\Framework\TestCase;

final class FakePrintingTest extends TestCase
{
    private array $config = ['connection_type' => 'network', 'settings' => ['ip' => '192.0.2.10']];

    protected function tearDown(): void
    {
        PrintRecorder::instance()->disable();
        parent::tearDown();
    }

    public function test_fake_captures_text_without_connecting(): void
    {
        $recorder = Laraprint::fake();

        Laraprint::printer($this->config)->printText('HELLO-FAKE')->cut()->close();

        $recorder->assertPrinted()
            ->assertPrintedTimes(1)
            ->assertPrintedContains('HELLO-FAKE');
    }

    public function test_fake_captures_receipt_company_name(): void
    {
        $recorder = Laraprint::fake();

        Laraprint::thermalPrinter($this->config, ['company' => ['name' => 'ACME'], 'qr_code' => ['enabled' => false]])
            ->printReceipt(['sale_number' => 'S1', 'items' => [], 'subtotal' => 0, 'total_amount' => 0, 'payments' => []]);

        $recorder->assertPrintedContains('ACME');
    }

    public function test_assert_nothing_printed(): void
    {
        Laraprint::fake()->assertNothingPrinted();
    }

    public function test_open_cash_drawer_is_captured(): void
    {
        $recorder = Laraprint::fake();

        Laraprint::openCashDrawer($this->config);

        // La commande ESC/POS d'impulsion tiroir commence par ESC p (0x1B 0x70).
        $recorder->assertPrintedContains("\x1Bp");
    }

    public function test_status_is_unknown_under_fake(): void
    {
        Laraprint::fake();

        $status = Laraprint::printerStatus($this->config);

        $this->assertNull($status->online);
        $this->assertFalse($status->isReady());
    }

    public function test_disabling_fake_stops_capture(): void
    {
        Laraprint::fake();
        PrintRecorder::instance()->disable();

        $this->assertFalse(PrintRecorder::isFaking());
    }
}
