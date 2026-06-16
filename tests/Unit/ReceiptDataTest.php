<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use DateTimeImmutable;
use Neocode\Laraprint\Thermal\ReceiptData;
use PHPUnit\Framework\TestCase;

final class ReceiptDataTest extends TestCase
{
    public function test_it_builds_from_full_array(): void
    {
        $data = ReceiptData::fromArray([
            'sale_number' => 'SALE202501001',
            'sold_at' => '2026-01-15 10:30:00',
            'cashier_name' => 'Jean Dupont',
            'cash_register_name' => 'Caisse 1',
            'patient_name' => 'Marie Martin',
            'patient_phone' => '+225 0102030405',
            'items' => [['item_name' => 'Paracétamol', 'quantity' => 2, 'unit_price' => 500, 'total_amount' => 1000]],
            'subtotal' => 1000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 1000,
            'payments' => [['type' => 'cash', 'amount' => 1000]],
        ]);

        $this->assertSame('SALE202501001', $data->saleNumber);
        $this->assertInstanceOf(DateTimeImmutable::class, $data->soldAt);
        $this->assertSame('2026-01-15 10:30:00', $data->soldAt->format('Y-m-d H:i:s'));
        $this->assertSame('Jean Dupont', $data->cashierName);
        $this->assertSame('Marie Martin', $data->patientName);
        $this->assertCount(1, $data->items);
        $this->assertSame(1000.0, $data->totalAmount);
        $this->assertCount(1, $data->payments);
    }

    public function test_it_accepts_datetime_instance(): void
    {
        $now = new DateTimeImmutable('2026-06-15 08:00:00');
        $data = ReceiptData::fromArray([
            'sale_number' => 'X',
            'sold_at' => $now,
            'items' => [],
            'subtotal' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'payments' => [],
        ]);

        $this->assertSame($now, $data->soldAt);
    }

    public function test_it_applies_safe_defaults_for_missing_fields(): void
    {
        $data = ReceiptData::fromArray(['sale_number' => 'NO-DETAILS']);

        $this->assertSame('NO-DETAILS', $data->saleNumber);
        $this->assertNull($data->soldAt);
        $this->assertNull($data->cashierName);
        $this->assertSame([], $data->items);
        $this->assertSame([], $data->payments);
        $this->assertSame(0.0, $data->subtotal);
        $this->assertSame(0.0, $data->totalAmount);
        $this->assertSame([], $data->taxesGrouped);
    }

    public function test_it_casts_numeric_strings_to_floats(): void
    {
        $data = ReceiptData::fromArray([
            'sale_number' => 'CAST',
            'subtotal' => '1500',
            'total_amount' => '1500',
        ]);

        $this->assertSame(1500.0, $data->subtotal);
        $this->assertSame(1500.0, $data->totalAmount);
    }
}
