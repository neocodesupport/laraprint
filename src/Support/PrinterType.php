<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Support;

/**
 * Type logique d'imprimante pour choisir la stratégie d'impression.
 */
enum PrinterType: string
{
    /**
     * Imprimante thermique ESC/POS (ou driver raw) : on envoie des octets ESC/POS directement.
     */
    case ThermalEscposRaw = 'thermal_escpos_raw';

    /**
     * Imprimante bureautique Windows : on délègue à Windows via pilote/handlers (PDF/Word/Excel).
     */
    case WindowsSpoolDocument = 'windows_spool_document';

    /**
     * Imprimante bureautique CUPS : on envoie au spouleur CUPS (PDF/images selon pilotes).
     */
    case CupsSpoolDocument = 'cups_spool_document';
}
