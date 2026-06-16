<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Support;

/**
 * Configuration pour l'impression de tickets (entreprise, mise en page, devise, messages).
 * Utilisable à partir d'un tableau (config Laravel, BDD, etc.).
 */
final class ReceiptConfig
{
    public function __construct(
        public readonly array $company = [],
        public readonly array $layout = [],
        public readonly array $currency = [],
        public readonly array $messages = [],
        public readonly array $qrCode = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            company: $data['company'] ?? [],
            layout: $data['layout'] ?? [],
            currency: $data['currency'] ?? [],
            messages: $data['messages'] ?? [],
            qrCode: $data['qr_code'] ?? [],
        );
    }

    public function getCompanyName(): string
    {
        return $this->company['name'] ?? 'MEDSOFT';
    }

    public function getCompanySubtitle(): string
    {
        return $this->company['subtitle'] ?? '';
    }

    public function getSeparator(): string
    {
        $char = $this->layout['separator_char'] ?? '-';
        $length = (int) ($this->layout['separator_length'] ?? 32);

        return str_repeat($char, $length);
    }

    public function formatCurrency(float|int $amount): string
    {
        $symbol = $this->currency['symbol'] ?? 'FCFA';
        $decimals = (int) ($this->currency['decimals'] ?? 0);
        $thousands = $this->currency['thousands_separator'] ?? ' ';
        $decimal = $this->currency['decimal_separator'] ?? ',';
        $position = $this->currency['position'] ?? 'after';

        $formatted = number_format((float) $amount, $decimals, $decimal, $thousands);

        return $position === 'before' ? $symbol.' '.$formatted : $formatted.' '.$symbol;
    }

    public function getThankYouMessage(): string
    {
        return $this->messages['thank_you'] ?? 'Merci pour votre visite !';
    }

    public function getKeepReceiptMessage(): string
    {
        return $this->messages['keep_receipt'] ?? 'Conservez ce ticket';
    }

    public function getHeaderSize(): int
    {
        return (int) ($this->layout['header_size'] ?? 2);
    }

    public function getTotalSize(): int
    {
        return (int) ($this->layout['total_size'] ?? 2);
    }

    public function getItemNameSize(): int
    {
        return (int) ($this->layout['item_name_size'] ?? 1);
    }

    public function isQrCodeEnabled(): bool
    {
        return (bool) ($this->qrCode['enabled'] ?? true);
    }

    public function getQrCodeSize(): int
    {
        return (int) ($this->qrCode['size'] ?? 3);
    }
}
