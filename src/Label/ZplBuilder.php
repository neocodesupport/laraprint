<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Label;

use Neocode\Laraprint\Laraprint;

/**
 * Générateur d'étiquettes **ZPL** (Zebra Programming Language).
 *
 * Compose une étiquette puis l'envoie en **octets bruts** (sans init ESC/POS) vers une
 * imprimante Zebra (réseau 9100 ou périphérique). Distinct de l'ESC/POS thermique.
 *
 * Exemple :
 *   ZplBuilder::make()
 *       ->text(50, 50, 'Article A', size: 40)
 *       ->barcode(50, 120, '123456789')
 *       ->qr(400, 50, 'https://exemple.com')
 *       ->box(20, 20, 760, 380, 3)
 *       ->print($config);
 */
final class ZplBuilder
{
    /** @var list<string> */
    private array $fields = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * Champ texte. $font = police ZPL (0 = défaut scalable).
     */
    public function text(int $x, int $y, string $content, string $font = '0', int $size = 30): self
    {
        $this->fields[] = sprintf('^FO%d,%d^A%sN,%d,%d^FD%s^FS', $x, $y, $font, $size, $size, $this->escape($content));

        return $this;
    }

    /**
     * Code-barres Code 128 (^BC).
     */
    public function barcode(int $x, int $y, string $data, int $height = 100, bool $printInterpretation = true): self
    {
        $this->fields[] = sprintf(
            '^FO%d,%d^BCN,%d,%s,N,N^FD%s^FS',
            $x,
            $y,
            $height,
            $printInterpretation ? 'Y' : 'N',
            $this->escape($data),
        );

        return $this;
    }

    /**
     * QR code (^BQ).
     */
    public function qr(int $x, int $y, string $data, int $magnification = 5): self
    {
        $this->fields[] = sprintf('^FO%d,%d^BQN,2,%d^FDLA,%s^FS', $x, $y, $magnification, $this->escape($data));

        return $this;
    }

    /**
     * Cadre / rectangle (^GB).
     */
    public function box(int $x, int $y, int $width, int $height, int $thickness = 2): self
    {
        $this->fields[] = sprintf('^FO%d,%d^GB%d,%d,%d^FS', $x, $y, $width, $height, $thickness);

        return $this;
    }

    /**
     * Insère du ZPL brut (commandes non couvertes par le builder).
     */
    public function raw(string $zpl): self
    {
        $this->fields[] = $zpl;

        return $this;
    }

    /**
     * Rend l'étiquette complète (^XA … ^XZ).
     */
    public function toZpl(): string
    {
        return "^XA\n".implode("\n", $this->fields)."\n^XZ\n";
    }

    /**
     * Envoie l'étiquette en octets bruts vers l'imprimante.
     *
     * @param  array<string, mixed>  $connectionConfig
     */
    public function print(array $connectionConfig): void
    {
        Laraprint::sendRaw($connectionConfig, $this->toZpl());
    }

    private function escape(string $value): string
    {
        // ^ et ~ sont les caractères de contrôle ZPL : on les retire des données.
        return str_replace(['^', '~'], ['', ''], $value);
    }
}
