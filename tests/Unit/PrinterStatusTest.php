<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Support\PrinterStatus;
use PHPUnit\Framework\TestCase;

final class PrinterStatusTest extends TestCase
{
    public function test_ready_printer(): void
    {
        $status = PrinterStatus::decode(0x12, 0x12, 0x12, 0x12);

        $this->assertTrue($status->online);
        $this->assertFalse($status->paperOut);
        $this->assertFalse($status->coverOpen);
        $this->assertTrue($status->isReady());
    }

    public function test_offline_printer(): void
    {
        // s1 bit 0x08 mis => hors ligne.
        $status = PrinterStatus::decode(0x1A, 0x12, 0x12, 0x12);

        $this->assertFalse($status->online);
        $this->assertFalse($status->isReady());
    }

    public function test_paper_out(): void
    {
        // s4 bits 0x60 mis => papier épuisé.
        $status = PrinterStatus::decode(0x12, 0x12, 0x12, 0x72);

        $this->assertTrue($status->paperOut);
        $this->assertFalse($status->isReady());
    }

    public function test_cover_open(): void
    {
        // s2 bit 0x04 mis => capot ouvert.
        $status = PrinterStatus::decode(0x12, 0x16, 0x12, 0x12);

        $this->assertTrue($status->coverOpen);
        $this->assertFalse($status->isReady());
    }

    public function test_unknown_when_no_response(): void
    {
        $status = PrinterStatus::decode(null, null, null, null);

        $this->assertNull($status->online);
        $this->assertNull($status->paperOut);
        $this->assertFalse($status->isReady());
        $this->assertSame(['s1' => null, 's2' => null, 's3' => null, 's4' => null], $status->raw);
    }
}
