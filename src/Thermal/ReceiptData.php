<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Thermal;

/**
 * Données d'un ticket pour l'impression thermique.
 * Structure générique : l'application fournit un tableau conforme.
 *
 * @phpstan-type ReceiptItem array{
 *     item_name: string,
 *     item_code?: string,
 *     item_description?: string,
 *     quantity: int|float,
 *     unit_price: int|float,
 *     discount_amount?: int|float,
 *     tax_amount?: int|float,
 *     tax_percentage?: int|float,
 *     total_amount: int|float,
 *     metadata?: array
 * }
 * @phpstan-type ReceiptPayment array{
 *     type: string,
 *     type_label?: string,
 *     amount: int|float,
 *     reference?: string,
 *     cash_received?: int|float,
 *     change_amount?: int|float
 * }
 * @phpstan-type ReceiptDataArray array{
 *     sale_number: string,
 *     sold_at?: \DateTimeInterface|string,
 *     cashier_name?: string,
 *     cash_register_name?: string,
 *     patient_name?: string,
 *     patient_phone?: string,
 *     patient_code?: string,
 *     items: list<ReceiptItem>,
 *     subtotal: int|float,
 *     discount_amount: int|float,
 *     tax_amount: int|float,
 *     total_amount: int|float,
 *     payments: list<ReceiptPayment>,
 *     taxes_grouped?: list<array{name: string, code?: string, amount: int|float, rate?: int|float, type?: string}>
 * }
 */
final class ReceiptData
{
    /**
     * @param  array<int, array{item_name: string, item_code?: string, item_description?: string, quantity: int|float, unit_price: int|float, discount_amount?: int|float, tax_amount?: int|float, tax_percentage?: int|float, total_amount: int|float, metadata?: array}>  $items
     * @param  array<int, array{type: string, type_label?: string, amount: int|float, reference?: string, cash_received?: int|float, change_amount?: int|float}>  $payments
     */
    public function __construct(
        public readonly string $saleNumber,
        public readonly array $items,
        public readonly float $subtotal,
        public readonly float $discountAmount,
        public readonly float $taxAmount,
        public readonly float $totalAmount,
        public readonly array $payments,
        public readonly ?\DateTimeInterface $soldAt = null,
        public readonly ?string $cashierName = null,
        public readonly ?string $cashRegisterName = null,
        public readonly ?string $patientName = null,
        public readonly ?string $patientPhone = null,
        public readonly ?string $patientCode = null,
        /** @var list<array{name: string, code?: string, amount: float, rate?: float, type?: string}> */
        public readonly array $taxesGrouped = [],
    ) {}

    /**
     * @param  ReceiptDataArray  $data
     */
    public static function fromArray(array $data): self
    {
        $soldAt = null;
        if (isset($data['sold_at'])) {
            $v = $data['sold_at'];
            $soldAt = $v instanceof \DateTimeInterface ? $v : new \DateTimeImmutable((string) $v);
        }

        return new self(
            saleNumber: (string) ($data['sale_number'] ?? ''),
            items: $data['items'] ?? [],
            subtotal: (float) ($data['subtotal'] ?? 0),
            discountAmount: (float) ($data['discount_amount'] ?? 0),
            taxAmount: (float) ($data['tax_amount'] ?? 0),
            totalAmount: (float) ($data['total_amount'] ?? 0),
            payments: $data['payments'] ?? [],
            soldAt: $soldAt,
            cashierName: isset($data['cashier_name']) ? (string) $data['cashier_name'] : null,
            cashRegisterName: isset($data['cash_register_name']) ? (string) $data['cash_register_name'] : null,
            patientName: isset($data['patient_name']) ? (string) $data['patient_name'] : null,
            patientPhone: isset($data['patient_phone']) ? (string) $data['patient_phone'] : null,
            patientCode: isset($data['patient_code']) ? (string) $data['patient_code'] : null,
            taxesGrouped: $data['taxes_grouped'] ?? [],
        );
    }
}
