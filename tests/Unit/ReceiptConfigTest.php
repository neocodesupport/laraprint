<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Support\ReceiptConfig;
use PHPUnit\Framework\TestCase;

final class ReceiptConfigTest extends TestCase
{
    public function test_it_returns_defaults_when_empty(): void
    {
        $config = ReceiptConfig::fromArray([]);

        $this->assertSame('MEDSOFT', $config->getCompanyName());
        $this->assertSame('', $config->getCompanySubtitle());
        $this->assertSame('Merci pour votre visite !', $config->getThankYouMessage());
        $this->assertSame('Conservez ce ticket', $config->getKeepReceiptMessage());
        $this->assertSame(2, $config->getHeaderSize());
        $this->assertSame(2, $config->getTotalSize());
        $this->assertSame(1, $config->getItemNameSize());
        $this->assertTrue($config->isQrCodeEnabled());
        $this->assertSame(3, $config->getQrCodeSize());
    }

    public function test_it_builds_separator_from_layout(): void
    {
        $config = ReceiptConfig::fromArray([
            'layout' => ['separator_char' => '=', 'separator_length' => 10],
        ]);

        $this->assertSame('==========', $config->getSeparator());
    }

    public function test_it_formats_currency_after_by_default(): void
    {
        $config = ReceiptConfig::fromArray([
            'currency' => [
                'symbol' => 'FCFA',
                'decimals' => 0,
                'thousands_separator' => ' ',
                'decimal_separator' => ',',
                'position' => 'after',
            ],
        ]);

        $this->assertSame('1 000 FCFA', $config->formatCurrency(1000));
    }

    public function test_it_formats_currency_before_with_decimals(): void
    {
        $config = ReceiptConfig::fromArray([
            'currency' => [
                'symbol' => '€',
                'decimals' => 2,
                'thousands_separator' => '.',
                'decimal_separator' => ',',
                'position' => 'before',
            ],
        ]);

        $this->assertSame('€ 1.234,50', $config->formatCurrency(1234.5));
    }

    public function test_custom_company_and_messages(): void
    {
        $config = ReceiptConfig::fromArray([
            'company' => ['name' => 'Acme', 'subtitle' => 'Pharmacie'],
            'messages' => ['thank_you' => 'Merci', 'keep_receipt' => 'Gardez-le'],
            'qr_code' => ['enabled' => false, 'size' => 6],
        ]);

        $this->assertSame('Acme', $config->getCompanyName());
        $this->assertSame('Pharmacie', $config->getCompanySubtitle());
        $this->assertSame('Merci', $config->getThankYouMessage());
        $this->assertSame('Gardez-le', $config->getKeepReceiptMessage());
        $this->assertFalse($config->isQrCodeEnabled());
        $this->assertSame(6, $config->getQrCodeSize());
    }
}
