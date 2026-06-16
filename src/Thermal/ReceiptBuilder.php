<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Thermal;

use Mike42\Escpos\EscposImage;
use Mike42\Escpos\Printer as EscposPrinter;
use Neocode\Laraprint\DirectPrinter;

/**
 * Builder fluide pour composer un ticket sans descendre à l'API ESC/POS brute.
 *
 * Exemple :
 *   Laraprint::build($config)
 *       ->center()->bold()->size(2, 2)->line('MA BOUTIQUE')->bold(false)->size(1, 1)
 *       ->rule()->left()->line('Article A      1 000')->rule()
 *       ->qr('https://exemple.com/ticket/42')->feed(2)->cut()->print();
 */
final class ReceiptBuilder
{
    private DirectPrinter $printer;

    private EscposPrinter $escpos;

    /**
     * @param  array<string, mixed>  $connectionConfig
     */
    public function __construct(array $connectionConfig)
    {
        $this->printer = DirectPrinter::forPrinter($connectionConfig);
        $this->escpos = $this->printer->getEscposPrinter();
    }

    /**
     * @param  array<string, mixed>  $connectionConfig
     */
    public static function make(array $connectionConfig): self
    {
        return new self($connectionConfig);
    }

    public function left(): self
    {
        $this->escpos->setJustification(EscposPrinter::JUSTIFY_LEFT);

        return $this;
    }

    public function center(): self
    {
        $this->escpos->setJustification(EscposPrinter::JUSTIFY_CENTER);

        return $this;
    }

    public function right(): self
    {
        $this->escpos->setJustification(EscposPrinter::JUSTIFY_RIGHT);

        return $this;
    }

    public function bold(bool $on = true): self
    {
        $this->escpos->setEmphasis($on);

        return $this;
    }

    public function underline(bool $on = true): self
    {
        $this->escpos->setUnderline($on ? 1 : 0);

        return $this;
    }

    public function size(int $width = 1, int $height = 1): self
    {
        $this->escpos->setTextSize($width, $height);

        return $this;
    }

    public function text(string $text): self
    {
        $this->escpos->text($text);

        return $this;
    }

    public function line(string $text = ''): self
    {
        $this->escpos->text($text."\n");

        return $this;
    }

    public function feed(int $lines = 1): self
    {
        $this->escpos->feed($lines);

        return $this;
    }

    public function rule(string $char = '-', int $length = 32): self
    {
        $this->escpos->text(str_repeat($char, $length)."\n");

        return $this;
    }

    public function barcode(string $data, int $type = EscposPrinter::BARCODE_CODE39): self
    {
        $this->escpos->barcode($data, $type);

        return $this;
    }

    public function qr(string $data, int $size = 4): self
    {
        $this->escpos->qrCode($data, EscposPrinter::QR_ECLEVEL_L, $size);

        return $this;
    }

    public function image(string $path): self
    {
        $this->escpos->graphics(EscposImage::load($path));

        return $this;
    }

    public function drawer(int $pin = 0): self
    {
        $this->escpos->pulse($pin);

        return $this;
    }

    public function cut(): self
    {
        $this->escpos->cut();

        return $this;
    }

    /**
     * Envoie le ticket et ferme la connexion.
     */
    public function print(): void
    {
        $this->printer->close();
    }

    /**
     * Accès à l'instance ESC/POS sous-jacente pour les besoins avancés.
     */
    public function escpos(): EscposPrinter
    {
        return $this->escpos;
    }
}
