<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Support;

/**
 * Tailles de papier pour tickets et PDF.
 */
enum PaperSize: string
{
    case Size40mm = '40mm';
    case Size44mm = '44mm';
    case Size48mm = '48mm';
    case Size58mm = '58mm';
    case Size76mm = '76mm';
    case Size80mm = '80mm';
    case A4 = 'a4';
    case A5 = 'a5';
    case Letter = 'letter';

    public function getLabel(): string
    {
        return match ($this) {
            self::Size40mm => '40mm (Ticket thermique très petit)',
            self::Size44mm => '44mm (Ticket thermique petit)',
            self::Size48mm => '48mm (Ticket thermique compact)',
            self::Size58mm => '58mm (Ticket thermique standard)',
            self::Size76mm => '76mm (Ticket thermique large)',
            self::Size80mm => '80mm (Ticket thermique extra-large)',
            self::A4 => 'A4 (210 × 297 mm)',
            self::A5 => 'A5 (148 × 210 mm)',
            self::Letter => 'Letter (216 × 279 mm)',
        };
    }

    public function getWidthInPoints(): float
    {
        return match ($this) {
            self::Size40mm => 113.39,
            self::Size44mm => 124.72,
            self::Size48mm => 136.06,
            self::Size58mm => 164.41,
            self::Size76mm => 215.43,
            self::Size80mm => 226.77,
            self::A4 => 595.28,
            self::A5 => 419.53,
            self::Letter => 612.0,
        };
    }

    public function getHeightInPoints(): float
    {
        return match ($this) {
            self::Size40mm, self::Size44mm, self::Size48mm,
            self::Size58mm, self::Size76mm, self::Size80mm => 0.0,
            self::A4 => 841.89,
            self::A5 => 595.28,
            self::Letter => 792.0,
        };
    }

    public function getWidthInMm(): float
    {
        return match ($this) {
            self::Size40mm => 40.0,
            self::Size44mm => 44.0,
            self::Size48mm => 48.0,
            self::Size58mm => 58.0,
            self::Size76mm => 76.0,
            self::Size80mm => 80.0,
            self::A4 => 210.0,
            self::A5 => 148.0,
            self::Letter => 216.0,
        };
    }

    public function getHeightInMm(): float
    {
        return match ($this) {
            self::Size40mm, self::Size44mm, self::Size48mm,
            self::Size58mm, self::Size76mm, self::Size80mm => 500.0,
            self::A4 => 297.0,
            self::A5 => 210.0,
            self::Letter => 279.0,
        };
    }

    public function isThermalSize(): bool
    {
        return match ($this) {
            self::Size40mm, self::Size44mm, self::Size48mm,
            self::Size58mm, self::Size76mm, self::Size80mm => true,
            default => false,
        };
    }
}
