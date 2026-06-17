<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Events;

use Neocode\Laraprint\Models\Printer;

/**
 * Émis lorsqu'une imprimante nouvellement découverte est enregistrée lors d'un import
 * (système, USB, réseau ou mDNS).
 */
final class PrinterDiscovered
{
    /**
     * @param  Printer  $printer  L'imprimante enregistrée.
     * @param  string  $source  Origine de la découverte : "system", "usb", "network", "mdns".
     */
    public function __construct(
        public readonly Printer $printer,
        public readonly string $source = 'discovery',
    ) {}
}
