<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Support;

/**
 * Types de connexion supportés par le SDK (canal physique vers l'imprimante).
 *
 * Source de vérité pour la liste des `connection_type` : validation, inférence de
 * stratégie ({@see PrinterType}) et documentation.
 */
enum ConnectionType: string
{
    case Network = 'network';
    case Windows = 'windows';
    case Cups = 'cups';
    case Smb = 'smb';
    case Usb = 'usb';
    case File = 'file';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * Vrai si l'impression de fichiers passe par le spouleur OS (et non en ESC/POS brut).
     */
    public function usesSpooler(): bool
    {
        return match ($this) {
            self::Windows, self::Cups, self::Smb => true,
            default => false,
        };
    }

    /**
     * Stratégie d'impression de fichier par défaut pour ce type de connexion.
     */
    public function defaultPrinterType(): PrinterType
    {
        return match ($this) {
            self::Windows => PrinterType::WindowsSpoolDocument,
            self::Cups, self::Smb => PrinterType::CupsSpoolDocument,
            default => PrinterType::ThermalEscposRaw,
        };
    }

    /**
     * Stratégie par défaut déduite d'une chaîne `connection_type` (repli ESC/POS brut).
     */
    public static function inferPrinterType(string $connectionType): PrinterType
    {
        return self::tryFrom($connectionType)?->defaultPrinterType() ?? PrinterType::ThermalEscposRaw;
    }
}
