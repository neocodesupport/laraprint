<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Support\PaperSize;
use PHPUnit\Framework\TestCase;

final class PaperSizeTest extends TestCase
{
    public function test_thermal_sizes_are_flagged(): void
    {
        $this->assertTrue(PaperSize::Size58mm->isThermalSize());
        $this->assertTrue(PaperSize::Size80mm->isThermalSize());
        $this->assertFalse(PaperSize::A4->isThermalSize());
        $this->assertFalse(PaperSize::Letter->isThermalSize());
    }

    public function test_widths_in_mm(): void
    {
        $this->assertSame(58.0, PaperSize::Size58mm->getWidthInMm());
        $this->assertSame(210.0, PaperSize::A4->getWidthInMm());
    }

    public function test_thermal_height_is_continuous_roll_value(): void
    {
        $this->assertSame(500.0, PaperSize::Size58mm->getHeightInMm());
        $this->assertSame(0.0, PaperSize::Size58mm->getHeightInPoints());
        $this->assertSame(297.0, PaperSize::A4->getHeightInMm());
    }

    public function test_labels_are_non_empty(): void
    {
        foreach (PaperSize::cases() as $case) {
            $this->assertNotSame('', $case->getLabel());
        }
    }

    public function test_backed_values(): void
    {
        $this->assertSame('58mm', PaperSize::Size58mm->value);
        $this->assertSame('a4', PaperSize::A4->value);
    }
}
